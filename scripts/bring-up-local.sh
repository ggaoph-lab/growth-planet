#!/bin/zsh
set -euo pipefail
cd /Users/shane/Projects/growth-planet

log() { print -P "%F{cyan}==>%f $1"; }
fail() { print -P "%F{red}FAIL%f $1"; exit 1; }

log "Waiting for Docker daemon..."
for i in {1..90}; do
  if docker info >/dev/null 2>&1; then
    log "Docker ready (${i}s)"
    break
  fi
  if [[ $i -eq 90 ]]; then
    fail "Docker daemon did not become ready"
  fi
  sleep 2
done

if [[ ! -f .env ]]; then
  log "Creating .env via make init"
  make init
fi

log "docker compose up -d"
make up

log "migrate"
make migrate

log "poetry feature tests"
docker compose exec -T app php artisan test --filter=PoetryPlanetTest

log "frontend preview build + nginx on :8088"
make preview

log "health check"
curl -fsS http://localhost:8088/api/health | tee /tmp/gp-health.json
print ""
log "DONE — open http://localhost:8088"
exec /bin/zsh -l
