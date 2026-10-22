#!/usr/bin/env bash
# Update Growth Planet on Synology from GitHub main.
# Safe to run on the NAS host (or via SSH) from /volume1/docker/growth-planet.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

REPO_URL="${REPO_URL:-https://github.com/ggaoph-lab/growth-planet}"
BRANCH="${BRANCH:-main}"
COMPOSE_FILE="${COMPOSE_FILE:-compose.yaml}"
ENV_FILE="${ENV_FILE:-deploy/compose/.env.prod}"
KEEP_PATHS=(
  "deploy/compose/.env.prod"
  "deploy/compose/.env.oracle.original"
  "deploy/compose/.env.before-file-cache"
  "deploy/certs"
  "backups"
  ".env"
  "DEPLOYMENT-STATUS.md"
  "DEPLOYED-COMMIT"
)

export PATH="/usr/local/bin:/usr/syno/bin:/var/packages/ContainerManager/target/usr/bin:${PATH:-}"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing $ENV_FILE — refuse to update without production env." >&2
  exit 1
fi

if ! docker info >/dev/null 2>&1; then
  echo "Docker unavailable for $(whoami). Grant docker.sock access or rebuild in Container Manager after code sync." >&2
  DOCKER_OK=0
else
  DOCKER_OK=1
fi

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
work="$(mktemp -d "/tmp/gp-update-${stamp}-XXXXXX")"
trap 'rm -rf "$work"' EXIT

echo "Downloading ${REPO_URL}/archive/refs/heads/${BRANCH}.tar.gz ..."
curl -fsSL "${REPO_URL}/archive/refs/heads/${BRANCH}.tar.gz" -o "$work/src.tar.gz"
mkdir -p "$work/src"
tar -xzf "$work/src.tar.gz" -C "$work/src" --strip-components=1

# Preserve secrets / local state
mkdir -p "$work/keep"
for rel in "${KEEP_PATHS[@]}"; do
  if [[ -e "$ROOT/$rel" ]]; then
    mkdir -p "$work/keep/$(dirname "$rel")"
    cp -a "$ROOT/$rel" "$work/keep/$rel"
  fi
done

echo "Syncing code into $ROOT (preserving env/backups/certs) ..."
# Replace tracked tree but do not delete local-only dirs we restore next.
rsync -a --delete \
  --exclude '.git/' \
  --exclude 'backups/' \
  --exclude 'deploy/compose/.env.prod' \
  --exclude 'deploy/compose/.env.*' \
  --exclude 'deploy/certs/' \
  --exclude '.env' \
  --exclude 'DEPLOYMENT-STATUS.md' \
  --exclude 'DEPLOYED-COMMIT' \
  --exclude 'node_modules/' \
  --exclude 'frontend/node_modules/' \
  --exclude 'backend/vendor/' \
  "$work/src/" "$ROOT/"

for rel in "${KEEP_PATHS[@]}"; do
  if [[ -e "$work/keep/$rel" ]]; then
    mkdir -p "$(dirname "$ROOT/$rel")"
    rm -rf "$ROOT/$rel"
    cp -a "$work/keep/$rel" "$ROOT/$rel"
  fi
done

# Prefer Synology compose filename used by Container Manager.
if [[ -f "$ROOT/compose.prod.yaml" && ! -f "$ROOT/$COMPOSE_FILE" ]]; then
  cp -a "$ROOT/compose.prod.yaml" "$ROOT/$COMPOSE_FILE"
fi
if [[ -f "$ROOT/compose.prod.yaml" ]]; then
  cp -a "$ROOT/compose.prod.yaml" "$ROOT/docker-compose.yml"
fi

new_commit="$(curl -fsSL "https://api.github.com/repos/ggaoph-lab/growth-planet/commits/${BRANCH}" \
  | python3 -c 'import sys,json; print(json.load(sys.stdin)["sha"])')"
printf '%s\n' "$new_commit" >"$ROOT/DEPLOYED-COMMIT"

if [[ "$DOCKER_OK" -eq 1 ]]; then
  echo "Building and restarting containers ..."
  if [[ -x "$ROOT/deploy/scripts/nas-backup.sh" || -x "$ROOT/deploy/scripts/backup.sh" ]]; then
    docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" run --rm --no-deps backup \
      true 2>/dev/null || true
  fi
  docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" build app nginx
  docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" up -d --remove-orphans
  docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" exec -T app php artisan migrate --force
  docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" exec -T app php artisan app:preflight || true
else
  echo "Code synced to commit $new_commit."
  echo "Next: open Container Manager → growth-planet → Build / Start."
fi

http_port="$(grep -E '^HTTP_PORT=' "$ENV_FILE" | cut -d= -f2- || true)"
http_port="${http_port:-8088}"
if curl -fsS -m 8 "http://127.0.0.1:${http_port}/healthz" >/dev/null 2>&1; then
  echo "healthz ok"
  curl -fsS -m 8 "http://127.0.0.1:${http_port}/api/health" || true
  echo
fi

echo "Updated toward ${BRANCH} @ ${new_commit}"
