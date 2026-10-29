#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
PYTHON_BIN="${ATLAS_PYTHON:-/private/tmp/atlas-kokoro-venv/bin/python}"
export HF_HOME="${HF_HOME:-/private/tmp/atlas-hf}"
SHARDS="${ATLAS_SHARDS:-5}"
THREADS="${ATLAS_THREADS:-2}"
LOG_DIR="${ATLAS_LOG_DIR:-/private/tmp/atlas-kokoro-logs}"
mkdir -p "$LOG_DIR"
pids=()
for ((i=0; i<SHARDS; i++)); do
  device=cpu
  if ((i == 0)) && [[ "${ATLAS_USE_MPS:-0}" == 1 ]]; then device=mps; fi
  "$PYTHON_BIN" scripts/atlas/gen_phrase_audio_local.py --shard-index "$i" --shard-count "$SHARDS" --threads "$THREADS" --device "$device" > "$LOG_DIR/shard-$i.log" 2>&1 &
  pids+=("$!")
done
failed=0
for pid in "${pids[@]}"; do
  wait "$pid" || failed=1
done
if ((failed)); then
  echo 'At least one voice shard failed; rerun to resume missing clips.' >&2
  exit 1
fi
python3 - <<'PY'
import sys
sys.path.insert(0, 'scripts/atlas')
from gen_phrase_audio import collect_jobs
missing = [str(path) for _, _, path in collect_jobs() if not path.exists()]
print(f'Phrase audio missing={len(missing)}')
if missing:
    print('Rerun to generate missing clips; first:', missing[0], file=sys.stderr)
    raise SystemExit(1)
PY
ATLAS_BASE=/ielts/ npm run build > "$LOG_DIR/build.log" 2>&1
printf 'All voice shards and Word Atlas build completed.\n'
