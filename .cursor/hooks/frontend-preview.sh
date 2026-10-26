#!/usr/bin/env bash
# Rebuild frontend/dist and ensure nginx on :8088 serves the host dist.
#   debounce — afterFileEdit: only for frontend/* paths, delayed rebuild
#   now      — stop / manual: rebuild if dirty (or force)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

MODE="${1:-now}"
DIRTY="$ROOT/.cursor/.frontend-dirty"
LOG="$ROOT/.cursor/.frontend-preview.log"
MOUNT_MARK="$ROOT/.cursor/.frontend-preview-mounted"

mkdir -p "$ROOT/.cursor"

is_frontend_path() {
  local p="${1:-}"
  case "$p" in
    *"/frontend/"*|frontend/*|./frontend/*|*"/apps/word-atlas/"*|apps/word-atlas/*|./apps/word-atlas/*) return 0 ;;
    *) return 1 ;;
  esac
}

path_from_stdin() {
  # Read hook JSON from FD 0 into a var first — a heredoc would steal stdin.
  local payload
  payload="$(cat)"
  PAYLOAD="$payload" python3 - <<'PY'
import json, os
try:
    data = json.loads(os.environ.get("PAYLOAD") or "{}")
except Exception:
    raise SystemExit(0)

candidates = []
def walk(obj):
    if isinstance(obj, dict):
        for k, v in obj.items():
            lk = str(k).lower()
            if lk in ("path", "file_path", "filepath", "file", "uri", "target") and isinstance(v, str):
                candidates.append(v)
            walk(v)
    elif isinstance(obj, list):
        for i in obj:
            walk(i)
walk(data)
for c in candidates:
    print(c)
PY
}

ensure_nginx_mount() {
  local nginx_ok=0 app_ok=0
  if docker inspect growth-planet-nginx-1 --format '{{range .Mounts}}{{.Source}}{{"\n"}}{{end}}' 2>/dev/null \
    | grep -q '/frontend/dist$'; then
    nginx_ok=1
  fi
  if docker inspect growth-planet-app-1 --format '{{range .Mounts}}{{.Source}}{{"\n"}}{{end}}' 2>/dev/null \
    | grep -q '/backend$'; then
    app_ok=1
  fi
  if [[ -f "$MOUNT_MARK" && "$nginx_ok" -eq 1 && "$app_ok" -eq 1 ]]; then
    return 0
  fi

  if [[ ! -f "$ROOT/deploy/compose/.env.prod" ]]; then
    echo "[$(date '+%H:%M:%S')] skip mount: deploy/compose/.env.prod missing" >>"$LOG"
    return 0
  fi

  echo "[$(date '+%H:%M:%S')] mounting frontend/dist + backend onto preview stack" >>"$LOG"
  if docker compose -f compose.prod.yaml --env-file deploy/compose/.env.prod -f compose.preview.yaml up -d nginx app >>"$LOG" 2>&1; then
    # Drop cached config so new services.php / TTS code is visible immediately.
    docker compose -f compose.prod.yaml --env-file deploy/compose/.env.prod -f compose.preview.yaml exec -T app \
      php artisan config:clear >>"$LOG" 2>&1 || true
    touch "$MOUNT_MARK"
  else
    echo "[$(date '+%H:%M:%S')] preview mount failed" >>"$LOG"
  fi
}

build_dist() {
  echo "[$(date '+%H:%M:%S')] building frontend" >>"$LOG"
  (
    cd "$ROOT/frontend"
    # Keep dist/ielts (docker nested mount of word-atlas); vite emptyOutDir is off for the same reason.
    mkdir -p dist
    npx vite build >>"$LOG" 2>&1
  )
  if [[ -f "$ROOT/apps/word-atlas/package.json" ]]; then
    echo "[$(date '+%H:%M:%S')] building word-atlas (/ielts/)" >>"$LOG"
    if [[ ! -d "$ROOT/apps/word-atlas/public/atlas/img" ]]; then
      echo "[$(date '+%H:%M:%S')] word-atlas media missing; run make word-atlas-assets" >>"$LOG"
    else
      (
        cd "$ROOT/apps/word-atlas"
        # Keep the dist directory inode (nested docker bind-mount); only clear contents.
        mkdir -p dist
        find dist -mindepth 1 -delete
        ATLAS_BASE=/ielts/ npx vite build >>"$LOG" 2>&1
      )
      # Served via nested bind-mount apps/word-atlas/dist → /ielts/ (compose.yaml).
    fi
  fi
  rm -f "$DIRTY"
  echo "[$(date '+%H:%M:%S')] build done → http://localhost:8088" >>"$LOG"
}

case "$MODE" in
  debounce)
    paths="$(path_from_stdin || true)"
    hit=0
    while IFS= read -r p; do
      [[ -z "$p" ]] && continue
      if is_frontend_path "$p"; then
        hit=1
        break
      fi
    done <<<"$paths"

    if [[ "$hit" -ne 1 ]]; then
      printf '%s\n' '{}'
      exit 0
    fi

    token="$(date +%s%N)"
    printf '%s\n' "$token" >"$DIRTY"

    # Detach so Cursor does not kill the delayed rebuild when the hook exits.
    nohup bash -c "
      sleep 1.5
      current=\$(cat '$DIRTY' 2>/dev/null || true)
      [[ \"\$current\" == '$token' ]] || exit 0
      '$ROOT/.cursor/hooks/frontend-preview.sh' now force
    " >>"$LOG" 2>&1 &
    disown || true

    printf '%s\n' '{}'
    exit 0
    ;;
  now)
    force=0
    [[ "${2:-}" == "force" ]] && force=1
    if [[ "$force" -ne 1 && ! -f "$DIRTY" ]]; then
      printf '%s\n' '{}'
      exit 0
    fi
    ensure_nginx_mount
    build_dist
    printf '%s\n' '{}'
    exit 0
    ;;
  *)
    echo "usage: $0 debounce|now [force]" >&2
    exit 1
    ;;
esac
