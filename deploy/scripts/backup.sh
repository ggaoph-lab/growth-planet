#!/usr/bin/env bash
# Encrypted MySQL + storage backup for production Compose.
# Requires: deploy/compose/.env.prod with BACKUP_ENCRYPTION_PASSPHRASE
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

COMPOSE=(docker compose -f compose.prod.yaml --env-file deploy/compose/.env.prod)
ENV_FILE=deploy/compose/.env.prod
BACKUP_DIR="${BACKUP_DIR:-$ROOT/backups}"
KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing $ENV_FILE" >&2
  exit 1
fi

# shellcheck disable=SC1090
set -a
# Export only the keys we need without sourcing arbitrary shell.
MYSQL_DATABASE="$(grep -E '^MYSQL_DATABASE=' "$ENV_FILE" | head -1 | cut -d= -f2-)"
MYSQL_USER="$(grep -E '^MYSQL_USER=' "$ENV_FILE" | head -1 | cut -d= -f2-)"
MYSQL_PASSWORD="$(grep -E '^MYSQL_PASSWORD=' "$ENV_FILE" | head -1 | cut -d= -f2-)"
BACKUP_ENCRYPTION_PASSPHRASE="$(grep -E '^BACKUP_ENCRYPTION_PASSPHRASE=' "$ENV_FILE" | head -1 | cut -d= -f2-)"
set +a

MYSQL_DATABASE="${MYSQL_DATABASE:-growth_planet}"
MYSQL_USER="${MYSQL_USER:-growth_planet}"

if [[ -z "${BACKUP_ENCRYPTION_PASSPHRASE:-}" ]]; then
  echo "BACKUP_ENCRYPTION_PASSPHRASE is empty in $ENV_FILE" >&2
  exit 1
fi

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

echo "Dumping MySQL..."
"${COMPOSE[@]}" exec -T mysql \
  mysqldump \
    --single-transaction \
    --no-tablespaces \
    --routines \
    --triggers \
    --hex-blob \
    -u"$MYSQL_USER" \
    -p"$MYSQL_PASSWORD" \
    "$MYSQL_DATABASE" > "$WORK/db.sql"

echo "Copying application storage..."
mkdir -p "$WORK/storage"
if "${COMPOSE[@]}" ps --status running -q app >/dev/null 2>&1; then
  "${COMPOSE[@]}" cp app:/var/www/backend/storage/app/. "$WORK/storage/" || true
fi

ARCHIVE="$WORK/growth-planet-${STAMP}.tar"
tar -C "$WORK" -cf "$ARCHIVE" db.sql storage

OUT="$BACKUP_DIR/growth-planet-${STAMP}.tar.age"
if command -v age >/dev/null 2>&1 && [[ -n "${BACKUP_AGE_RECIPIENT:-}" ]]; then
  age -r "$BACKUP_AGE_RECIPIENT" -o "$OUT" "$ARCHIVE"
else
  OUT="$BACKUP_DIR/growth-planet-${STAMP}.tar.enc"
  # CentOS 7's OpenSSL 1.0 lacks PBKDF2; use the app container's OpenSSL 3.
  export BACKUP_ENCRYPTION_PASSPHRASE
  "${COMPOSE[@]}" exec -T -e BACKUP_ENCRYPTION_PASSPHRASE app \
    openssl enc -aes-256-cbc -pbkdf2 -salt \
      -pass env:BACKUP_ENCRYPTION_PASSPHRASE \
    < "$ARCHIVE" > "$OUT"
fi

chmod 600 "$OUT"
echo "Wrote $OUT"

# Retention
find "$BACKUP_DIR" -type f \( -name 'growth-planet-*.tar.enc' -o -name 'growth-planet-*.tar.age' \) -mtime +"$KEEP_DAYS" -delete 2>/dev/null || true
echo "Backup complete (retention ${KEEP_DAYS} days)."
