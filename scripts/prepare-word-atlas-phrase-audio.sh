#!/usr/bin/env bash
# Phrase audio is generated separately from the upstream Atlas img/word audio.
# A matching revision means deployments reuse the host's persistent files.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
EXPECTED="$(cat "$ROOT/apps/word-atlas/PHRASE_AUDIO_REVISION")"
COUNT="${EXPECTED##* }"
DEST="$ROOT/apps/word-atlas/public/atlas/audio"
MARKER="$DEST/.phrase-revision"

if [[ ! -f "$MARKER" || "$(cat "$MARKER")" != "$EXPECTED" ]]; then
  echo "Atlas phrase audio revision missing or changed; upload and verify the requested media before deployment." >&2
  exit 1
fi

ACTUAL="$(find "$DEST/phrase" -type f -name '*.mp3' | wc -l | tr -d ' ')"
if [[ "$ACTUAL" != "$COUNT" ]]; then
  echo "Atlas phrase audio incomplete: $ACTUAL / $COUNT. Refusing to relabel or redownload it." >&2
  exit 1
fi

echo "Atlas phrase audio unchanged ($EXPECTED); $ACTUAL files, no download."
