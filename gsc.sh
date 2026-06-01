#!/usr/bin/env bash
# ================================================================
#  gsc_verify.sh
#  Script verifikasi Google Search Console untuk:
#    - https://bogotaaprendetic.gov.co/
#    - https://campus.bogotaaprendetic.gov.co/
#
#  Cara pakai (dari dalam server via SSH):
#    chmod +x gsc_verify.sh
#    ./gsc_verify.sh
# ================================================================

set -e

# ── Warna output ──────────────────────────────────────────────
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

ok()   { echo -e "${GREEN}  ✓  $1${NC}"; }
warn() { echo -e "${YELLOW}  ⚠  $1${NC}"; }
err()  { echo -e "${RED}  ✗  $1${NC}"; }
info() { echo -e "${CYAN}  →  $1${NC}"; }
step() { echo -e "\n${BOLD}${BLUE}[$1]${NC} $2"; }

# ── Banner ────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}${BLUE}╔══════════════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${BLUE}║     Google Search Console - Verifikasi HTML Upload       ║${NC}"
echo -e "${BOLD}${BLUE}║     bogotaaprendetic.gov.co  &  campus.*                 ║${NC}"
echo -e "${BOLD}${BLUE}╚══════════════════════════════════════════════════════════╝${NC}"
echo ""

# ================================================================
# STEP 1 — Temukan direktori root web otomatis
# ================================================================
step "1/5" "Mencari direktori root web server..."

find_webroot() {
    # Cek lokasi umum Moodle / Apache / Nginx
    local candidates=(
        "/var/www/html"
        "/var/www/html/moodle"
        "/var/www/moodle"
        "/var/www/bogotaaprendetic"
        "/var/www/campus"
        "/srv/www/htdocs"
        "/usr/share/nginx/html"
        "/opt/moodle"
        "/home/moodle/public_html"
        "/home/www/public_html"
    )
    for dir in "${candidates[@]}"; do
        if [ -d "$dir" ]; then
            echo "$dir"
            return
        fi
    done

    # Coba cari dari konfigurasi Apache
    if command -v apache2 &>/dev/null || command -v httpd &>/dev/null; then
        local apacheroot
        apacheroot=$(grep -r "DocumentRoot" /etc/apache2/ /etc/httpd/ 2>/dev/null \
                     | grep -v "#" | awk '{print $2}' | head -1 | tr -d '"')
        [ -d "$apacheroot" ] && echo "$apacheroot" && return
    fi

    # Coba cari dari konfigurasi Nginx
    if command -v nginx &>/dev/null; then
        local nginxroot
        nginxroot=$(grep -r "root " /etc/nginx/ 2>/dev/null \
                    | grep -v "#" | awk '{print $2}' | tr -d ';' | head -1)
        [ -d "$nginxroot" ] && echo "$nginxroot" && return
    fi

    echo ""
}

WEBROOT_MAIN=""
WEBROOT_CAMPUS=""

# Cari webroot otomatis
AUTO_ROOT=$(find_webroot)

if [ -n "$AUTO_ROOT" ]; then
    ok "Direktori ditemukan otomatis: $AUTO_ROOT"
    WEBROOT_MAIN="$AUTO_ROOT"
    WEBROOT_CAMPUS="$AUTO_ROOT"
else
    warn "Tidak bisa deteksi otomatis."
fi

# Minta konfirmasi / input manual
echo ""
echo -e "  ${BOLD}Masukkan path direktori root untuk masing-masing domain:${NC}"
echo -e "  (Tekan Enter untuk pakai default jika ada)"
echo ""

read -rp "  Path root untuk bogotaaprendetic.gov.co [${WEBROOT_MAIN:-/var/www/html}]: " INPUT_MAIN
WEBROOT_MAIN="${INPUT_MAIN:-${WEBROOT_MAIN:-/var/www/html}}"

read -rp "  Path root untuk campus.bogotaaprendetic.gov.co [${WEBROOT_CAMPUS:-/var/www/html}]: " INPUT_CAMPUS
WEBROOT_CAMPUS="${INPUT_CAMPUS:-${WEBROOT_CAMPUS:-/var/www/html}}"

# Validasi direktori
for dir_check in "$WEBROOT_MAIN" "$WEBROOT_CAMPUS"; do
    if [ ! -d "$dir_check" ]; then
        err "Direktori tidak ada: $dir_check"
        echo ""
        echo -e "  ${YELLOW}Contoh direktori yang mungkin benar:${NC}"
        find /var/www /srv/www /opt /home -maxdepth 4 -name "index.php" 2>/dev/null \
            | xargs -I{} dirname {} | sort -u | head -10 | sed 's/^/    • /'
        echo ""
        read -rp "  Masukkan path yang benar untuk $dir_check: " FIXED
        if [ "$dir_check" = "$WEBROOT_MAIN" ]; then
            WEBROOT_MAIN="$FIXED"
        else
            WEBROOT_CAMPUS="$FIXED"
        fi
    fi
done

ok "Root domain utama : $WEBROOT_MAIN"
ok "Root domain campus: $WEBROOT_CAMPUS"

# ================================================================
# STEP 2 — Input kode HTML dari Google Search Console
# ================================================================
step "2/5" "Masukkan kode verifikasi dari Google Search Console"

echo ""
echo -e "  ${BOLD}Cara mendapatkan kode verifikasi:${NC}"
echo -e "  1. Buka: ${CYAN}https://search.google.com/search-console${NC}"
echo -e "  2. Klik ${BOLD}Add Property${NC} (atau pilih property yang ada)"
echo -e "  3. Masukkan URL domain → pilih metode ${BOLD}HTML file${NC}"
echo -e "  4. Google akan memberikan:"
echo -e "     • Nama file  : contoh  ${YELLOW}googleXXXXXXXXXXXXXXXX.html${NC}"
echo -e "     • Isi file   : tag HTML pendek"
echo ""
echo -e "  ${RED}⚠  Kamu perlu melakukan ini DUA KALI — satu per domain${NC}"
echo ""

# ── Domain 1: bogotaaprendetic.gov.co ──
echo -e "  ${BOLD}━━━ Domain 1: bogotaaprendetic.gov.co ━━━${NC}"
read -rp "  Nama file HTML (contoh: googleabc123.html) : " GSC_FILENAME_MAIN
read -rp "  Isi konten file HTML (paste dari GSC)      : " GSC_CONTENT_MAIN
echo ""

# ── Domain 2: campus.bogotaaprendetic.gov.co ──
echo -e "  ${BOLD}━━━ Domain 2: campus.bogotaaprendetic.gov.co ━━━${NC}"
read -rp "  Nama file HTML (contoh: googlexyz789.html) : " GSC_FILENAME_CAMPUS
read -rp "  Isi konten file HTML (paste dari GSC)      : " GSC_CONTENT_CAMPUS
echo ""

# Validasi input tidak kosong
if [ -z "$GSC_FILENAME_MAIN" ] || [ -z "$GSC_CONTENT_MAIN" ]; then
    err "Kode verifikasi domain utama tidak boleh kosong!"
    exit 1
fi
if [ -z "$GSC_FILENAME_CAMPUS" ] || [ -z "$GSC_CONTENT_CAMPUS" ]; then
    err "Kode verifikasi domain campus tidak boleh kosong!"
    exit 1
fi

# ================================================================
# STEP 3 — Tulis file HTML ke server
# ================================================================
step "3/5" "Menulis file HTML verifikasi ke server..."

write_verify_file() {
    local webroot="$1"
    local filename="$2"
    local content="$3"
    local domain="$4"
    local filepath="$webroot/$filename"

    # Buat file
    echo "$content" > "$filepath"

    if [ -f "$filepath" ]; then
        ok "File dibuat: $filepath"
        # Set permission agar bisa dibaca web server
        chmod 644 "$filepath"
        chown www-data:www-data "$filepath" 2>/dev/null || \
        chown apache:apache "$filepath" 2>/dev/null || \
        chown nginx:nginx "$filepath" 2>/dev/null || true
        ok "Permission diset: 644"
    else
        err "Gagal membuat file: $filepath"
        echo -e "  ${YELLOW}Coba jalankan script ini dengan sudo${NC}"
        exit 1
    fi
}

echo ""
echo -e "  ${BOLD}Domain 1: bogotaaprendetic.gov.co${NC}"
write_verify_file "$WEBROOT_MAIN" "$GSC_FILENAME_MAIN" "$GSC_CONTENT_MAIN" "bogotaaprendetic.gov.co"

echo ""
echo -e "  ${BOLD}Domain 2: campus.bogotaaprendetic.gov.co${NC}"
write_verify_file "$WEBROOT_CAMPUS" "$GSC_FILENAME_CAMPUS" "$GSC_CONTENT_CAMPUS" "campus.bogotaaprendetic.gov.co"

# ================================================================
# STEP 4 — Verifikasi file bisa diakses via HTTP
# ================================================================
step "4/5" "Mengecek apakah file bisa diakses via HTTP..."

check_url() {
    local url="$1"
    local expected="$2"

    if command -v curl &>/dev/null; then
        HTTP_CODE=$(curl -s -o /tmp/gsc_check.txt -w "%{http_code}" \
                    --max-time 10 --insecure "$url" 2>/dev/null || echo "000")
        BODY=$(cat /tmp/gsc_check.txt 2>/dev/null || echo "")

        if [ "$HTTP_CODE" = "200" ]; then
            ok "HTTP 200 OK  →  $url"
            if echo "$BODY" | grep -q "google-site-verification"; then
                ok "Konten verifikasi GSC terdeteksi ✓"
            else
                warn "File ada tapi konten tidak cocok, cek isi file"
            fi
        elif [ "$HTTP_CODE" = "301" ] || [ "$HTTP_CODE" = "302" ]; then
            warn "HTTP $HTTP_CODE (redirect)  →  $url"
            info "Mungkin ada redirect HTTP→HTTPS. GSC biasanya masih bisa verifikasi."
        elif [ "$HTTP_CODE" = "000" ]; then
            warn "Tidak bisa connect ke $url (timeout/network)"
            info "Coba akses manual dari browser"
        else
            err "HTTP $HTTP_CODE  →  $url"
            info "Cek konfigurasi web server / firewall"
        fi
    else
        warn "curl tidak tersedia, skip pengecekan HTTP"
    fi
}

echo ""
check_url "https://bogotaaprendetic.gov.co/$GSC_FILENAME_MAIN"
echo ""
check_url "https://campus.bogotaaprendetic.gov.co/$GSC_FILENAME_CAMPUS"

# ================================================================
# STEP 5 — Ringkasan & instruksi selanjutnya
# ================================================================
step "5/5" "Selesai! Instruksi selanjutnya:"

echo ""
echo -e "${BOLD}${GREEN}╔══════════════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${GREEN}║                  LANGKAH SELANJUTNYA                    ║${NC}"
echo -e "${BOLD}${GREEN}╠══════════════════════════════════════════════════════════╣${NC}"
echo -e "${GREEN}║                                                          ║${NC}"
echo -e "${GREEN}║  1. Buka Google Search Console:                          ║${NC}"
echo -e "${GREEN}║     https://search.google.com/search-console             ║${NC}"
echo -e "${GREEN}║                                                          ║${NC}"
echo -e "${GREEN}║  2. Untuk bogotaaprendetic.gov.co:                       ║${NC}"
echo -e "${GREEN}║     Klik tombol [VERIFY] / [Verifikasi]                  ║${NC}"
echo -e "${GREEN}║                                                          ║${NC}"
echo -e "${GREEN}║  3. Untuk campus.bogotaaprendetic.gov.co:                ║${NC}"
echo -e "${GREEN}║     Klik tombol [VERIFY] / [Verifikasi]                  ║${NC}"
echo -e "${GREEN}║                                                          ║${NC}"
echo -e "${GREEN}║  4. Jika berhasil → status berubah jadi [Verified] ✓    ║${NC}"
echo -e "${GREEN}║                                                          ║${NC}"
echo -e "${GREEN}║  ⚠  JANGAN hapus file HTML ini setelah verifikasi!      ║${NC}"
echo -e "${GREEN}║     Google akan cek ulang secara berkala.                ║${NC}"
echo -e "${GREEN}║                                                          ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════════════════╝${NC}"
echo ""

# Simpan log lokasi file
LOG_FILE="gsc_verify_log.txt"
{
    echo "=== GSC Verification Log ==="
    echo "Tanggal     : $(date)"
    echo ""
    echo "Domain 1    : https://bogotaaprendetic.gov.co/"
    echo "File        : $WEBROOT_MAIN/$GSC_FILENAME_MAIN"
    echo "URL cek     : https://bogotaaprendetic.gov.co/$GSC_FILENAME_MAIN"
    echo ""
    echo "Domain 2    : https://campus.bogotaaprendetic.gov.co/"
    echo "File        : $WEBROOT_CAMPUS/$GSC_FILENAME_CAMPUS"
    echo "URL cek     : https://campus.bogotaaprendetic.gov.co/$GSC_FILENAME_CAMPUS"
} > "$LOG_FILE"

info "Log disimpan di: $(pwd)/$LOG_FILE"
echo ""
