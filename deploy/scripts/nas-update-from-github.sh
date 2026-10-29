#!/usr/bin/env bash
# Update Growth Planet on Synology (or any Docker host) from GitHub + GHCR.
#
# Default path (fast): sync compose/scripts → docker compose pull → up → migrate.
# LOCAL_BUILD=1 builds images on the deployment host.
#
# Safe to run from /volume1/docker/growth-planet (SSH or Task Scheduler).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

REPO_URL="${REPO_URL:-https://github.com/ggaoph-lab/growth-planet}"
BRANCH="${BRANCH:-main}"
COMPOSE_FILE="${COMPOSE_FILE:-compose.yaml}"
ENV_FILE="${ENV_FILE:-deploy/compose/.env.prod}"
# Set LOCAL_BUILD=1 to build on the host instead of pulling GHCR images.
LOCAL_BUILD="${LOCAL_BUILD:-0}"
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
  --exclude 'frontend/dist/' \
  --exclude 'apps/word-atlas/dist/' \
  --exclude 'apps/word-atlas/public/atlas/img/' \
  --exclude 'apps/word-atlas/public/atlas/audio/' \
  --exclude 'apps/word-atlas/public/atlas/thumb/' \
  --exclude 'apps/word-atlas/public/atlas/.media-revision' \
  --exclude 'apps/word-atlas/public/atlas/.media-migrate-*/' \
  --exclude 'backend/vendor/' \
  --exclude '.release-*/' \
  "$work/src/" "$ROOT/"

for rel in "${KEEP_PATHS[@]}"; do
  if [[ -e "$work/keep/$rel" ]]; then
    mkdir -p "$(dirname "$ROOT/$rel")"
    rm -rf "$ROOT/$rel"
    cp -a "$work/keep/$rel" "$ROOT/$rel"
  fi
done

# Synology Container Manager expects production compose under compose.yaml / docker-compose.yml.
# Always refresh from compose.prod.yaml so a GitHub sync cannot leave the repo's dev compose.yaml in place.
if [[ -f "$ROOT/compose.prod.yaml" ]]; then
  cp -a "$ROOT/compose.prod.yaml" "$ROOT/compose.yaml"
  cp -a "$ROOT/compose.prod.yaml" "$ROOT/docker-compose.yml"
  if [[ "$COMPOSE_FILE" != "compose.yaml" && "$COMPOSE_FILE" != "docker-compose.yml" ]]; then
    cp -a "$ROOT/compose.prod.yaml" "$ROOT/$COMPOSE_FILE"
  fi
fi

new_commit="$(curl -fsSL "https://api.github.com/repos/ggaoph-lab/growth-planet/commits/${BRANCH}" \
  | python3 -c 'import sys,json; print(json.load(sys.stdin)["sha"])')"

compose() {
  docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" "$@"
}

if [[ "$DOCKER_OK" -eq 1 ]]; then
  # Must run before recreating nginx: the old image may hold the only local copy.
  media_container="$(compose ps -q nginx 2>/dev/null || true)"
  bash "$ROOT/scripts/prepare-word-atlas-media.sh" "${media_container:-growth-planet-nginx-1}"
  bash "$ROOT/scripts/prepare-word-atlas-phrase-audio.sh"
  if [[ -x "$ROOT/deploy/scripts/nas-backup.sh" || -x "$ROOT/deploy/scripts/backup.sh" ]]; then
    compose run --rm --no-deps backup true 2>/dev/null || true
  fi

  if [[ "$LOCAL_BUILD" == "1" ]]; then
    echo "LOCAL_BUILD=1 — building images on this host (slow) ..."
    if [[ -f "$ROOT/compose.build.yaml" ]]; then
      docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" -f "$ROOT/compose.build.yaml" build app nginx
    else
      compose build app nginx
    fi
  else
    echo "Pulling prebuilt images from registry (PHP_IMAGE / NGINX_IMAGE in $ENV_FILE) ..."
    if ! compose pull app nginx; then
      echo >&2
      echo "ERROR: docker compose pull failed." >&2
      echo "Common fixes:" >&2
      echo "  1) Wait for GitHub Actions 'Docker images' workflow to finish on main." >&2
      echo "  2) Make GHCR packages public, or: docker login ghcr.io -u USER --password-stdin  (PAT: read:packages)" >&2
      echo "  3) Emergency only: LOCAL_BUILD=1 bash deploy/scripts/nas-update-from-github.sh" >&2
      exit 1
    fi
  fi

  # Explicitly bring up worker/scheduler too — Container Manager / partial ups have left them missing.
  compose up -d --remove-orphans app nginx worker scheduler mysql
  # Nginx caches upstream IPs at start; bounce it after app recreate so API is not 502.
  compose restart nginx
  compose exec -T app php artisan migrate --force
  compose exec -T app php artisan points:grant-daily-allowance || true
  compose exec -T app php artisan app:preflight || true
  running="$(compose ps --status running --services 2>/dev/null || true)"
  for svc in app nginx worker scheduler mysql; do
    if ! printf '%s\n' "$running" | grep -qx "$svc"; then
      echo "WARN: service '$svc' is not running after update." >&2
    fi
  done
else
  echo "Code synced to commit $new_commit."
  echo "Next: open Container Manager → growth-planet → Pull / Start (include worker + scheduler)."
fi

http_port="$(grep -E '^HTTP_PORT=' "$ENV_FILE" | cut -d= -f2- || true)"
http_port="${http_port:-8088}"
if [[ "$DOCKER_OK" -eq 1 ]]; then
  curl -fsS -m 8 "http://127.0.0.1:${http_port}/healthz" >/dev/null
  curl -fsS -m 8 "http://127.0.0.1:${http_port}/api/health" >/dev/null
  printf '%s\n' "$new_commit" >"$ROOT/DEPLOYED-COMMIT"
  echo "healthz and api/health ok"
  echo "Deployed ${BRANCH} @ ${new_commit}"
else
  echo "Code updated toward ${BRANCH} @ ${new_commit}; Docker deployment pending."
fi
