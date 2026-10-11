#!/usr/bin/env bash
# Restore an encrypted backup created by backup.sh.
# Usage: bash deploy/scripts/restore.sh backups/growth-planet-YYYYMMDDThhmmssZ.tar.enc
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

COMPOSE=(docker compose -f compose.prod.yaml --env-file deploy/compose/.env.prod)
ENV_FILE=deploy/compose/.env.prod

if [[ $# -lt 1 ]]; then
  echo "Usage: $0 <backup-file.tar.enc|backup-file.tar.age>" >&2
  exit 1
fi

BACKUP_FILE="$1"
if [[ ! -f "$BACKUP_FILE" ]]; then
  echo "Backup not found: $BACKUP_FILE" >&2
  exit 1
fi

echo "WARNING: This will OVERWRITE the current database and storage/app contents."
read -r -p "Type RESTORE to continue: " CONFIRM
if [[ "$CONFIRM" != "RESTORE" ]]; then
  echo "Aborted."
  exit 1
fi

MYSQL_DATABASE="$(grep -E '^MYSQL_DATABASE=' "$ENV_FILE" | head -1 | cut -d= -f2-)"
MYSQL_USER="$(grep -E '^MYSQL_USER=' "$ENV_FILE" | head -1 | cut -d= -f2-)"
MYSQL_PASSWORD="$(grep -E '^MYSQL_PASSWORD=' "$ENV_FILE" | head -1 | cut -d= -f2-)"
BACKUP_ENCRYPTION_PASSPHRASE="$(grep -E '^BACKUP_ENCRYPTION_PASSPHRASE=' "$ENV_FILE" | head -1 | cut -d= -f2-)"
MYSQL_DATABASE="${MYSQL_DATABASE:-growth_planet}"
MYSQL_USER="${MYSQL_USER:-growth_planet}"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

ARCHIVE="$WORK/restore.tar"
case "$BACKUP_FILE" in
  *.age)
    age -d -o "$ARCHIVE" "$BACKUP_FILE"
    ;;
  *.enc)
    openssl enc -d -aes-256-cbc -pbkdf2 \
      -pass "pass:${BACKUP_ENCRYPTION_PASSPHRASE}" \
      -in "$BACKUP_FILE" -out "$ARCHIVE"
    ;;
  *)
    echo "Unknown backup extension (expected .enc or .age)" >&2
    exit 1
    ;;
esac

tar -C "$WORK" -xf "$ARCHIVE"

echo "Restoring database..."
"${COMPOSE[@]}" exec -T mysql \
  mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < "$WORK/db.sql"

if [[ -d "$WORK/storage" ]]; then
  echo "Restoring storage/app..."
  "${COMPOSE[@]}" cp "$WORK/storage/." app:/var/www/backend/storage/app/
fi

echo "Restore finished. Run: make prod-preflight"
