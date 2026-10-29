#!/usr/bin/env bash
# Fetch Word Atlas illustration/audio media into apps/word-atlas/public/atlas/.
# Source code and catalog JSON are vendored; ~700MB of img/audio/thumb stay out of git.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="$ROOT/apps/word-atlas/public/atlas"
REPO_URL="${WORD_ATLAS_REPO:-https://github.com/designfuchen/word-atlas.git}"
# Change MEDIA_REVISION only when the media itself is intentionally updated.
MEDIA_REF="${WORD_ATLAS_MEDIA_REF:-$(cat "$ROOT/apps/word-atlas/MEDIA_REVISION")}"
MARKER="$DEST/.media-revision"

need_sync=0
for dir in img audio thumb; do
  if [[ ! -d "$DEST/$dir" ]] || [[ -z "$(ls -A "$DEST/$dir" 2>/dev/null || true)" ]]; then
    need_sync=1
    break
  fi
done

if [[ "${1:-}" != "--force" && "$need_sync" -eq 0 ]] && \
  [[ ! -f "$MARKER" || "$(cat "$MARKER")" == "$MEDIA_REF" ]]; then
  printf '%s\n' "$MEDIA_REF" > "$MARKER"
  echo "Word Atlas media unchanged ($MEDIA_REF); keeping existing files."
  exit 0
fi

TMP="$(mktemp -d)"
cleanup() { rm -rf "$TMP"; }
trap cleanup EXIT

echo "Cloning Word Atlas media from $REPO_URL …"
git -c core.hooksPath=/dev/null clone --depth 1 --filter=blob:none --sparse "$REPO_URL" "$TMP/word-atlas"
if [[ "$(git -C "$TMP/word-atlas" rev-parse HEAD)" != "$MEDIA_REF" ]]; then
  git -C "$TMP/word-atlas" fetch --depth 1 origin "$MEDIA_REF"
  git -C "$TMP/word-atlas" checkout --detach "$MEDIA_REF"
fi
git -C "$TMP/word-atlas" sparse-checkout set public/atlas/img public/atlas/audio public/atlas/thumb

mkdir -p "$DEST"
for dir in img audio thumb; do
  if [[ "$dir" == audio && -d "$DEST/audio/phrase" ]]; then
    # The locally generated phrase catalog has its own revision and must survive
    # upstream word-audio refreshes. rsync excludes protect it from --delete.
    rsync -a --delete --exclude='/phrase/' --exclude='/.phrase-revision' \
      "$TMP/word-atlas/public/atlas/audio/" "$DEST/audio/"
  else
    rm -rf "$DEST/$dir"
    mv "$TMP/word-atlas/public/atlas/$dir" "$DEST/$dir"
  fi
  echo "  synced $dir ($(du -sh "$DEST/$dir" | awk '{print $1}'))"
done

echo "Done."
printf '%s\n' "$MEDIA_REF" > "$MARKER"
