#!/usr/bin/env bash
set -euo pipefail

cd /var/www/backend

mkdir -p \
  resources/views \
  storage/app/public \
  storage/app/private \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

if [[ -z "${APP_KEY:-}" ]]; then
  echo "ERROR: APP_KEY is empty. Set it in deploy/compose/.env.prod before starting." >&2
  exit 1
fi

# Wait for MySQL when DB_HOST is set (compose network).
if [[ -n "${DB_HOST:-}" && "${SKIP_DB_WAIT:-0}" != "1" ]]; then
  echo "Waiting for MySQL at ${DB_HOST}:${DB_PORT:-3306}..."
  for i in $(seq 1 60); do
    if php -r '
      try {
        $h = getenv("DB_HOST");
        $p = getenv("DB_PORT") ?: "3306";
        $d = getenv("DB_DATABASE");
        $u = getenv("DB_USERNAME");
        $w = getenv("DB_PASSWORD");
        new PDO("mysql:host=$h;port=$p;dbname=$d", $u, $w, [PDO::ATTR_TIMEOUT => 3]);
        exit(0);
      } catch (Throwable $e) { exit(1); }
    '; then
      echo "MySQL is ready."
      break
    fi
    if [[ "$i" -eq 60 ]]; then
      echo "ERROR: MySQL did not become ready in time." >&2
      exit 1
    fi
    sleep 2
  done
fi

# Cache config/routes/views for production (safe to re-run).
if [[ "${APP_ENV:-production}" == "production" && "${SKIP_CONFIG_CACHE:-0}" != "1" ]]; then
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
fi

exec "$@"
