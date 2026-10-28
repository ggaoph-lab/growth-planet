#!/usr/bin/env bash
# Keep Atlas media outside code images. Migrate it from the old running nginx
# locally on first upgrade; download only for a missing or changed media revision.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="$ROOT/apps/word-atlas/public/atlas"
MEDIA_REF="${WORD_ATLAS_MEDIA_REF:-$(cat "$ROOT/apps/word-atlas/MEDIA_REVISION")}"
MARKER="$DEST/.media-revision"
CONTAINER="${1:-growth-planet-nginx-1}"

ready() {
  local dir
  for dir in img audio thumb; do
    [[ -d "$DEST/$dir" && -n "$(ls -A "$DEST/$dir")" ]] || return 1
  done
}

if ready; then
  if [[ ! -f "$MARKER" || "$(cat "$MARKER")" == "$MEDIA_REF" ]]; then
    printf '%s\n' "$MEDIA_REF" > "$MARKER"
    echo "Atlas media unchanged ($MEDIA_REF); no download."
    exit 0
  fi
  echo "Atlas media revision changed; synchronizing the requested revision."
  exec bash "$ROOT/scripts/sync-word-atlas-assets.sh" --force
fi

# A changed revision must not be relabeled by copying old container contents.
if [[ ! -f "$MARKER" || "$(cat "$MARKER")" == "$MEDIA_REF" ]] && \
  command -v docker >/dev/null && docker inspect "$CONTAINER" >/dev/null 2>&1; then
  mkdir -p "$DEST"
  scratch="$(mktemp -d "$DEST/.media-migrate-XXXXXX")"
  trap 'rm -rf "$scratch"' EXIT
  for dir in img audio thumb; do
    if [[ -d "$DEST/$dir" && -n "$(ls -A "$DEST/$dir")" ]]; then continue; fi
    if docker cp "$CONTAINER:/var/www/frontend/dist/ielts/atlas/$dir" "$scratch/$dir" && \
      [[ -n "$(ls -A "$scratch/$dir")" ]]; then
      # Docker bind mounts can leave an empty host directory on an interrupted setup.
      if [[ -d "$DEST/$dir" ]]; then rmdir "$DEST/$dir"; fi
      mv "$scratch/$dir" "$DEST/$dir"
      echo "Preserved Atlas $dir from existing container (local disk copy)."
    fi
  done
  if ready; then
    printf '%s\n' "$MEDIA_REF" > "$MARKER"
    echo "Atlas media migration complete; no network download."
    exit 0
  fi
fi

echo "Atlas media is missing; synchronizing it for this installation."
bash "$ROOT/scripts/sync-word-atlas-assets.sh"
