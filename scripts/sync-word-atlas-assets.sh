#!/usr/bin/env bash
# Fetch Word Atlas illustration/audio media into apps/word-atlas/public/atlas/.
# Source code and catalog JSON are vendored; ~700MB of img/audio/thumb stay out of git.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="$ROOT/apps/word-atlas/public/atlas"
REPO_URL="${WORD_ATLAS_REPO:-https://github.com/designfuchen/word-atlas.git}"

need_sync=0
for dir in img audio thumb; do
  if [[ ! -d "$DEST/$dir" ]] || [[ -z "$(ls -A "$DEST/$dir" 2>/dev/null || true)" ]]; then
    need_sync=1
    break
  fi
done

if [[ "${1:-}" != "--force" && "$need_sync" -eq 0 ]]; then
  echo "Word Atlas media already present under $DEST (use --force to re-sync)."
  exit 0
fi

TMP="$(mktemp -d)"
cleanup() { rm -rf "$TMP"; }
trap cleanup EXIT

echo "Cloning Word Atlas media from $REPO_URL …"
git -c core.hooksPath=/dev/null clone --depth 1 --filter=blob:none --sparse "$REPO_URL" "$TMP/word-atlas"
git -C "$TMP/word-atlas" sparse-checkout set public/atlas/img public/atlas/audio public/atlas/thumb

mkdir -p "$DEST"
for dir in img audio thumb; do
  rm -rf "$DEST/$dir"
  mv "$TMP/word-atlas/public/atlas/$dir" "$DEST/$dir"
  echo "  synced $dir ($(du -sh "$DEST/$dir" | awk '{print $1}'))"
done

echo "Done."
