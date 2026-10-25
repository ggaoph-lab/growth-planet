#!/usr/bin/env bash
# Creates local .env files with freshly generated secrets. Safe to re-run: existing files are kept.
# Always syncs backend/.env DB_PASSWORD from the root .env (never leaves it blank/drifting).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

rand() { openssl rand -base64 32 | tr -dc 'A-Za-z0-9' | head -c 32; }

get_env() {
  local file="$1" key="$2"
  grep -E "^${key}=" "$file" | head -1 | cut -d= -f2-
}

sync_backend_db_password() {
  local pw
  pw="$(get_env .env MYSQL_PASSWORD)"
  if [[ -z "$pw" ]]; then
    echo "WARNING: MYSQL_PASSWORD empty in .env; skip backend/.env sync" >&2
    return 0
  fi
  if [[ ! -f backend/.env ]]; then
    return 0
  fi
  if grep -qE '^DB_PASSWORD=' backend/.env; then
    sed -i.bak -E "s|^DB_PASSWORD=.*|DB_PASSWORD=${pw}|" backend/.env
    rm -f backend/.env.bak
  else
    printf '\nDB_PASSWORD=%s\n' "$pw" >>backend/.env
  fi
  chmod 600 backend/.env 2>/dev/null || true
  echo "synced backend/.env DB_PASSWORD from .env"
}

if [[ ! -f .env ]]; then
  sed -e "s/^MYSQL_PASSWORD=.*/MYSQL_PASSWORD=$(rand)/" \
      -e "s/^MYSQL_ROOT_PASSWORD=.*/MYSQL_ROOT_PASSWORD=$(rand)/" \
      .env.example > .env
  chmod 600 .env
  echo "created .env with generated database passwords"
else
  echo ".env exists, kept (passwords are not regenerated)"
fi

if [[ ! -f backend/.env ]]; then
  cp backend/.env.example backend/.env
  chmod 600 backend/.env
  echo "created backend/.env (run 'make key' to generate APP_KEY)"
else
  echo "backend/.env exists, kept"
fi

sync_backend_db_password

if [[ ! -f frontend/dist/index.html ]]; then
  mkdir -p frontend/dist
  cat > frontend/dist/index.html <<'HTML'
<!doctype html><meta charset="utf-8"><title>成长星球</title><p>前端尚未构建，请运行 make frontend-build</p>
HTML
fi
