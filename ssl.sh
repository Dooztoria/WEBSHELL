#!/usr/bin/env bash
# ================================================================
#  fix_ssl_letsencrypt.sh
#  Melanjutkan instalasi SSL Let's Encrypt yang terhenti
#  Server: Oracle Linux 8 / RHEL 8, Apache, Moodle
#  Domain:
#    - bogotaaprendetic.gov.co
#    - www.bogotaaprendetic.gov.co
#    - campus.bogotaaprendetic.gov.co
#
#  Jalankan sebagai root:
#    chmod +x fix_ssl_letsencrypt.sh
#    ./fix_ssl_letsencrypt.sh
# ================================================================

set -e

GREEN='\033[0;32m'; YELLOW='\033[1;33m'
RED='\033[0;31m';   BLUE='\033[0;34m'
BOLD='\033[1m';     NC='\033[0m'

ok()   { echo -e "${GREEN}  ✓  $1${NC}"; }
warn() { echo -e "${YELLOW}  ⚠  $1${NC}"; }
err()  { echo -e "${RED}  ✗  $1${NC}"; exit 1; }
info() { echo -e "     $1"; }
step() { echo -e "\n${BOLD}${BLUE}━━━ STEP $1 ━━━${NC} $2\n"; }

echo ""
echo -e "${BOLD}${BLUE}╔══════════════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${BLUE}║    Fix SSL Let's Encrypt — bogotaaprendetic.gov.co       ║${NC}"
echo -e "${BOLD}${BLUE}╚══════════════════════════════════════════════════════════╝${NC}"

# Pastikan dijalankan sebagai root
if [ "$EUID" -ne 0 ]; then
    err "Jalankan script ini sebagai root: sudo ./fix_ssl_letsencrypt.sh"
fi

# ================================================================
# STEP 1 — Perbaiki ssl.conf yang error (self-signed masih ada)
# ================================================================
step "1/6" "Memastikan ssl.conf Apache tidak error..."

SSL_CONF="/etc/httpd/conf.d/ssl.conf"
KEY_FILE="/etc/pki/tls/private/localhost.key"
CRT_FILE="/etc/pki/tls/certs/localhost.crt"

# Buat self-signed sementara jika belum ada (agar apachectl configtest OK)
if [ ! -f "$CRT_FILE" ] || [ ! -f "$KEY_FILE" ]; then
    info "Membuat self-signed cert sementara..."
    openssl req -new -newkey rsa:2048 -days 1 -nodes -x509 \
        -keyout "$KEY_FILE" \
        -out "$CRT_FILE" \
        -subj "/CN=localhost" 2>/dev/null
    ok "Self-signed cert sementara dibuat"
else
    ok "File cert sementara sudah ada"
fi

# Test konfigurasi Apache
apachectl configtest 2>&1 | grep -q "Syntax OK" && \
    ok "Apache configtest: Syntax OK" || \
    warn "Ada warning di configtest, lanjut cek..."

# ================================================================
# STEP 2 — Pastikan port 80 & 443 terbuka di firewall
# ================================================================
step "2/6" "Mengecek firewall..."

if systemctl is-active firewalld &>/dev/null; then
    firewall-cmd --permanent --add-service=http  &>/dev/null || true
    firewall-cmd --permanent --add-service=https &>/dev/null || true
    firewall-cmd --reload &>/dev/null
    ok "Firewall: port 80 & 443 terbuka"
else
    warn "firewalld tidak aktif, skip"
fi

# Cek apakah port 80 accessible dari luar (dipakai certbot untuk verifikasi)
info "Mengecek koneksi ke Let's Encrypt..."
if curl -s --max-time 5 https://acme-v02.api.letsencrypt.org/directory -o /dev/null; then
    ok "Server bisa menjangkau Let's Encrypt API"
else
    warn "Tidak bisa menjangkau Let's Encrypt. Cek koneksi internet server."
fi

# ================================================================
# STEP 3 — Pastikan Apache berjalan
# ================================================================
step "3/6" "Memastikan Apache berjalan..."

systemctl start httpd  2>/dev/null || true
systemctl enable httpd 2>/dev/null || true

if systemctl is-active httpd &>/dev/null; then
    ok "Apache (httpd) berjalan"
else
    err "Apache tidak berjalan! Jalankan: systemctl start httpd"
fi

# ================================================================
# STEP 4 — Upgrade certbot ke versi terbaru (via pip/snap)
# ================================================================
step "4/6" "Upgrade certbot..."

# Certbot 1.22 dari EPEL sudah cukup tua, coba upgrade jika snapd tersedia
# Atau gunakan yang sudah terinstall
CERTBOT_VER=$(certbot --version 2>&1 | awk '{print $2}')
info "Certbot terinstall: v$CERTBOT_VER"

# Cek apakah certbot-dns atau apache plugin tersedia
if ! python3 -c "import certbot_apache" 2>/dev/null; then
    info "Menginstall python3-certbot-apache..."
    dnf install -y python3-certbot-apache &>/dev/null || true
fi
ok "Certbot siap digunakan"

# ================================================================
# STEP 5 — Jalankan certbot untuk semua domain
# ================================================================
step "5/6" "Menjalankan certbot Let's Encrypt..."

echo ""
echo -e "  ${BOLD}Masukkan email admin untuk notifikasi Let's Encrypt:${NC}"
echo -e "  ${YELLOW}(email akan dipakai jika sertifikat mau expired)${NC}"
echo ""
read -rp "  Email admin: " ADMIN_EMAIL

if [ -z "$ADMIN_EMAIL" ]; then
    err "Email tidak boleh kosong!"
fi

echo ""
info "Mendaftarkan SSL untuk:"
info "  • bogotaaprendetic.gov.co"
info "  • www.bogotaaprendetic.gov.co"
info "  • campus.bogotaaprendetic.gov.co"
echo ""

# Jalankan certbot — mode non-interaktif
certbot --apache \
    --non-interactive \
    --agree-tos \
    --email "$ADMIN_EMAIL" \
    --redirect \
    -d bogotaaprendetic.gov.co \
    -d www.bogotaaprendetic.gov.co \
    -d campus.bogotaaprendetic.gov.co \
    2>&1 | tee /tmp/certbot_output.log

# Cek hasil
if grep -q "Congratulations" /tmp/certbot_output.log; then
    echo ""
    ok "SSL Let's Encrypt BERHASIL dipasang untuk semua domain!"
    echo ""
    echo -e "  ${GREEN}${BOLD}Sertifikat tersimpan di:${NC}"
    echo -e "  ${GREEN}  /etc/letsencrypt/live/bogotaaprendetic.gov.co/${NC}"

elif grep -q "Certificate not yet due for renewal" /tmp/certbot_output.log; then
    ok "Sertifikat sudah ada dan masih valid."

elif grep -q "too many certificates" /tmp/certbot_output.log; then
    echo ""
    warn "Rate limit Let's Encrypt tercapai (terlalu banyak percobaan)."
    echo ""
    echo -e "  ${YELLOW}Solusi: Tunggu 1 minggu atau gunakan mode staging untuk test:${NC}"
    echo -e "  ${YELLOW}certbot --apache --staging -d bogotaaprendetic.gov.co ...${NC}"

elif grep -q "Problem binding to port 80" /tmp/certbot_output.log || \
     grep -q "Could not bind to IPv4" /tmp/certbot_output.log; then
    echo ""
    warn "Port 80 tidak bisa digunakan certbot (mungkin diblokir atau dipakai proses lain)"
    echo ""
    info "Coba metode DNS challenge (tidak perlu port 80):"
    echo ""
    echo -e "  ${CYAN}certbot certonly --manual --preferred-challenges dns \\${NC}"
    echo -e "  ${CYAN}  -d bogotaaprendetic.gov.co \\${NC}"
    echo -e "  ${CYAN}  -d www.bogotaaprendetic.gov.co \\${NC}"
    echo -e "  ${CYAN}  -d campus.bogotaaprendetic.gov.co${NC}"

elif grep -q "Couldn't connect to server\|Connection refused\|No valid IP" /tmp/certbot_output.log; then
    echo ""
    warn "Domain tidak bisa dijangkau dari internet. Cek DNS records."
    echo ""
    info "Pastikan DNS domain mengarah ke IP server ini:"
    SERVER_IP=$(curl -s --max-time 5 ifconfig.me || hostname -I | awk '{print $1}')
    echo -e "  IP server ini: ${BOLD}$SERVER_IP${NC}"
    echo ""
    echo -e "  Di panel DNS kamu, harus ada A record:"
    echo -e "    bogotaaprendetic.gov.co        → $SERVER_IP"
    echo -e "    www.bogotaaprendetic.gov.co    → $SERVER_IP"
    echo -e "    campus.bogotaaprendetic.gov.co → $SERVER_IP"

else
    echo ""
    warn "Certbot mungkin gagal. Lihat log lengkap di: /var/log/letsencrypt/letsencrypt.log"
    echo ""
    tail -30 /var/log/letsencrypt/letsencrypt.log
fi

# ================================================================
# STEP 6 — Setup auto-renewal
# ================================================================
step "6/6" "Setup auto-renewal sertifikat..."

# Test renewal
certbot renew --dry-run &>/dev/null && ok "Auto-renewal test berhasil" || \
    warn "Auto-renewal test gagal, cek manual"

# Pastikan timer/cron aktif
if systemctl list-timers | grep -q certbot; then
    ok "Certbot timer systemd aktif"
elif crontab -l 2>/dev/null | grep -q certbot; then
    ok "Certbot cron job sudah ada"
else
    # Tambah cron job manual
    (crontab -l 2>/dev/null; echo "0 3 * * * certbot renew --quiet && systemctl reload httpd") | crontab -
    ok "Cron job renewal ditambahkan (tiap hari jam 03:00)"
fi

# Restart Apache
systemctl reload httpd 2>/dev/null && ok "Apache di-reload" || \
systemctl restart httpd 2>/dev/null && ok "Apache di-restart"

# ================================================================
# RINGKASAN AKHIR
# ================================================================
echo ""
echo -e "${BOLD}${GREEN}╔══════════════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${GREEN}║                   RINGKASAN HASIL                       ║${NC}"
echo -e "${BOLD}${GREEN}╠══════════════════════════════════════════════════════════╣${NC}"
echo -e "${GREEN}║                                                          ║${NC}"

# Cek status sertifikat
for DOMAIN in "bogotaaprendetic.gov.co" "campus.bogotaaprendetic.gov.co"; do
    CERT_PATH="/etc/letsencrypt/live/$DOMAIN"
    ALT_PATH="/etc/letsencrypt/live/bogotaaprendetic.gov.co"
    CHECK_PATH=""
    [ -d "$CERT_PATH" ] && CHECK_PATH="$CERT_PATH"
    [ -z "$CHECK_PATH" ] && [ -d "$ALT_PATH" ] && CHECK_PATH="$ALT_PATH"

    if [ -n "$CHECK_PATH" ] && [ -f "$CHECK_PATH/fullchain.pem" ]; then
        EXPIRY=$(openssl x509 -enddate -noout -in "$CHECK_PATH/fullchain.pem" \
                 | cut -d= -f2)
        echo -e "${GREEN}║  ✓ $DOMAIN${NC}"
        echo -e "${GREEN}║    Expired: $EXPIRY${NC}"
    else
        echo -e "${YELLOW}║  ⚠ $DOMAIN — cek manual${NC}"
    fi
    echo -e "${GREEN}║                                                          ║${NC}"
done

echo -e "${GREEN}║  Langkah selanjutnya:                                    ║${NC}"
echo -e "${GREEN}║  1. Test di browser:                                     ║${NC}"
echo -e "${GREEN}║     https://bogotaaprendetic.gov.co                      ║${NC}"
echo -e "${GREEN}║     https://campus.bogotaaprendetic.gov.co               ║${NC}"
echo -e "${GREEN}║  2. Cek di: https://www.ssllabs.com/ssltest/             ║${NC}"
echo -e "${GREEN}║  3. Di GSC: klik [Request Indexing] ulang                ║${NC}"
echo -e "${GREEN}║                                                           ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════════════════╝${NC}"
echo ""
