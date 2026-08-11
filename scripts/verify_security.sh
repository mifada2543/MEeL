#!/usr/bin/env bash
#
# scripts/verify_security.sh
# =============================================================================
# Verifikasi keamanan satu-perintah (one-command security verification)
#
# Menjalankan 3 suite keamanan + probe 403 Private Drive sekaligus:
#   1. PHPUnit subset keamanan  → SsrfGuardTest + DriveSecurityTest + ValidatingProxyTest
#   2. Security Test            → php tests/security_test.php    (scan statis)
#   3. Functional Test          → php tests/functional_test.php  (verifikasi patch)
#   4. Probe 403                → akses langsung data_drive/private_admins/
#                                (dir + file tiruan) HARUS HTTP 403
#   5. (opsional --deploy)      → php tests/check_deploy.php     (health deploy)
#
# Exit code:
#   0 = semua suite lulus (WARN/SKIP diperbolehkan)
#   1 = minimal satu suite GAGAL
#   2 = argumen tidak dikenal
#
# Usage:
#   scripts/verify_security.sh                                        # probe ke http://localhost/MEeL
#   scripts/verify_security.sh --url=https://staging.example/MEeL     # ganti base URL probe
#   scripts/verify_security.sh --skip-403                             # lewati probe HTTP (tanpa web server)
#   scripts/verify_security.sh --deploy --hdd=/tmp/meel-storage/media # sertakan Deployment Check
#   scripts/verify_security.sh --no-color                             # tanpa warna ANSI (CI/log)
# =============================================================================

set -u  # error jika variabel tidak terdefinisi (TANPA set -e — tiap langkah di-handle manual)

# ─── Lokasi & konfigurasi ────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_ROOT" || { echo "Gagal masuk ke direktori proyek: $PROJECT_ROOT" >&2; exit 1; }

URL_BASE="http://localhost/MEeL"
RUN_403=1
RUN_DEPLOY=0
USE_COLOR=1
HDD_ARG=""

# ─── Parsing argumen ─────────────────────────────────────────────────────────
for arg in "$@"; do
  case "$arg" in
    --url=*)      URL_BASE="${arg#*=}" ;;
    --skip-403)   RUN_403=0 ;;
    --deploy)     RUN_DEPLOY=1 ;;
    --hdd=*)      HDD_ARG="$arg" ;;
    --no-color)   USE_COLOR=0 ;;
    -h|--help)
      sed -n '2,26p' "$0" | sed 's/^# \{0,1\}//'
      exit 0
      ;;
    *)
      echo "Argumen tidak dikenal: $arg (lihat --help)" >&2
      exit 2
      ;;
  esac
done

# ─── State & warna ───────────────────────────────────────────────────────────
PASS=0
FAIL=0
WARN=0

C_GREEN=$'\033[32m'; C_RED=$'\033[31m'; C_YELLOW=$'\033[33m'; C_BOLD=$'\033[1m'; C_RESET=$'\033[0m'
if [ "$USE_COLOR" != "1" ]; then
  C_GREEN=""; C_RED=""; C_YELLOW=""; C_BOLD=""; C_RESET=""
fi

echo "${C_BOLD}===== MEeL Security Verification =====${C_RESET}"

# ─── Helper ──────────────────────────────────────────────────────────────────
# run_step <nama> <rc_warn> <perintah...>: jalankan, cetak hasil, catat status.
# rc_warn = exit code yang dianggap WARN (lulus dengan peringatan), -1 = tidak ada.
# security_test.php & functional_test.php mengembalikan 1 saat hanya ada warning
# (Score A / Health GOOD) dan 2 saat ada FAIL — jadi 1 harus dihitung WARN, bukan FAIL.
run_step() {
  local name="$1"; shift
  local rc_warn="$1"; shift
  echo ""
  echo "${C_BOLD}── $name ──${C_RESET}"
  "$@"
  local rc=$?
  if [ "$rc" -eq 0 ]; then
    echo "${C_GREEN}✔ PASS${C_RESET} — $name"
    PASS=$((PASS + 1))
  elif [ "$rc_warn" -ge 0 ] && [ "$rc" -eq "$rc_warn" ]; then
    echo "${C_YELLOW}⚠ WARN${C_RESET} — $name (exit $rc — lulus dengan peringatan)"
    WARN=$((WARN + 1))
  else
    echo "${C_RED}✘ FAIL${C_RESET} — $name (exit $rc)"
    FAIL=$((FAIL + 1))
  fi
  return "$rc"
}

# warn <pesan>: catat peringatan (tidak menggagalkan skrip).
warn() {
  echo "${C_YELLOW}⚠ WARN${C_RESET} — $1"
  WARN=$((WARN + 1))
}

# probe_403: akses langsung ke private_admins/ HARUS 403. 403 = PASS,
# server tidak terjangkau = WARN (probe dilewati), 404/301 = WARN (storage
# belum ter-mount ATAU AllowOverride/mod_rewrite tidak aktif), kode lain
# (mis. 200 yang melayani konten) = FAIL — itu artinya deny rule tidak aktif.
probe_403() {
  local base="$URL_BASE/data_drive/private_admins"
  echo ""
  echo "${C_BOLD}── Probe 403: $base/ ──${C_RESET}"
  local dir_code file_code
  dir_code=$(curl -s -o /dev/null -m 10 -w '%{http_code}' "$base/" 2>/dev/null) || dir_code="000"
  file_code=$(curl -s -o /dev/null -m 10 -w '%{http_code}' "$base/zz_probe_security.mp4" 2>/dev/null) || file_code="000"

  if [ "$dir_code" = "000" ] && [ "$file_code" = "000" ]; then
    warn "Web server tidak dapat dijangkau di $URL_BASE — probe dilewati (pakai --url=... atau --skip-403)"
  elif [ "$dir_code" = "403" ] && [ "$file_code" = "403" ]; then
    echo "${C_GREEN}✔ PASS${C_RESET} — akses langsung ditolak (dir: 403, file: 403)"
    PASS=$((PASS + 1))
  elif [ "$dir_code" = "404" ] || [ "$file_code" = "404" ] \
    || [ "$dir_code" = "301" ] || [ "$dir_code" = "302" ]; then
    warn "HTTP dir=$dir_code file=$file_code — storage belum ter-mount ATAU AllowOverride/mod_rewrite tidak aktif (lihat docs/en/test.md)"
  else
    echo "${C_RED}✘ FAIL${C_RESET} — HTTP dir=$dir_code file=$file_code — akses langsung TIDAK diblokir!"
    FAIL=$((FAIL + 1))
  fi
}

# ─── Langkah 1: PHPUnit subset keamanan ──────────────────────────────────────
if [ ! -x vendor/bin/phpunit ] && [ ! -f vendor/bin/phpunit ]; then
  warn "vendor/bin/phpunit tidak ditemukan — jalankan 'composer install' dulu (langkah dilewati)"
else
  run_step "PHPUnit keamanan (SsrfGuardTest | DriveSecurityTest | ValidatingProxyTest)" -1 \
    php vendor/bin/phpunit --no-coverage --filter 'SsrfGuardTest|DriveSecurityTest|ValidatingProxyTest'
fi

# ─── Langkah 2: Security Test statis ─────────────────────────────────────────
if [ ! -f tests/security_test.php ]; then
  warn "tests/security_test.php tidak ditemukan (langkah dilewati)"
else
  run_step "Security Test (tests/security_test.php)" 1 php tests/security_test.php
fi

# ─── Langkah 3: Functional Test (verifikasi patch) ───────────────────────────
if [ ! -f tests/functional_test.php ]; then
  warn "tests/functional_test.php tidak ditemukan (langkah dilewati)"
else
  run_step "Functional Test (tests/functional_test.php)" 1 php tests/functional_test.php
fi

# ─── Langkah 4: Probe 403 Private Drive ──────────────────────────────────────
if [ "$RUN_403" = "1" ]; then
  probe_403
fi

# ─── Langkah 5 (opsional): Deployment Check ──────────────────────────────────
if [ "$RUN_DEPLOY" = "1" ]; then
  if [ ! -f tests/check_deploy.php ]; then
    warn "tests/check_deploy.php tidak ditemukan (langkah dilewati)"
  else
    # shellcheck disable=SC2086 — HDD_ARG sengaja tidak dikutip agar kosong = tanpa argumen
    run_step "Deployment Check (tests/check_deploy.php)" -1 php tests/check_deploy.php --no-color $HDD_ARG
  fi
fi

# ─── Ringkasan & exit code ───────────────────────────────────────────────────
echo ""
echo "${C_BOLD}===== Ringkasan =====${C_RESET}"
echo "  PASS : $PASS"
echo "  WARN : $WARN"
echo "  FAIL : $FAIL"
if [ "$FAIL" -eq 0 ]; then
  echo "${C_GREEN}✔ VERIFIKASI LULUS${C_RESET}"
  exit 0
fi
echo "${C_RED}✘ VERIFIKASI GAGAL — periksa langkah yang gagal di atas${C_RESET}"
exit 1
