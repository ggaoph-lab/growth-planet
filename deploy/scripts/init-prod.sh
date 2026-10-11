#!/usr/bin/env bash
# Create deploy/compose/.env.prod with generated secrets (does not overwrite an existing file).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

TARGET=deploy/compose/.env.prod
EXAMPLE=deploy/compose/.env.prod.example

rand() { openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c 32; }
app_key() { echo "base64:$(openssl rand -base64 32 | tr -d '\n')"; }

if [[ ! -f "$EXAMPLE" ]]; then
  echo "Missing $EXAMPLE" >&2
  exit 1
fi

mkdir -p deploy/compose deploy/certs backups
chmod 700 backups deploy/compose || true

if [[ -f "$TARGET" ]]; then
  echo "$TARGET already exists; left unchanged"
  exit 0
fi

APP_KEY_VALUE="$(app_key)"
MYSQL_PASSWORD_VALUE="$(rand)"
MYSQL_ROOT_PASSWORD_VALUE="$(rand)"
BACKUP_PASS_VALUE="$(rand)"

sed \
  -e "s|^APP_KEY=.*|APP_KEY=${APP_KEY_VALUE}|" \
  -e "s|^MYSQL_PASSWORD=.*|MYSQL_PASSWORD=${MYSQL_PASSWORD_VALUE}|" \
  -e "s|^MYSQL_ROOT_PASSWORD=.*|MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD_VALUE}|" \
  -e "s|^BACKUP_ENCRYPTION_PASSPHRASE=.*|BACKUP_ENCRYPTION_PASSPHRASE=${BACKUP_PASS_VALUE}|" \
  "$EXAMPLE" > "$TARGET"

chmod 600 "$TARGET"
echo "Created $TARGET"
echo "Next: edit APP_URL and SANCTUM_STATEFUL_DOMAINS to match your domain or public IP."
echo "Keep APP_KEY and BACKUP_ENCRYPTION_PASSPHRASE offline — losing APP_KEY makes birth_date undecryptable."
