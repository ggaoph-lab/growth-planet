#!/usr/bin/env bash
# Host compatibility check for Growth Planet production (Ubuntu 24.04 ARM Ampere, etc.).
set -euo pipefail

PASS=0
WARN=0
FAIL=0

pass() { echo "PASS  $*"; PASS=$((PASS + 1)); }
warn() { echo "WARN  $*"; WARN=$((WARN + 1)); }
fail() { echo "FAIL  $*"; FAIL=$((FAIL + 1)); }

echo "== Growth Planet host check =="
echo "date: $(date -Is 2>/dev/null || date)"
echo

# OS
if [[ -f /etc/os-release ]]; then
  # shellcheck disable=SC1091
  . /etc/os-release
  echo "OS: ${PRETTY_NAME:-$ID $VERSION_ID}"
  case "${ID:-}-${VERSION_ID:-}" in
    ubuntu-24.04*|ubuntu-22.04*) pass "Ubuntu ${VERSION_ID} is supported" ;;
    rocky-9*|almalinux-9*|rhel-9*) pass "${ID} ${VERSION_ID} is supported" ;;
    centos-7*) warn "CentOS 7 is EOL; container-only deploy is required" ;;
    *) warn "OS ${ID:-unknown} ${VERSION_ID:-} is untested; Docker-based deploy may still work" ;;
  esac
else
  warn "Cannot read /etc/os-release"
fi

# Arch
ARCH="$(uname -m)"
echo "Arch: ${ARCH}"
case "$ARCH" in
  aarch64|arm64) pass "ARM64 / aarch64 (Oracle Ampere A1 compatible)" ;;
  x86_64|amd64) pass "amd64 is supported" ;;
  *) fail "Unsupported architecture: ${ARCH}" ;;
esac

# Kernel
KREL="$(uname -r | cut -d- -f1)"
KMAJOR="$(echo "$KREL" | cut -d. -f1)"
KMINOR="$(echo "$KREL" | cut -d. -f2)"
if [[ "${KMAJOR:-0}" -gt 5 || ( "${KMAJOR:-0}" -eq 5 && "${KMINOR:-0}" -ge 14 ) ]]; then
  pass "Kernel ${KREL} >= 5.14"
elif [[ "${KMAJOR:-0}" -ge 3 ]]; then
  warn "Kernel ${KREL} is old; prefer >= 5.14"
else
  fail "Kernel ${KREL} is too old"
fi

# Docker
if command -v docker >/dev/null 2>&1; then
  DOCKER_VER="$(docker version --format '{{.Server.Version}}' 2>/dev/null || true)"
  if [[ -n "$DOCKER_VER" ]]; then
    pass "Docker Engine ${DOCKER_VER}"
  else
    fail "Docker is installed but the daemon is not reachable (permission or not running)"
  fi
else
  fail "Docker is not installed"
fi

if docker compose version >/dev/null 2>&1; then
  pass "Docker Compose v2 available: $(docker compose version --short 2>/dev/null || echo ok)"
else
  fail "Docker Compose v2 plugin missing (need: docker compose)"
fi

# Resources
MEM_KB="$(awk '/MemTotal/ {print $2}' /proc/meminfo 2>/dev/null || echo 0)"
MEM_GB=$((MEM_KB / 1024 / 1024))
if [[ "$MEM_GB" -ge 2 ]]; then
  pass "Memory ${MEM_GB} GiB (>= 2 GiB)"
else
  fail "Memory ${MEM_GB} GiB is below 2 GiB recommended for MySQL 8.4"
fi

AVAIL_KB="$(df -Pk /opt 2>/dev/null | awk 'NR==2 {print $4}' || df -Pk / | awk 'NR==2 {print $4}')"
AVAIL_GB=$((AVAIL_KB / 1024 / 1024))
if [[ "$AVAIL_GB" -ge 10 ]]; then
  pass "Disk free on deploy volume ~${AVAIL_GB} GiB (>= 10 GiB)"
else
  fail "Disk free ~${AVAIL_GB} GiB is below 10 GiB"
fi

# Ports
for PORT in 80 443; do
  if command -v ss >/dev/null 2>&1; then
    if ss -ltn "sport = :$PORT" 2>/dev/null | grep -q ":$PORT"; then
      warn "Port ${PORT} appears in use; set HTTP_PORT/HTTPS_PORT or stop the conflicting service"
    else
      pass "Port ${PORT} looks free"
    fi
  fi
done

# Time sync
if command -v timedatectl >/dev/null 2>&1; then
  if timedatectl show -p NTPSynchronized --value 2>/dev/null | grep -qi yes; then
    pass "System clock NTP synchronized"
  else
    warn "NTP not synchronized; enable chrony/systemd-timesyncd"
  fi
fi

# Optional smoke: pull is skipped here to keep the check offline-friendly.
echo
echo "Summary: ${PASS} passed, ${WARN} warnings, ${FAIL} failures"
if [[ "$FAIL" -gt 0 ]]; then
  exit 1
fi
exit 0
