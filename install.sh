#!/usr/bin/env bash
#
# install.sh — Installer otomatis MEeL-HUB
#
# Menjalankan seluruh langkah di "Instalasi Cepat" README secara otomatis:
#   1. Cek dependency (PHP + ekstensi, MariaDB/MySQL, Apache)
#   2. Setup database + import schema.sql
#   3. Buat auth/settings.php & auth/config.php dari template (+ MEEL_ENV/APP_DEBUG,
#      path biner manual, trusted proxy, opsi X-Sendfile)
#   4. Buat direktori storage runtime + symlink deploy ke MEEL_HDD_BASE
#      (hardening .htaccess folder upload ikut disalin ke target)
#   5. Aktifkan mod_rewrite + (opsional) buat VirtualHost Apache
#   6. Jalankan database/migrate.php
#   7. Verifikasi akhir via tests/check_deploy.php — exit 1 jika ada FAIL
#
# Didesain idempotent untuk sebagian besar langkah (settings.php, direktori
# storage, migration). Pengecualian: import awal schema.sql TIDAK diulang
# otomatis jika database sudah ada (berisi seed data Admin yang bukan
# idempotent) — script akan melewatinya dengan aman, bukan reimport paksa.
#
# Uji di Ubuntu/Debian (apt). Untuk distro lain, sesuaikan bagian
# install_dependencies().
#
# Pemakaian:
#   ./install.sh                 # mode interaktif (tanya konfigurasi)
#   ./install.sh --yes           # non-interaktif, pakai semua default
#   ./install.sh --hdd=/path     # set MEEL_HDD_BASE langsung
#   ./install.sh --skip-apt      # lewati instalasi paket sistem (sudah ada)
#   ./install.sh --xsendfile     # aktifkan MEEL_USE_XSENDFILE (wajib mod_xsendfile Apache)
#   ./install.sh --env=production|development   # set MEEL_ENV + APP_DEBUG
#   ./install.sh --trust-proxy   # aktifkan MEEL_TRUST_PROXY_HEADERS (di balik reverse proxy)
#   ./install.sh --vhost=meel.local  # buat VirtualHost Apache untuk domain tsb
#   ./install.sh --help
#
# Semua prompt ya/tidak memakai 'y' = ya, 't' = tidak.
#
set -euo pipefail

# ─────────────────────────────────────────────────────────────────────────
# Warna & helper output
# ─────────────────────────────────────────────────────────────────────────
if [ -t 1 ]; then
    C_RESET='\033[0m'; C_BOLD='\033[1m'
    C_RED='\033[1;31m'; C_GREEN='\033[1;32m'; C_YELLOW='\033[1;33m'; C_CYAN='\033[1;36m'
else
    C_RESET=''; C_BOLD=''; C_RED=''; C_GREEN=''; C_YELLOW=''; C_CYAN=''
fi

step()  { echo -e "\n${C_CYAN}==>${C_RESET} ${C_BOLD}$1${C_RESET}"; }
ok()    { echo -e "  ${C_GREEN}✔${C_RESET} $1"; }
warn()  { echo -e "  ${C_YELLOW}⚠${C_RESET} $1"; }
fail()  { echo -e "  ${C_RED}✘${C_RESET} $1"; }
die()   { fail "$1"; exit 1; }

# ─────────────────────────────────────────────────────────────────────────
# Argumen CLI
# ─────────────────────────────────────────────────────────────────────────
ASSUME_YES=false
SKIP_APT=false
HDD_OVERRIDE=""
XSENDFILE_OVERRIDE=""      # ""=tanya, "1"=aktifkan, "0"=nonaktifkan
ENV_OVERRIDE=""             # ""=tanya, "production"|"development"
TRUST_PROXY_OVERRIDE=""     # ""=tanya, "1"=aktifkan, "0"=nonaktifkan
VHOST_OVERRIDE=""           # ""=tanya/lewati, domain=buat VirtualHost

for arg in "$@"; do
    case "$arg" in
        --yes|-y)      ASSUME_YES=true ;;
        --skip-apt)    SKIP_APT=true ;;
        --hdd=*)       HDD_OVERRIDE="${arg#--hdd=}" ;;
        --xsendfile)   XSENDFILE_OVERRIDE=1 ;;
        --no-xsendfile) XSENDFILE_OVERRIDE=0 ;;
        --env=*)       ENV_OVERRIDE="${arg#--env=}" ;;
        --trust-proxy) TRUST_PROXY_OVERRIDE=1 ;;
        --no-trust-proxy) TRUST_PROXY_OVERRIDE=0 ;;
        --vhost=*)     VHOST_OVERRIDE="${arg#--vhost=}" ;;
        --help|-h)
            sed -n '2,35p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            die "Argumen tidak dikenal: $arg (lihat --help)"
            ;;
    esac
done

confirm() {
    # confirm "Pertanyaan" default_jawaban(Y/N)
    # Prompt boolean konsisten: 'y' = ya, 't' = tidak (juga menerima 'n').
    # Di mode --yes (non-interaktif): ikuti nilai default, JANGAN selalu
    # jawab ya — beberapa prompt (mis. reimport schema ke DB yang sudah
    # ada) sengaja default ke N karena berisiko/destruktif.
    local prompt="$1" default="${2:-Y}" reply
    if $ASSUME_YES; then
        [ "$default" = "Y" ]
        return
    fi
    read -r -p "  $prompt (y = ya, t = tidak) " reply || true
    reply="${reply:-$default}"
    [[ "$reply" =~ ^[Yy]$ ]]
}

ask() {
    # ask "Pertanyaan" default_value -> echo hasil
    local prompt="$1" default="$2" reply
    if $ASSUME_YES; then echo "$default"; return; fi
    read -r -p "  $prompt [$default]: " reply || true
    echo "${reply:-$default}"
}

ask_secret() {
    local prompt="$1" default="$2" reply
    if $ASSUME_YES; then echo "$default"; return; fi
    read -r -s -p "  $prompt: " reply || true
    echo ""
    echo "${reply:-$default}"
}

# ─────────────────────────────────────────────────────────────────────────
# Lokasi proyek — script ini HARUS dijalankan dari root repo MEeL-HUB
# ─────────────────────────────────────────────────────────────────────────
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$PROJECT_ROOT"

if [ ! -f "auth/settings.example.php" ] || [ ! -f "database/schema.sql" ]; then
    die "Script ini harus dijalankan dari root repo MEeL-HUB (auth/settings.example.php atau database/schema.sql tidak ditemukan di $PROJECT_ROOT)."
fi

echo -e "${C_BOLD}"
echo "  __  __ _____     _        _   _ _   _ ____  "
echo " |  \/  | ____|___| |      | | | | | | | __ ) "
echo " | |\/| |  _| / _ \ |______| |_| | | | |  _ \ "
echo " | |  | | |__|  __/ |______|  _  | |_| | |_) |"
echo " |_|  |_|_____\___|_|      |_| |_|\___/|____/ "
echo -e "${C_RESET}"
echo " Installer — clone → install → run"
echo " Project root: $PROJECT_ROOT"

# ─────────────────────────────────────────────────────────────────────────
# 1. Deteksi OS & dependency
# ─────────────────────────────────────────────────────────────────────────
step "1/7 — Cek dependency sistem"

SUDO=""
CAN_ELEVATE=true   # true jika sudah root ATAU 'sudo' tersedia
if [ "$(id -u)" -ne 0 ]; then
    if command -v sudo >/dev/null 2>&1; then
        SUDO="sudo"
    else
        CAN_ELEVATE=false
        warn "Bukan root dan 'sudo' tidak tersedia — instalasi paket sistem mungkin gagal."
    fi
fi

HAS_APT=false
command -v apt-get >/dev/null 2>&1 && HAS_APT=true

REQUIRED_APT_PKGS="php-cli php-mysqli php-pdo-mysql php-fileinfo php-mbstring php-intl php-gd php-zip php-xml php-curl mariadb-server"
OPTIONAL_APT_PKGS="ffmpeg mecab mecab-ipadic-utf8 apache2 libapache2-mod-php composer"

if ! $SKIP_APT && $HAS_APT; then
    if confirm "Install/verifikasi paket sistem via apt sekarang? (PHP, ekstensi, MariaDB, dll.)" Y; then
        step "Update apt & install paket wajib"
        # apt-get update bisa gagal parsial karena repo pihak ketiga yang error
        # (mis. PPA/repo tambahan down) — itu tidak boleh menggagalkan seluruh
        # instalasi selama paket yang kita butuhkan tetap ada di repo utama.
        $SUDO apt-get update -y || warn "apt-get update gagal sebagian (repo pihak ketiga?) — lanjut coba install paket."
        $SUDO apt-get install -y $REQUIRED_APT_PKGS
        ok "Paket wajib terpasang: $REQUIRED_APT_PKGS"

        if confirm "Install juga paket opsional (ffmpeg, mecab, apache2, composer)?" Y; then
            $SUDO apt-get install -y $OPTIONAL_APT_PKGS || warn "Sebagian paket opsional gagal terpasang — cek manual nanti."
        fi
    fi
elif ! $HAS_APT; then
    warn "apt-get tidak terdeteksi (bukan Debian/Ubuntu) — lewati instalasi paket otomatis."
    warn "Pastikan manual: PHP 8.0+ (mysqli, mbstring, intl, gd, zip, xml, curl), MariaDB/MySQL, Apache+mod_rewrite."
else
    ok "Instalasi apt dilewati (--skip-apt)."
fi

# Verifikasi biner inti
MISSING=()
command -v php   >/dev/null 2>&1 || MISSING+=("php")
command -v mysql >/dev/null 2>&1 || MISSING+=("mysql (client)")
if [ ${#MISSING[@]} -gt 0 ]; then
    die "Dependency wajib belum ada: ${MISSING[*]}. Install manual lalu jalankan ulang script ini."
fi
ok "php: $(php -v | head -n1)"

# Verifikasi ekstensi PHP wajib. Kadang paket sudah ter-install via apt tapi
# modul belum aktif di SAPI cli tepat saat pengecekan (race dpkg trigger,
# terutama jika instalasi apache2/php dijalankan berbarengan) — coba
# `phpenmod` sebagai fallback sebelum benar-benar dianggap gagal.
REQUIRED_EXT="mysqli pdo_mysql fileinfo mbstring intl gd zip xml curl"
MISSING_EXT=()
for ext in $REQUIRED_EXT; do
    if php -m | grep -qi "^${ext}\$"; then
        continue
    fi
    if command -v phpenmod >/dev/null 2>&1 && $CAN_ELEVATE; then
        $SUDO phpenmod "$ext" >/dev/null 2>&1 || true
    fi
    # Retry singkat — dpkg trigger (phpenmod symlink) kadang butuh sesaat
    # untuk benar-benar tersedia ke proses baru, terutama jika instalasi
    # paket lain (apache2, dll.) sedang berjalan bersamaan.
    FOUND=false
    for _try in 1 2 3; do
        if php -m | grep -qi "^${ext}\$"; then
            FOUND=true
            break
        fi
        sleep 1
    done
    if $FOUND; then
        ok "Ekstensi '$ext' aktif."
    else
        MISSING_EXT+=("$ext")
    fi
done
if [ ${#MISSING_EXT[@]} -gt 0 ]; then
    die "Ekstensi PHP wajib belum aktif: ${MISSING_EXT[*]}. Install manual (apt install php-<ext>) lalu jalankan ulang."
fi
ok "Ekstensi PHP wajib lengkap: $REQUIRED_EXT"

# Cek biner opsional (fitur tetap jalan tanpa ini, hanya fitur terkait nonaktif)
for bin in ffmpeg ffprobe yt-dlp mecab; do
    if command -v "$bin" >/dev/null 2>&1; then
        ok "$bin terdeteksi"
    else
        warn "$bin tidak ditemukan — fitur terkait ($bin) tidak akan berfungsi sampai diinstall."
    fi
done

# ─────────────────────────────────────────────────────────────────────────
# 2. Konfigurasi Database
# ─────────────────────────────────────────────────────────────────────────
step "2/7 — Konfigurasi Database"

DB_HOST="$(ask "Host database" "localhost")"
DB_NAME="$(ask "Nama database" "MEeL")"
DB_USER="$(ask "User database" "root")"
DB_PASS="$(ask_secret "Password database (kosongkan jika tanpa password)" "")"

MYSQL_AUTH=(-h "$DB_HOST" -u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL_AUTH+=(-p"$DB_PASS")

# Pastikan service DB nyala (best-effort — nama service beda-beda per distro)
if command -v service >/dev/null 2>&1; then
    $SUDO service mariadb start 2>/dev/null || $SUDO service mysql start 2>/dev/null || true
elif command -v systemctl >/dev/null 2>&1; then
    $SUDO systemctl start mariadb 2>/dev/null || $SUDO systemctl start mysql 2>/dev/null || true
fi
sleep 1

if ! mysql "${MYSQL_AUTH[@]}" -e "SELECT 1;" >/dev/null 2>&1; then
    die "Tidak bisa konek ke MySQL/MariaDB dengan kredensial di atas. Pastikan service jalan & kredensial benar."
fi
ok "Koneksi database berhasil."

DB_EXISTS=$(mysql "${MYSQL_AUTH[@]}" -N -e "SHOW DATABASES LIKE '${DB_NAME}';" 2>/dev/null | wc -l)
if [ "$DB_EXISTS" -gt 0 ]; then
    warn "Database '${DB_NAME}' sudah ada — dilewati apa adanya (schema.sql berisi seed data Admin"
    warn "yang bukan idempotent, reimport akan gagal 'Duplicate entry' jika data sudah ada)."
    warn "Jika Anda ingin instalasi BENAR-BENAR bersih: DROP DATABASE \`${DB_NAME}\`; lalu jalankan ulang script ini."
    if confirm "Tetap paksa reimport schema.sql sekarang? (BERISIKO gagal jika tabel/data sudah ada)" N; then
        # schema.sql meng-hardcode `CREATE DATABASE ... MEeL` + `USE MEeL` —
        # samakan dengan nama DB konfigurasi agar import tidak melompat ke
        # database lain (mis. MEeL asli) saat nama DB berbeda dari default.
        if ! sed -e "s#^CREATE DATABASE IF NOT EXISTS \`MEeL\`#CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`#" \
                 -e "s#^USE \`MEeL\`;#USE \`${DB_NAME}\`;#" database/schema.sql | mysql "${MYSQL_AUTH[@]}"; then
            warn "Reimport schema gagal (kemungkinan besar data sudah ada) — melanjutkan dengan database apa adanya."
        else
            ok "Schema di-import ulang ke '${DB_NAME}'."
        fi
    fi
else
    mysql "${MYSQL_AUTH[@]}" -e "CREATE DATABASE \`${DB_NAME}\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
    # schema.sql meng-hardcode `CREATE DATABASE ... MEeL` + `USE MEeL` —
    # samakan dengan nama DB konfigurasi agar import tidak melompat ke
    # database lain (mis. MEeL asli) saat nama DB berbeda dari default.
    sed -e "s#^CREATE DATABASE IF NOT EXISTS \`MEeL\`#CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`#" \
        -e "s#^USE \`MEeL\`;#USE \`${DB_NAME}\`;#" database/schema.sql | mysql "${MYSQL_AUTH[@]}"
    ok "Database '${DB_NAME}' dibuat & schema di-import (20 tabel)."
fi

# ─────────────────────────────────────────────────────────────────────────
# 3. auth/settings.php & auth/config.php
# ─────────────────────────────────────────────────────────────────────────
step "3/7 — Buat auth/settings.php & auth/config.php"

if [ -f "auth/settings.php" ]; then
    warn "auth/settings.php sudah ada — tidak ditimpa (hapus manual jika ingin regenerasi)."
else
    cp auth/settings.example.php auth/settings.php
    ok "auth/settings.php dibuat dari template."
fi

if [ -f "auth/config.php" ]; then
    ok "auth/config.php sudah ada — dilewati."
else
    cp auth/config.example.php auth/config.php
    ok "auth/config.php dibuat dari template."
fi

# Tanya lokasi storage media (MEEL_HDD_BASE)
if [ -n "$HDD_OVERRIDE" ]; then
    HDD_BASE="$HDD_OVERRIDE"
else
    DEFAULT_HDD="$PROJECT_ROOT/storage/media"
    HDD_BASE="$(ask "Lokasi storage media (MEEL_HDD_BASE) — bisa HDD eksternal atau folder lokal" "$DEFAULT_HDD")"
fi
HDD_BASE="${HDD_BASE%/}"   # normalisasi: buang trailing slash

# Patch settings.php: DB creds + MEEL_HDD_BASE (hanya replace baris default,
# aman dijalankan ulang karena mencocokkan pola persis dari settings.example.php)
python3 - "$DB_HOST" "$DB_USER" "$DB_PASS" "$DB_NAME" "$HDD_BASE" "auth/settings.php" <<'PYEOF' 2>/dev/null || {
import sys, re
host, user, passwd, db, hdd, path = sys.argv[1:7]
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()
content = re.sub(r'\$server\s*=\s*"localhost";', f'$server   = "{host}";', content, count=1)
content = re.sub(r'\$username\s*=\s*"root";', f'$username = "{user}";', content, count=1)
content = re.sub(r'\$password\s*=\s*"";', f'$password = "{passwd}";', content, count=1)
content = re.sub(r'\$db\s*=\s*"MEeL";', f'$db       = "{db}";', content, count=1)
content = content.replace("define('MEEL_HDD_BASE', '/media/CHANGE_ME/MEeL/media');", f"define('MEEL_HDD_BASE', '{hdd}');")
with open(path, 'w', encoding='utf-8') as f:
    f.write(content)
print("patched via python3")
PYEOF
    # Fallback sed jika python3 tidak tersedia
    sed -i "s#\$server   = \"localhost\";#\$server   = \"${DB_HOST}\";#" auth/settings.php
    sed -i "s#\$username = \"root\";#\$username = \"${DB_USER}\";#" auth/settings.php
    sed -i "s#\$password = \"\";#\$password = \"${DB_PASS}\";#" auth/settings.php
    sed -i "s#\$db       = \"MEeL\";#\$db       = \"${DB_NAME}\";#" auth/settings.php
    sed -i "s#define('MEEL_HDD_BASE', '/media/CHANGE_ME/MEeL/media');#define('MEEL_HDD_BASE', '${HDD_BASE}');#" auth/settings.php
}
ok "auth/settings.php dikonfigurasi (DB: ${DB_NAME}@${DB_HOST}, storage: ${HDD_BASE})"

# ─────────────────────────────────────────────────────────────────────────
# 3b. Environment, path biner, trusted proxy (opsional — patch settings.php)
# ─────────────────────────────────────────────────────────────────────────
ENV_CHOICE="production"
if [ -n "$ENV_OVERRIDE" ]; then
    ENV_CHOICE="$ENV_OVERRIDE"
else
    ENV_CHOICE="$(ask "Environment aplikasi (production/development)" "production")"
fi
case "$ENV_CHOICE" in
    production|development) ;;
    *) warn "Environment '$ENV_CHOICE' tidak dikenal — pakai production."; ENV_CHOICE="production" ;;
esac
DEBUG_CHOICE=false
[ "$ENV_CHOICE" = "development" ] && DEBUG_CHOICE=true

# Path biner manual — kosong = auto-detect via resolveBinary() saat runtime
FFMPEG_PATH=""; FFPROBE_PATH=""; NODE_PATH=""; YTDLP_PATH=""
if ! $ASSUME_YES && confirm "Set path biner manual (ffmpeg/ffprobe/node/yt-dlp)? (kosongkan semua = auto-detect)" N; then
    FFMPEG_PATH="$(ask "Path ffmpeg (kosong = auto-detect)" "")"
    FFPROBE_PATH="$(ask "Path ffprobe (kosong = auto-detect)" "")"
    NODE_PATH="$(ask "Path node (kosong = auto-detect)" "")"
    YTDLP_PATH="$(ask "Path yt-dlp (kosong = auto-detect)" "")"
fi

# Trusted proxy headers — HANYA jika di balik reverse proxy (nginx/caddy/Cloudflare)
TRUST_PROXY=false
if [ "$TRUST_PROXY_OVERRIDE" = "1" ]; then
    TRUST_PROXY=true
elif [ "$TRUST_PROXY_OVERRIDE" != "0" ] && confirm "Aplikasi di balik reverse proxy (nginx/caddy/Cloudflare)? (IP asli berasal dari header proxy)" N; then
    TRUST_PROXY=true
fi

# Terapkan ke settings.php (nilai kosong = biarkan default). Fallback sed
# dipakai jika python3 tidak tersedia. Aman dijalankan ulang: tidak ada
# perubahan jika settings.php sudah dimodifikasi manual (pola tidak cocok).
patch_optional_settings() {
    # patch_optional_settings ENV DEBUG FFMPEG FFPROBE NODE YTDLP TRUST_PROXY
    python3 - "$@" "auth/settings.php" <<'PYEOF' 2>/dev/null || {
import re, sys
env, debug, ffmpeg, ffprobe, node, ytdlp, trust, path = sys.argv[1:9]
with open(path, 'r', encoding='utf-8') as f:
    c = f.read()
if env == 'production':
    # uncomment baris yang nilainya sama persis (idempotent: baris lain
    # dibiarkan sebagai komentar, tidak pernah duplikat define aktif)
    c = re.sub(r"^//\s*define\('MEEL_ENV', 'production'\).*$",
               "define('MEEL_ENV', 'production');", c, count=1, flags=re.M)
elif env == 'development':
    c = re.sub(r"^//\s*define\('MEEL_ENV', 'development'\).*$",
               "define('MEEL_ENV', 'development');", c, count=1, flags=re.M)
if debug == 'true':
    c = re.sub(r"^//\s*define\('APP_DEBUG', true\).*$",
               "define('APP_DEBUG', true);", c, count=1, flags=re.M)
elif debug == 'false':
    c = re.sub(r"^//\s*define\('APP_DEBUG', false\).*$",
               "define('APP_DEBUG', false);", c, count=1, flags=re.M)
for name, val in (('MEEL_FFMPEG_PATH', ffmpeg), ('MEEL_FFPROBE_PATH', ffprobe),
                  ('MEEL_NODE_PATH', node), ('MEEL_YTDLP_PATH', ytdlp)):
    if val:
        c = re.sub(r"define\('%s'\s*,\s*''\)" % name,
                   "define('%s', '%s')" % (name, val), c, count=1)
if trust == 'true':
    c = re.sub(r"define\('MEEL_TRUST_PROXY_HEADERS'\s*,\s*false\)",
               "define('MEEL_TRUST_PROXY_HEADERS', true)", c, count=1)
with open(path, 'w', encoding='utf-8') as f:
    f.write(c)
print("patched via python3")
PYEOF
    # Fallback sed jika python3 tidak tersedia
    case "$1" in
        production)  sed -i "s#^// define('MEEL_ENV', 'production');#define('MEEL_ENV', 'production');#" auth/settings.php ;;
        development) sed -i "s#^// define('MEEL_ENV', 'development');#define('MEEL_ENV', 'development');#" auth/settings.php ;;
    esac
    if [ "$2" = "true" ]; then
        sed -i "s#^// define('APP_DEBUG', true);.*#define('APP_DEBUG', true);#" auth/settings.php
    elif [ "$2" = "false" ]; then
        sed -i "s#^// define('APP_DEBUG', false);.*#define('APP_DEBUG', false);#" auth/settings.php
    fi
    [ -n "$3" ] && sed -i "s#define('MEEL_FFMPEG_PATH', '');#define('MEEL_FFMPEG_PATH', '$3');#" auth/settings.php
    [ -n "$4" ] && sed -i "s#define('MEEL_FFPROBE_PATH', '');#define('MEEL_FFPROBE_PATH', '$4');#" auth/settings.php
    [ -n "$5" ] && sed -i "s#define('MEEL_NODE_PATH', '');#define('MEEL_NODE_PATH', '$5');#" auth/settings.php
    [ -n "$6" ] && sed -i "s#define('MEEL_YTDLP_PATH', '');#define('MEEL_YTDLP_PATH', '$6');#" auth/settings.php
    [ "$7" = "true" ] && sed -i "s#define('MEEL_TRUST_PROXY_HEADERS', false);#define('MEEL_TRUST_PROXY_HEADERS', true);#" auth/settings.php
    }
}

patch_optional_settings "$ENV_CHOICE" "$DEBUG_CHOICE" "$FFMPEG_PATH" "$FFPROBE_PATH" "$NODE_PATH" "$YTDLP_PATH" "$TRUST_PROXY"
ok "MEEL_ENV=${ENV_CHOICE} (APP_DEBUG=${DEBUG_CHOICE}) ditulis ke auth/settings.php."
[ -n "$FFMPEG_PATH" ] && ok "MEEL_FFMPEG_PATH → $FFMPEG_PATH"
[ -n "$FFPROBE_PATH" ] && ok "MEEL_FFPROBE_PATH → $FFPROBE_PATH"
[ -n "$NODE_PATH" ] && ok "MEEL_NODE_PATH → $NODE_PATH"
[ -n "$YTDLP_PATH" ] && ok "MEEL_YTDLP_PATH → $YTDLP_PATH"
if $TRUST_PROXY; then
    ok "MEEL_TRUST_PROXY_HEADERS diaktifkan (percaya X-Forwarded-For/Proto)."
else
    warn "MEEL_TRUST_PROXY_HEADERS tetap nonaktif (default aman — jangan aktifkan tanpa proxy)."
fi

# ─────────────────────────────────────────────────────────────────────────
# X-Sendfile (opsional — akselerasi streaming via Apache)
# ─────────────────────────────────────────────────────────────────────────
# PERHATIAN: dengan MEEL_USE_XSENDFILE=true, aplikasi mengirim header
# X-Sendfile lalu BERHENTI streaming dari PHP (music/stream.php,
# drive/download.php). Jika modul Apache mod_xsendfile belum terpasang &
# dikonfigurasi (LoadModule + XSendFile on + XSendFilePath), streaming dan
# download akan rusak/kosong. Default: TIDAK aktif (aman).
USE_XSENDFILE=false
if [ "$XSENDFILE_OVERRIDE" = "1" ]; then
    USE_XSENDFILE=true
elif [ "$XSENDFILE_OVERRIDE" != "0" ]; then
    if confirm "Aktifkan X-Sendfile di settings.php? (HANYA jika mod_xsendfile Apache sudah terpasang & terkonfigurasi)" N; then
        USE_XSENDFILE=true
    fi
fi

if $USE_XSENDFILE; then
    python3 - "auth/settings.php" <<'PYEOF' 2>/dev/null || {
import re, sys
path = sys.argv[1]
with open(path, 'r', encoding='utf-8') as f:
    c = f.read()
c = re.sub(r"define\('MEEL_USE_XSENDFILE', false\);", "define('MEEL_USE_XSENDFILE', true);", c, count=1)
with open(path, 'w', encoding='utf-8') as f:
    f.write(c)
print("patched via python3")
PYEOF
    # Fallback sed jika python3 tidak tersedia
    sed -i "s#define('MEEL_USE_XSENDFILE', false);#define('MEEL_USE_XSENDFILE', true);#" auth/settings.php
    }
    ok "MEEL_USE_XSENDFILE diaktifkan di auth/settings.php."
    warn "JANGAN LUPA konfigurasi Apache — tanpa mod_xsendfile, streaming akan rusak:"
    warn "  LoadModule xsendfile_module modules/mod_xsendfile.so"
    warn "  <IfModule xsendfile_module>"
    warn "      XSendFile on"
    warn "      XSendFilePath \"${HDD_BASE}\""
    warn "      XSendFilePath \"${PROJECT_ROOT}/data_drive\""
    warn "  </IfModule>"
    warn "  Restart Apache, lalu verifikasi: apachectl -M | grep xsend"
    warn "  (detail: docs/id/installation.md → 'Aktifkan mod_xsendfile')"
else
    warn "X-Sendfile TIDAK diaktifkan — streaming memakai PHP langsung (default aman)."
fi

# ─────────────────────────────────────────────────────────────────────────
# 4. Direktori storage runtime
# ─────────────────────────────────────────────────────────────────────────
step "4/7 — Buat direktori storage runtime"

mkdir -p "$HDD_BASE/video/upload/video" \
         "$HDD_BASE/video/upload/thumbnail" \
         "$HDD_BASE/music/upload/file" \
         "$HDD_BASE/music/upload/thumbnail" \
         "$HDD_BASE/books/upload/manga" \
         "$HDD_BASE/books/upload/pdf" \
         "$HDD_BASE/books/upload/thumbnail" \
         "$HDD_BASE/drive/public" \
         "$HDD_BASE/drive/private_admins"
ok "Storage media dibuat di: $HDD_BASE"
echo "    video : $HDD_BASE/video/upload/{video,thumbnail}"
echo "    music : $HDD_BASE/music/upload/{file,thumbnail}"
echo "    books : $HDD_BASE/books/upload/{manga,pdf,thumbnail}"
echo "    drive : $HDD_BASE/drive/{public,private_admins}"

mkdir -p data_drive/public data_drive/private_admins temp profile/upload
ok "Folder runtime lokal (data_drive, temp, profile/upload) siap."

# Jika HDD_BASE bukan folder lokal repo (mis. HDD eksternal / lokasi lain),
# arahkan <root>/{video,music,books}/upload ke storage terpusat via symlink
# SAAT deploy — TIDAK PERNAH commit symlink ini ke repo (.gitignore sudah
# menangani). .htaccess hardening folder upload ikut disalin ke target agar
# check_deploy tetap PASS. Placeholder (.gitkeep/.htaccess) TIDAK dianggap
# data, jadi fresh clone tetap diarahkan ke storage terpusat.
if [ "$HDD_BASE" != "$PROJECT_ROOT/video/upload" ]; then
    for m in video music books; do
        target="$HDD_BASE/${m}/upload"
        link="$PROJECT_ROOT/${m}/upload"
        if [ -L "$link" ]; then
            warn "${m}/upload sudah symlink — dilewati (hapus manual jika ingin diarahkan ulang)."
            [ -f "$target/.htaccess" ] || warn "  Target symlink tidak punya .htaccess hardening — salin manual dari repo."
        elif [ -d "$link" ] && [ -n "$(find "$link" -mindepth 1 -maxdepth 1 ! -name '.gitkeep' ! -name '.htaccess' -print -quit 2>/dev/null)" ]; then
            warn "${m}/upload adalah folder nyata berisi data — TIDAK diganti symlink otomatis. Pindahkan manual jika ingin pakai storage terpusat."
        else
            # Salin hardening ke target SEBELUM folder repo diganti symlink
            if [ -f "$link/.htaccess" ] && [ ! -f "$target/.htaccess" ]; then
                if cp "$link/.htaccess" "$target/.htaccess" 2>/dev/null; then
                    ok "Hardening .htaccess disalin ke ${target}"
                else
                    warn "Gagal menyalin .htaccess ke ${target} — salin manual dari repo sebelum produksi."
                fi
            fi
            rm -rf "$link"
            ln -s "$target" "$link"
            ok "Symlink dibuat: ${m}/upload → ${target}"
        fi
    done
fi

# Pratinjau publik Drive (mode HDD): MEEL_HDD_DRIVE turun otomatis dari
# MEEL_HDD_BASE (settings.example.php), jadi storage Drive selalu di
# <HDD_BASE>/drive/. URL web data_drive/public/<type>/... hanya resolve ke
# file fisik jika data_drive/public adalah symlink deploy ke storage tsb
# (rekomendasi docs 5a — jangan pernah commit symlink ini).
if [ "$HDD_BASE" != "$PROJECT_ROOT/data_drive" ]; then
    drive_target="$HDD_BASE/drive/public"
    drive_link="$PROJECT_ROOT/data_drive/public"
    if [ -L "$drive_link" ]; then
        warn "data_drive/public sudah symlink — dilewati (hapus manual jika ingin diarahkan ulang)."
    elif [ -d "$drive_link" ] && [ -n "$(find "$drive_link" -mindepth 1 -maxdepth 1 ! -name '.gitkeep' ! -name '.htaccess' -print -quit 2>/dev/null)" ]; then
        warn "data_drive/public berisi data — TIDAK diganti symlink otomatis. Pindahkan manual jika ingin pakai storage terpusat."
    else
        rm -rf "$drive_link"
        ln -s "$drive_target" "$drive_link"
        ok "Symlink dibuat: data_drive/public → ${drive_target}"
    fi
fi

# Kepemilikan & permission — best-effort, sesuaikan user web server Anda
WEB_USER="www-data"
if id "$WEB_USER" >/dev/null 2>&1 && $CAN_ELEVATE; then
    if confirm "Set ownership folder storage ke ${WEB_USER} (user Apache umum)?" Y; then
        $SUDO chown -R "$WEB_USER:$WEB_USER" data_drive temp profile/upload "$HDD_BASE" 2>/dev/null || \
            warn "Gagal chown — jalankan manual sesuai user web server Anda."
        $SUDO chmod -R 775 data_drive temp profile/upload "$HDD_BASE" 2>/dev/null || true
        ok "Ownership & permission diatur."
    fi
else
    warn "User '${WEB_USER}' tidak ditemukan atau tanpa sudo — atur ownership/permission storage manual sesuai web server Anda."
fi

# ─────────────────────────────────────────────────────────────────────────
# 5. Aktifkan mod_rewrite Apache + (opsional) VirtualHost
# ─────────────────────────────────────────────────────────────────────────
step "5/7 — Aktifkan mod_rewrite & VirtualHost Apache"

if command -v a2enmod >/dev/null 2>&1 && $CAN_ELEVATE; then
    if confirm "Aktifkan mod_rewrite & restart Apache sekarang?" Y; then
        $SUDO a2enmod rewrite >/dev/null 2>&1 || true
        $SUDO systemctl restart apache2 2>/dev/null || $SUDO service apache2 restart 2>/dev/null || \
            warn "Gagal restart Apache otomatis — restart manual."
        ok "mod_rewrite diaktifkan (jika Apache terpasang)."
    fi
else
    warn "a2enmod tidak ditemukan — jika pakai Apache, aktifkan mod_rewrite manual:"
    warn "  sudo a2enmod rewrite && sudo systemctl restart apache2"
    warn "Pastikan juga 'AllowOverride All' aktif di konfigurasi VirtualHost (lihat docs/id/installation.md)."
fi

# ─────────────────────────────────────────────────────────────────────────
# 5b. VirtualHost Apache (opsional — domain khusus untuk MEeL)
#     Di --yes mode: dilewati (akses via DocumentRoot/subfolder).
# ─────────────────────────────────────────────────────────────────────────
VHOST_DOMAIN=""
if [ -n "$VHOST_OVERRIDE" ]; then
    VHOST_DOMAIN="$VHOST_OVERRIDE"
elif ! $ASSUME_YES && confirm "Buat file VirtualHost Apache untuk MEeL (DocumentRoot: ${PROJECT_ROOT})?" N; then
    VHOST_DOMAIN="$(ask "Domain/ServerName (mis. meel.local)" "meel.local")"
fi

if [ -n "$VHOST_DOMAIN" ]; then
    VHOST_FILE="/etc/apache2/sites-available/meel.conf"
    VHOST_CONFIG="<VirtualHost *:80>
    ServerName ${VHOST_DOMAIN}
    DocumentRoot ${PROJECT_ROOT}
    <Directory ${PROJECT_ROOT}>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/meel-error.log
    CustomLog \${APACHE_LOG_DIR}/meel-access.log combined
</VirtualHost>"
    if command -v a2ensite >/dev/null 2>&1 && $CAN_ELEVATE; then
        printf '%s\n' "$VHOST_CONFIG" | $SUDO tee "$VHOST_FILE" >/dev/null
        $SUDO a2ensite meel >/dev/null 2>&1 || true
        $SUDO systemctl restart apache2 2>/dev/null || $SUDO service apache2 restart 2>/dev/null || \
            warn "Gagal restart Apache otomatis — restart manual."
        ok "VirtualHost '${VHOST_DOMAIN}' dibuat & diaktifkan (${VHOST_FILE})."
        warn "Untuk akses lokal, tambahkan ke /etc/hosts:  127.0.0.1 ${VHOST_DOMAIN}"
    else
        warn "a2ensite tidak tersedia / tanpa sudo — VirtualHost TIDAK ditulis otomatis. Salin manual:"
        echo ""
        printf '%s\n' "$VHOST_CONFIG"
        echo ""
        warn "Simpan ke ${VHOST_FILE}, lalu: sudo a2ensite meel && sudo systemctl restart apache2"
    fi
else
    ok "VirtualHost dilewati — akses via DocumentRoot/subfolder (mis. http://host/MEeL) memakai .htaccess langsung."
fi

# ─────────────────────────────────────────────────────────────────────────
# 6. Migration database
# ─────────────────────────────────────────────────────────────────────────
step "6/7 — Jalankan migration database"

if php database/migrate.php; then
    ok "Migration selesai (idempotent — aman diulang)."
else
    warn "Migration selesai dengan warning — cek output di atas."
fi

# ─────────────────────────────────────────────────────────────────────────
# 7. Verifikasi akhir via check_deploy.php
# ─────────────────────────────────────────────────────────────────────────
step "7/7 — Verifikasi deployment (tests/check_deploy.php)"

CHECK_OK=true
if [ -f "tests/check_deploy.php" ]; then
    if php tests/check_deploy.php; then
        ok "Deployment check: sehat (exit 0)."
    else
        CHECK_OK=false
        fail "Deployment check melaporkan FAIL — perbaiki sesuai output di atas, lalu jalankan ulang."
    fi
else
    warn "tests/check_deploy.php tidak ditemukan — lewati verifikasi otomatis."
fi

# ─────────────────────────────────────────────────────────────────────────
# Selesai
# ─────────────────────────────────────────────────────────────────────────
echo ""
echo "  Login default   : Admin / Admin#123  (${C_YELLOW}ganti segera setelah login pertama${C_RESET})"
echo "  Database         : ${DB_NAME}@${DB_HOST}"
echo "  Environment      : ${ENV_CHOICE} (APP_DEBUG=${DEBUG_CHOICE})"
echo "  Storage media     : ${HDD_BASE}"
echo ""
echo "  Jalankan cepat via PHP built-in server (untuk testing):"
echo "    php -S 0.0.0.0:8080"
echo ""
echo "  Untuk produksi, arahkan Apache VirtualHost ke: ${PROJECT_ROOT}"
echo "  (pastikan AllowOverride All + mod_rewrite aktif)"
echo ""

if $CHECK_OK; then
    echo -e "${C_GREEN}${C_BOLD}════════════════════════════════════════════════════${C_RESET}"
    echo -e "${C_GREEN}${C_BOLD} Instalasi selesai — deployment sehat!${C_RESET}"
    echo -e "${C_GREEN}${C_BOLD}════════════════════════════════════════════════════${C_RESET}"
    echo ""
    echo "  Ada WARN di verifikasi di atas? Lihat docs/id/installation.md atau"
    echo "  docs/id/troubleshooting.md untuk catatan."
    echo ""
    exit 0
else
    echo -e "${C_RED}${C_BOLD}════════════════════════════════════════════════════${C_RESET}"
    echo -e "${C_RED}${C_BOLD} Instalasi selesai TAPI verifikasi deployment GAGAL${C_RESET}"
    echo -e "${C_RED}${C_BOLD} — server BELUM siap dipakai.${C_RESET}"
    echo -e "${C_RED}${C_BOLD}════════════════════════════════════════════════════${C_RESET}"
    echo ""
    echo "  Perbaiki penyebab FAIL di output check_deploy di atas (lihat"
    echo "  docs/id/installation.md atau docs/id/troubleshooting.md), lalu"
    echo "  jalankan ulang verifikasi:"
    echo "    php tests/check_deploy.php"
    echo ""
    exit 1
fi
