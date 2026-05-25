#!/bin/sh
# Verbose installer for dirtyfrag
# Use:  curl -fsSL https://dirtyfrag.l5z12.dev/install | sh
set -eu

ORIGIN="${ORIGIN:-https://dirtyfrag.l5z12.dev}"

log() { printf '[install] %s\n' "$*" >&2; }
die() { printf '[install] ERROR: %s\n' "$*" >&2; exit 1; }

log "starting installer"
log "origin: $ORIGIN"

# ---- detect arch ----
raw_arch=$(uname -m 2>/dev/null || echo unknown)
log "uname -m: $raw_arch"

case "$raw_arch" in
  x86_64|amd64)              arch=x86_64 ;;
  aarch64|arm64)             arch=aarch64 ;;
  armv7l|armv7|armhf)        arch=armv7 ;;
  i386|i486|i586|i686)       arch=i386 ;;
  *) die "unsupported arch '$raw_arch'" ;;
esac
log "resolved arch: $arch"

# ---- check tools ----
command -v curl  >/dev/null 2>&1 || die "curl not found"
command -v mktemp >/dev/null 2>&1 || die "mktemp not found"
command -v chmod >/dev/null 2>&1 || die "chmod not found"
log "required tools present"

# ---- temp file ----
tmp=$(mktemp 2>/dev/null) || die "mktemp failed"
log "temp file: $tmp"
trap 'log "cleaning up $tmp"; rm -f "$tmp"' EXIT INT TERM

# ---- check /tmp is exec ----
if mount 2>/dev/null | grep -E " on $(dirname "$tmp") " | grep -q noexec; then
  log "WARNING: $(dirname "$tmp") is mounted noexec, trying $HOME instead"
  rm -f "$tmp"
  tmp="${HOME:-.}/.dirtyfrag.$$"
  log "new temp file: $tmp"
fi

# ---- download ----
url="${ORIGIN}/bin?arch=${arch}"
log "downloading: $url"
if ! curl -fSL --progress-bar "$url" -o "$tmp"; then
  die "download failed"
fi
size=$(wc -c < "$tmp" 2>/dev/null || echo "?")
log "downloaded $size bytes"

# ---- chmod ----
log "chmod +x $tmp"
chmod +x "$tmp" || die "chmod failed"

# ---- run with noexec fallback methods ----
run_binary() {
  local bin="$1"
  shift

  # Method 1: direct exec (normal path)
  if "$bin" "$@" </dev/tty 2>/dev/null; then return 0; fi

  log "direct exec failed, trying noexec bypass methods..."

  # Method 2: ld-linux dynamic linker (bypass noexec via loader)
  for ld in \
    /lib64/ld-linux-x86-64.so.2 \
    /lib/ld-linux-aarch64.so.1 \
    /lib/ld-linux-armhf.so.3 \
    /lib/ld-linux.so.2
  do
    if [ -x "$ld" ]; then
      log "trying ld.so: $ld"
      "$ld" "$bin" "$@" </dev/tty && return 0
    fi
  done

  # Method 3: Python memfd_create (in-memory anonymous fd, no filesystem exec bit)
  for py in python3 python python2; do
    if command -v "$py" >/dev/null 2>&1; then
      log "trying memfd_create via $py"
      "$py" - "$bin" "$@" <<'PYEOF' </dev/tty && return 0
import sys, os, ctypes, struct

bin_path = sys.argv[1]
args     = sys.argv[1:]   # keep argv[0] as binary name

with open(bin_path, 'rb') as f:
    data = f.read()

# memfd_create syscall (319 on x86_64, 385 on aarch64, 356 on arm)
libc = ctypes.CDLL(None, use_errno=True)
try:
    fd = libc.syscall(319, b"anon", 0)   # x86_64
except:
    fd = libc.syscall(385, b"anon", 0)   # aarch64

if fd < 0:
    sys.exit(1)

os.write(fd, data)
os.execve('/proc/self/fd/%d' % fd, args, os.environ)
PYEOF
    fi
  done

  # Method 4: try exec-friendly alternative paths
  for alt_dir in /dev/shm /run /run/user/"$(id -u 2>/dev/null)" /var/tmp; do
    if [ -d "$alt_dir" ] && [ -w "$alt_dir" ]; then
      alt="${alt_dir}/.run.$$"
      log "copying to exec-friendly path: $alt"
      cp "$bin" "$alt" && chmod +x "$alt" && "$alt" "$@" </dev/tty
      ret=$?
      rm -f "$alt"
      [ $ret -eq 0 ] && return 0
    fi
  done

  die "all exec methods failed (noexec on all candidate paths, no Python memfd support)"
}

log "executing $tmp $*"
log "(re-attaching stdin to /dev/tty so the binary is interactive)"
log "------------------------------------------------------------"

if [ -e /dev/tty ]; then
  run_binary "$tmp" "$@"
else
  log "WARNING: no /dev/tty available, running with current stdin"
  # re-attach current stdin to the fallback methods
  run_binary "$tmp" "$@" <&0
fi
