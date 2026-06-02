#!/usr/bin/env bash
# ================================================================
#  fix_certbot_challenge.sh
#  Fix error: "Connection reset by peer" saat certbot challenge
#  Server: Oracle Linux 8, Apache
# ================================================================

GREEN='\033[0;32m'; YELLOW='\033[1;33m'
RED='\033[0;31m';   BLUE='\033[0;34m'
CYAN='\033[0;36m';  BOLD='\033[1m'; NC='\033[0m'

ok()   { echo -e "${GREEN}  ✓  $1${NC}"; }
warn() { echo -e "${YELLOW}  ⚠  $1${NC}"; }
err()  { echo -e "${RED}  ✗  $1${NC}"; }
info() { echo -e "     $1"; }
step() { echo -e "\n${BOLD}${BLUE}━━━ $1 ━━━${NC} $2\n"; }

echo ""
echo -e "${BOLD}${BLUE}╔══════════════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${BLUE}║   Diagnosa & Fix Certbot Challenge — Connection Error    ║${NC}"
echo -e "${BOLD}${BLUE}╚══════════════════════════════════════════════════════════╝${NC}"

if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}Jalankan sebagai root: sudo ./fix_certbot_challenge.sh${NC}"
    exit 1
fi

# ================================================================
# DIAGNOSA 1 — IP server & DNS
# ================================================================
step "DIAGNOSA 1" "Cek IP server vs DNS domain..."

SERVER_IP4=$(curl -4 -s --max-time 5 ifconfig.me 2>/dev/null || \
             curl -4 -s --max-time 5 icanhazip.com 2>/dev/null || \
             hostname -I | awk '{print $1}')
SERVER_IP6=$(curl -6 -s --max-time 5 ifconfig.me 2>/dev/null || echo "tidak ada")

echo -e "  IP server (IPv4): ${BOLD}$SERVER_IP4${NC}"
echo -e "  IP server (IPv6): ${BOLD}$SERVER_IP6${NC}"
echo ""

for DOMAIN in bogotaaprendetic.gov.co www.bogotaaprendetic.gov.co campus.bogotaaprendetic.gov.co; do
    DNS_IP=$(dig +short A $DOMAIN 2>/dev/null | head -1 || \
             nslookup $DOMAIN 2>/dev/null | grep "Address:" | tail -1 | awk '{print $2}')
    if [ "$DNS_IP" = "$SERVER_IP4" ]; then
        ok "$DOMAIN → $DNS_IP (cocok ✓)"
    elif [ -z "$DNS_IP" ]; then
        err "$DOMAIN → DNS tidak ditemukan! Domain belum pointing ke server ini"
    else
        warn "$DOMAIN → $DNS_IP (BERBEDA dari IP server: $SERVER_IP4)"
        info "DNS belum mengarah ke server ini, atau masih propagasi"
    fi
done

# ================================================================
# DIAGNOSA 2 — Cek Apache listen di port 80
# ================================================================
step "DIAGNOSA 2" "Cek Apache listen port 80..."

if ss -tlnp 2>/dev/null | grep -q ":80 "; then
    ok "Apache listen di port 80"
    ss -tlnp | grep ":80 " | sed 's/^/     /'
else
    err "Port 80 TIDAK ada yang listen!"
    info "Coba: systemctl start httpd"
fi

# Cek apakah Apache listen di 0.0.0.0 atau hanya localhost
if grep -r "Listen" /etc/httpd/conf/httpd.conf /etc/httpd/conf.d/ 2>/dev/null | \
   grep -v "#" | grep -q "127.0.0.1"; then
    warn "Apache mungkin hanya listen di localhost!"
    info "Edit /etc/httpd/conf/httpd.conf → ganti 'Listen 127.0.0.1:80' ke 'Listen 80'"
fi

# ================================================================
# DIAGNOSA 3 — Cek apakah port 80 bisa diakses dari luar
# ================================================================
step "DIAGNOSA 3" "Cek port 80 dari perspektif luar..."

# Buat file test di webroot
TEST_TOKEN="certbot-test-$(date +%s)"
TEST_DIR="/var/www/html/.well-known/acme-challenge"
mkdir -p "$TEST_DIR"
echo "test-ok" > "$TEST_DIR/$TEST_TOKEN"

# Coba akses dari luar via domain
for DOMAIN in bogotaaprendetic.gov.co campus.bogotaaprendetic.gov.co; do
    TEST_URL="http://$DOMAIN/.well-known/acme-challenge/$TEST_TOKEN"
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" \
                --max-time 10 "$TEST_URL" 2>/dev/null || echo "000")
    if [ "$HTTP_CODE" = "200" ]; then
        ok "$DOMAIN port 80 accessible dari luar (HTTP $HTTP_CODE)"
    elif [ "$HTTP_CODE" = "301" ] || [ "$HTTP_CODE" = "302" ]; then
        warn "$DOMAIN redirect ($HTTP_CODE) — mungkin ada force HTTPS di Apache"
        info "Certbot butuh port 80 plain HTTP untuk challenge, perlu disable redirect sementara"
    elif [ "$HTTP_CODE" = "000" ]; then
        err "$DOMAIN tidak bisa diakses sama sekali (timeout)"
        info "Kemungkinan: firewall cloud/VPS memblokir port 80 dari luar"
    else
        warn "$DOMAIN HTTP $HTTP_CODE — cek konfigurasi Apache"
    fi
done

rm -f "$TEST_DIR/$TEST_TOKEN"

# ================================================================
# DIAGNOSA 4 — Cek apakah ada redirect HTTP→HTTPS yang menghalangi
# ================================================================
step "DIAGNOSA 4" "Cek redirect HTTP→HTTPS di Apache..."

REDIRECT_FOUND=0
for conf in /etc/httpd/conf.d/*.conf /etc/httpd/conf/httpd.conf; do
    if grep -l "Redirect\|RewriteRule.*https\|mod_rewrite" "$conf" 2>/dev/null | grep -q .; then
        warn "Redirect ditemukan di: $conf"
        grep -n "Redirect\|RewriteRule.*https" "$conf" 2>/dev/null | head -5 | sed 's/^/     /'
        REDIRECT_FOUND=1
    fi
done
[ "$REDIRECT_FOUND" = "0" ] && ok "Tidak ada redirect HTTPS yang menghalangi"

# ================================================================
# FIX OTOMATIS
# ================================================================
step "FIX" "Menerapkan perbaikan..."

# FIX 1: Pastikan direktori challenge bisa diakses (tidak ter-redirect)
CHALLENGE_CONF="/etc/httpd/conf.d/letsencrypt-challenge.conf"
cat > "$CHALLENGE_CONF" << 'APACHECONF'
# Izinkan akses ke .well-known/acme-challenge tanpa redirect
# File ini dibuat oleh fix_certbot_challenge.sh
<LocationMatch "/.well-known/acme-challenge/">
    RewriteEngine Off
    Satisfy Any
    Allow from all
</LocationMatch>

Alias /.well-known/acme-challenge/ /var/www/html/.well-known/acme-challenge/
<Directory "/var/www/html/.well-known/acme-challenge/">
    Options None
    AllowOverride None
    Require all granted
    ForceType text/plain
</Directory>
APACHECONF
ok "Konfigurasi challenge path dibuat: $CHALLENGE_CONF"

# FIX 2: Buat direktori challenge dengan permission yang benar
mkdir -p /var/www/html/.well-known/acme-challenge/
chmod 755 /var/www/html/.well-known/acme-challenge/
chown apache:apache /var/www/html/.well-known/acme-challenge/ 2>/dev/null || true
ok "Direktori challenge disiapkan"

# FIX 3: Disable SELinux sementara jika aktif (bisa memblokir akses file)
if command -v getenforce &>/dev/null && [ "$(getenforce)" = "Enforcing" ]; then
    warn "SELinux Enforcing terdeteksi — bisa memblokir certbot"
    semanage fcontext -a -t httpd_sys_content_t \
        "/var/www/html/.well-known(/.*)?" 2>/dev/null || true
    restorecon -Rv /var/www/html/.well-known/ 2>/dev/null || true
    ok "SELinux context diperbaiki untuk .well-known"
fi

# FIX 4: Reload Apache
apachectl configtest 2>&1 | grep -q "Syntax OK" && {
    systemctl reload httpd
    ok "Apache di-reload"
} || {
    warn "Ada error di configtest, cek konfigurasi Apache"
    apachectl configtest
}

# ================================================================
# PILIHAN: HTTP Challenge vs DNS Challenge
# ================================================================
echo ""
echo -e "${BOLD}${YELLOW}╔══════════════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}${YELLOW}║              PILIH METODE CERTBOT                        ║${NC}"
echo -e "${BOLD}${YELLOW}╠══════════════════════════════════════════════════════════╣${NC}"
echo -e "${YELLOW}║                                                          ║${NC}"
echo -e "${YELLOW}║  A) HTTP Challenge (default) — pakai port 80             ║${NC}"
echo -e "${YELLOW}║     Cocok jika port 80 accessible dari internet          ║${NC}"
echo -e "${YELLOW}║                                                          ║${NC}"
echo -e "${YELLOW}║  B) DNS Challenge — tanpa perlu port 80/443              ║${NC}"
echo -e "${YELLOW}║     Cocok jika port 80 diblokir firewall cloud/VPS       ║${NC}"
echo -e "${YELLOW}║     Kamu perlu akses ke panel DNS domain                 ║${NC}"
echo -e "${YELLOW}║                                                          ║${NC}"
echo -e "${YELLOW}╚══════════════════════════════════════════════════════════╝${NC}"
echo ""
read -rp "  Pilih metode [A/B]: " METHOD
METHOD=$(echo "$METHOD" | tr '[:lower:]' '[:upper:]')

read -rp "  Email admin: " ADMIN_EMAIL

echo ""

if [ "$METHOD" = "A" ]; then
    # ── METODE A: HTTP Challenge ──────────────────────────────
    info "Menjalankan certbot dengan HTTP challenge..."
    echo ""

    certbot --apache \
        --non-interactive \
        --agree-tos \
        --email "$ADMIN_EMAIL" \
        --redirect \
        --webroot-path /var/www/html \
        -d bogotaaprendetic.gov.co \
        -d www.bogotaaprendetic.gov.co \
        -d campus.bogotaaprendetic.gov.co 2>&1

    EXIT_CODE=$?
    if [ $EXIT_CODE -eq 0 ]; then
        echo ""
        ok "SSL BERHASIL dipasang!"
    else
        echo ""
        err_msg="Certbot gagal. Coba pilih metode B (DNS Challenge)."
        warn "$err_msg"
    fi

elif [ "$METHOD" = "B" ]; then
    # ── METODE B: DNS Challenge (manual) ─────────────────────
    echo ""
    echo -e "${CYAN}${BOLD}  Metode DNS Challenge — Langkah Manual:${NC}"
    echo ""
    echo -e "  Certbot akan meminta kamu menambahkan TXT record ke DNS."
    echo -e "  Kamu perlu login ke panel DNS domain kamu."
    echo ""
    echo -e "  ${YELLOW}Siapkan akses ke panel DNS sebelum lanjut!${NC}"
    echo ""
    read -rp "  Tekan Enter jika sudah siap..." _

    certbot certonly \
        --manual \
        --preferred-challenges dns \
        --agree-tos \
        --email "$ADMIN_EMAIL" \
        -d bogotaaprendetic.gov.co \
        -d www.bogotaaprendetic.gov.co \
        -d campus.bogotaaprendetic.gov.co

    EXIT_CODE=$?
    if [ $EXIT_CODE -eq 0 ]; then
        echo ""
        ok "Sertifikat berhasil dibuat! Sekarang pasang ke Apache..."

        # Pasang sertifikat ke Apache
        CERT_PATH="/etc/letsencrypt/live/bogotaaprendetic.gov.co"
        APACHE_SSL_CONF="/etc/httpd/conf.d/ssl-bogotaaprendetic.conf"

        cat > "$APACHE_SSL_CONF" << SSLCONF
# SSL config untuk bogotaaprendetic.gov.co
# Auto-generated oleh fix_certbot_challenge.sh

<VirtualHost *:80>
    ServerName bogotaaprendetic.gov.co
    ServerAlias www.bogotaaprendetic.gov.co
    Redirect permanent / https://bogotaaprendetic.gov.co/
</VirtualHost>

<VirtualHost *:80>
    ServerName campus.bogotaaprendetic.gov.co
    Redirect permanent / https://campus.bogotaaprendetic.gov.co/
</VirtualHost>

<VirtualHost *:443>
    ServerName bogotaaprendetic.gov.co
    ServerAlias www.bogotaaprendetic.gov.co
    DocumentRoot /var/www/html

    SSLEngine on
    SSLCertificateFile      $CERT_PATH/fullchain.pem
    SSLCertificateKeyFile   $CERT_PATH/privkey.pem
    Include                 /etc/letsencrypt/options-ssl-apache.conf
</VirtualHost>

<VirtualHost *:443>
    ServerName campus.bogotaaprendetic.gov.co
    DocumentRoot /var/www/moodle

    SSLEngine on
    SSLCertificateFile      $CERT_PATH/fullchain.pem
    SSLCertificateKeyFile   $CERT_PATH/privkey.pem
    Include                 /etc/letsencrypt/options-ssl-apache.conf
</VirtualHost>
SSLCONF

        ok "Konfigurasi Apache SSL dibuat: $APACHE_SSL_CONF"
        apachectl configtest && systemctl reload httpd
        ok "Apache di-reload dengan SSL baru"
    fi
fi

# ================================================================
# RINGKASAN
# ================================================================
echo ""
echo -e "${BOLD}${GREEN}━━━ HASIL AKHIR ━━━${NC}"
echo ""

for DOMAIN in bogotaaprendetic.gov.co campus.bogotaaprendetic.gov.co; do
    CERT="/etc/letsencrypt/live/bogotaaprendetic.gov.co/fullchain.pem"
    if [ -f "$CERT" ]; then
        EXPIRY=$(openssl x509 -enddate -noout -in "$CERT" | cut -d= -f2)
        ISSUER=$(openssl x509 -issuer -noout -in "$CERT" | grep -o "Let's Encrypt\|R[0-9]*\|E[0-9]*" | head -1)
        ok "$DOMAIN → SSL valid, expired: $EXPIRY"
        info "Issuer: $ISSUER"
    else
        warn "$DOMAIN → Sertifikat belum ada"
    fi
done

echo ""
echo -e "  ${BOLD}Setelah berhasil, di GSC:${NC}"
echo -e "  1. Buka https://search.google.com/search-console"
echo -e "  2. Pilih property ${CYAN}bogotaaprendetic.gov.co${NC}"
echo -e "  3. Klik ${BOLD}Minta Pengindeksan${NC} / Request Indexing"
echo ""
