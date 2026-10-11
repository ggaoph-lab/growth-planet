#!/usr/bin/env bash
# Creates local .env files with freshly generated secrets. Safe to re-run: existing files are kept.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

rand() { openssl rand -base64 32 | tr -dc 'A-Za-z0-9' | head -c 32; }

if [[ ! -f .env ]]; then
  sed -e "s/^MYSQL_PASSWORD=.*/MYSQL_PASSWORD=$(rand)/" \
      -e "s/^MYSQL_ROOT_PASSWORD=.*/MYSQL_ROOT_PASSWORD=$(rand)/" \
      .env.example > .env
  chmod 600 .env
  echo "created .env with generated database passwords"
else
  echo ".env exists, kept"
fi

if [[ ! -f backend/.env ]]; then
  cp backend/.env.example backend/.env
  chmod 600 backend/.env
  echo "created backend/.env (run 'make key' to generate APP_KEY)"
else
  echo "backend/.env exists, kept"
fi

if [[ ! -f frontend/dist/index.html ]]; then
  mkdir -p frontend/dist
  cat > frontend/dist/index.html <<'HTML'
<!doctype html><meta charset="utf-8"><title>成长星球</title><p>前端尚未构建，请运行 make frontend-build</p>
HTML
fi
