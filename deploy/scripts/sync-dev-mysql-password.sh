#!/usr/bin/env bash
# Align the running MySQL users with the passwords in the local root .env.
# Also syncs backend/.env DB_PASSWORD for host-side tools (127.0.0.1:33061).
#
# MySQL Docker only applies MYSQL_* passwords on first volume init. If .env
# drifts (or local/prod stacks previously shared a volume), the app env and
# the data directory disagree — this script repairs that without wiping data.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

ENV_FILE="${ENV_FILE:-.env}"
BACKEND_ENV="${BACKEND_ENV:-backend/.env}"
COMPOSE="${COMPOSE:-docker compose}"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "ERROR: $ENV_FILE missing. Run: make init" >&2
  exit 1
fi

get_env() {
  local file="$1" key="$2"
  grep -E "^${key}=" "$file" | head -1 | cut -d= -f2-
}

MYSQL_USER="$(get_env "$ENV_FILE" MYSQL_USER)"
MYSQL_DATABASE="$(get_env "$ENV_FILE" MYSQL_DATABASE)"
TARGET_PW="$(get_env "$ENV_FILE" MYSQL_PASSWORD)"
TARGET_ROOT_PW="$(get_env "$ENV_FILE" MYSQL_ROOT_PASSWORD)"

MYSQL_USER="${MYSQL_USER:-growth_planet}"
MYSQL_DATABASE="${MYSQL_DATABASE:-growth_planet}"

if [[ -z "$TARGET_PW" || -z "$TARGET_ROOT_PW" ]]; then
  echo "ERROR: MYSQL_PASSWORD / MYSQL_ROOT_PASSWORD empty in $ENV_FILE" >&2
  exit 1
fi

if ! $COMPOSE ps --status running --services 2>/dev/null | grep -qx mysql; then
  echo "ERROR: mysql service is not running. Start it first (make up)." >&2
  exit 1
fi

try_root() {
  local pw="$1"
  $COMPOSE exec -T mysql mysql -uroot -p"$pw" -e "SELECT 1" &>/dev/null
}

CURRENT_ROOT_PW=""
if try_root "$TARGET_ROOT_PW"; then
  CURRENT_ROOT_PW="$TARGET_ROOT_PW"
elif [[ -f deploy/compose/.env.prod ]]; then
  PROD_ROOT_PW="$(get_env deploy/compose/.env.prod MYSQL_ROOT_PASSWORD || true)"
  if [[ -n "${PROD_ROOT_PW:-}" ]] && try_root "$PROD_ROOT_PW"; then
    echo "note: MySQL still had deploy/compose/.env.prod root password; updating to $ENV_FILE"
    CURRENT_ROOT_PW="$PROD_ROOT_PW"
  fi
fi

if [[ -z "$CURRENT_ROOT_PW" ]]; then
  echo "ERROR: cannot authenticate as MySQL root with $ENV_FILE or .env.prod." >&2
  echo "If the data volume is disposable: docker compose down -v && make up" >&2
  exit 1
fi

# Escape single quotes for SQL string literals: ' -> ''
sql_quote() {
  printf "%s" "$1" | sed "s/'/''/g"
}

ROOT_SQL="$(sql_quote "$TARGET_ROOT_PW")"
USER_SQL="$(sql_quote "$TARGET_PW")"
USER_NAME_SQL="$(sql_quote "$MYSQL_USER")"

$COMPOSE exec -T mysql mysql -uroot -p"$CURRENT_ROOT_PW" <<SQL
ALTER USER 'root'@'localhost' IDENTIFIED BY '${ROOT_SQL}';
ALTER USER 'root'@'%' IDENTIFIED BY '${ROOT_SQL}';
CREATE USER IF NOT EXISTS '${USER_NAME_SQL}'@'%' IDENTIFIED BY '${USER_SQL}';
ALTER USER '${USER_NAME_SQL}'@'%' IDENTIFIED BY '${USER_SQL}';
CREATE DATABASE IF NOT EXISTS \`${MYSQL_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE DATABASE IF NOT EXISTS \`${MYSQL_DATABASE}_test\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
GRANT ALL PRIVILEGES ON \`${MYSQL_DATABASE}\`.* TO '${USER_NAME_SQL}'@'%';
GRANT ALL PRIVILEGES ON \`${MYSQL_DATABASE}_test\`.* TO '${USER_NAME_SQL}'@'%';
FLUSH PRIVILEGES;
SQL

# Refresh container env so healthchecks / newly started app pick up .env values.
$COMPOSE up -d mysql

# Keep backend/.env in sync for host tools (php on host, GUI clients on :33061).
if [[ -f "$BACKEND_ENV" ]]; then
  if grep -qE '^DB_PASSWORD=' "$BACKEND_ENV"; then
    sed -i.bak -E "s|^DB_PASSWORD=.*|DB_PASSWORD=${TARGET_PW}|" "$BACKEND_ENV"
    rm -f "${BACKEND_ENV}.bak"
  else
    printf '\nDB_PASSWORD=%s\n' "$TARGET_PW" >>"$BACKEND_ENV"
  fi
  chmod 600 "$BACKEND_ENV" 2>/dev/null || true
  echo "synced DB_PASSWORD into $BACKEND_ENV"
fi

# Recreate app so its injected DB_PASSWORD matches (compose reads .env at recreate).
if $COMPOSE ps --status running --services 2>/dev/null | grep -qx app; then
  $COMPOSE up -d --force-recreate app
fi

echo "MySQL passwords now match $ENV_FILE (user=${MYSQL_USER}, db=${MYSQL_DATABASE} + _test)."
