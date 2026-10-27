#!/bin/zsh
set -euo pipefail
cd /Users/shane/Projects/growth-planet

print -P "%F{cyan}==>%f Waiting for Docker daemon..."
for i in {1..90}; do
  if docker info >/dev/null 2>&1; then
    print -P "%F{cyan}==>%f Docker ready (${i}s)"
    break
  fi
  if [[ $i -eq 90 ]]; then
    print -P "%F{red}FAIL%f Docker daemon did not become ready"
    read -k 1 "?Press any key..."
    exit 1
  fi
  sleep 2
done

[[ -f .env ]] || make init

print -P "%F{cyan}==>%f make up"
make up

print -P "%F{cyan}==>%f make migrate"
make migrate

print -P "%F{cyan}==>%f PoetryPlanetTest"
docker compose exec -T app php artisan test --filter=PoetryPlanetTest || true

print -P "%F{cyan}==>%f make preview (http://localhost:8088)"
make preview

print -P "%F{cyan}==>%f health"
curl -fsS http://localhost:8088/api/health || curl -fsS http://127.0.0.1:8088/api/health || true
print ""
print -P "%F{green}DONE%f open http://localhost:8088"
exec /bin/zsh -l
