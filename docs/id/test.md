# 🧪 Panduan Testing

**Versi Dokumen:** 1.0
**Tanggal:** 25 Juli 2026

---

## 📋 Ikhtisar

MEeL menggunakan pendekatan testing berlapis:

| Lapisan | Tools | Cakupan | Lokasi |
|---|---|---|---|
| **Unit Test** | PHPUnit 9.6 | Logika murni, DB di-mock | `tests/unit/` |
| **Integration Test** | PHPUnit 9.6 | Operasi DB real | `tests/integration/` |
| **Functional Test** | Custom PHP | Alur kerja aplikasi | `tests/functional_test.php` |
| **Security Test** | Custom PHP | Pemindaian kerentanan | `tests/security_test.php` |
| **Deployment Check** | Custom PHP | Verifikasi environment (storage, folder upload, .htaccess, mod_rewrite) | `tests/check_deploy.php` |
| **CI Pipeline** | GitHub Actions | Validasi otomatis | `.github/workflows/ci.yml` |

---

## 🧪 PHPUnit Test Suite (255 Unit + 79 Integration = 334 Test)

### Instalasi

PHPUnit terinstall sebagai dev dependency Composer:

```bash
cd /path/ke/MEeL
composer install --no-progress
```

### Menjalankan Test

```bash
# Jalankan semua test
vendor/bin/phpunit --no-coverage

# Hanya unit test
vendor/bin/phpunit --no-coverage --testsuite='MEeL Core Unit Tests'

# Hanya integration test
vendor/bin/phpunit --no-coverage --testsuite='MEeL Integration Tests'

# File test spesifik
vendor/bin/phpunit --no-coverage tests/unit/RateLimiterTest.php
```

### Log Test

Semua output test dicatat ke `logs/tests/`:

```
logs/tests/
└── .htaccess          # Deny all — dilindungi dari akses publik
```

### Arsitektur Test

#### Unit Test (`tests/unit/`)

| File | Test | Cakupan |
|---|---|---|
| `RateLimiterTest.php` | 11 | Admin bypass, role limits, blocking, cleanup, stats, fallback, independent keys |
| `HelpersTest.php` | 50 | format_bytes, time_ago, audio MIME, disk space, CSRF, dir_size, deteksi protokol (data provider) |
| `JapaneseTest.php` | 14 | Romaji conversion, analyzeJapaneseText, English translation (tanpa MeCab) |
| `GarbageCollectorTest.php` | 5 | Class existence, idempotency, graceful handling |
| `SearchEngineTest.php` | 5 | Parse params, sanitizer (`sanitizeQuery`), default values, constants |
| `MediaLibraryTest.php` | 11 | Logika pagination (pure math), BookRepository mock |
| `MediaInteractionTest.php` | 7 | Validasi input (ID/type tidak valid) |
| `MediaViewerTest.php` | 4 | Logika rendering / viewer media |
| `BootstrapTest.php` | 9 | Env detection, konfigurasi error reporting, timezone |
| `CssManifestTest.php` | 16 | Manifest CSS modul — semua entri ada, **semua** folder ter-precache oleh `SwPrecache`, entri precache resolve, versi SW deterministik |
| `SharedJsTest.php` | 7 | Shared JS harness — alur download-backup-codes |
| `StreamAuthTest.php` | 8 | Guard otorisasi endpoint stream |
| `SsrfGuardTest.php` | 76 | **Guard SSRF** — allowlist protokol, range IP private/publik (v4 & v6), penolakan record DNS campuran, denylist hostname, HTTP pinning (lihat bawah) |
| `DriveSecurityTest.php` | 13 | **Private Drive** — akses cross-user, path traversal, symlink escape, boundary realpath, kuota, reservasi nama atomik (lihat bawah) |
| `ValidatingProxyTest.php` | 19 | **Validating forward proxy** — probe CONNECT/GET nyata: target private ditolak (502), target publik di-tunnel, bind loopback-only (lihat bawah) |

#### Integration Test (`tests/integration/`)

| File | Test | Cakupan |
|---|---|---|
| `MediaInteractionIntegrationTest.php` | 24 | Like/dislike music+video, toggle, ownership check, count sync, guest denial |
| `ChessGameOverIntegrationTest.php` | 13 | Alur game-over catur dengan DB real |
| `ChessHelpersIntegrationTest.php` | 6 | Helper catur dengan DB real |
| `ChessRematchIntegrationTest.php` | 21 | Alur rematch catur dengan DB real |
| `GarbageCollectorChessRoomsIntegrationTest.php` | 15 | Garbage collection room catur dengan DB real |

### Test Helpers

| File | Fungsi |
|---|---|
| `tests/DbTestHelper.php` | Koneksi DB real dengan isolasi transaction rollback |
| `tests/bootstrap.php` | Autoloader, `$_SERVER` defaults, setup direktori temp |

### Konfigurasi PHPUnit (`phpunit.xml`)

```xml
<phpunit bootstrap="tests/bootstrap.php" colors="true" verbose="true">
    <testsuites>
        <testsuite name="MEeL Core Unit Tests">
            <directory>tests/unit</directory>
        </testsuite>
        <testsuite name="MEeL Integration Tests">
            <directory>tests/integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

### Test Regresi Keamanan (Guard SSRF, Validating Proxy, Private Drive)

Tiga kelas test khusus mencakup batas-batas keamanan dari security-hardening
pass. Jalankan bersama atau satu per satu:

```bash
# Semua test keamanan (SSRF + Drive + proxy) sekaligus
vendor/bin/phpunit --no-coverage --filter 'SsrfGuardTest|DriveSecurityTest|ValidatingProxyTest'

# Atau per file:
vendor/bin/phpunit --no-coverage tests/unit/SsrfGuardTest.php
vendor/bin/phpunit --no-coverage tests/unit/DriveSecurityTest.php
vendor/bin/phpunit --no-coverage tests/unit/ValidatingProxyTest.php
```

| File test | Yang diverifikasi (penolakan/penerimaan nyata, bukan sekadar keberadaan) |
|---|---|
| `SsrfGuardTest.php` | Range IPv4/IPv6 private ditolak, IP publik diizinkan, denylist hostname, protokol tak didukung, URL cacat, penolakan kredensial, DNS hostname→IP private, HTTP pinning (`pinHttpUrl`) |
| `DriveSecurityTest.php` | Download cross-user diblokir, path traversal diblokir, symlink escape diblokir, boundary prefix realpath, penegakan kuota (atomik), reservasi nama atomik |
| `ValidatingProxyTest.php` | **Menjalankan proses proxy nyata** dan mengirim CONNECT/absolute-URI asli: target private ditolak 502, target publik di-tunnel/relay, bind loopback-only, siklus hidup proses |

### Verifikasi satu-perintah (`scripts/verify_security.sh`)

Ketiga suite keamanan plus probe 403 Private Drive bisa dijalankan dengan satu
perintah:

```bash
scripts/verify_security.sh
# flag opsional: --url=https://staging.example/MEeL --deploy --hdd=... --no-color
```

Skrip menjalankan, secara berurutan:

1. **PHPUnit subset keamanan** — `SsrfGuardTest | DriveSecurityTest | ValidatingProxyTest`
2. **Security Test** — `php tests/security_test.php` (scan statis)
3. **Functional Test** — `php tests/functional_test.php` (verifikasi patch)
4. **Probe 403 Private Drive** — akses HTTP langsung ke `data_drive/private_admins/`
   dan path file tiruan harus sama-sama mengembalikan `403` (aturan deny `.htaccess`)
5. *Opsional* (`--deploy`) — `php tests/check_deploy.php --no-color [--hdd=...]`

Exit code `0` = semua suite lulus (warning diperbolehkan), `1` = minimal satu
gagal — ramah CI. Tool yang tidak ada (`vendor/bin/phpunit`) atau web server
yang tidak terjangkau menghasilkan warning dan **di-skip, bukan gagal**.
`security_test.php` dan `functional_test.php` mengembalikan `1` saat hanya ada
warning (Score A — mis. storage HDD belum ter-mount); skrip memetakannya ke
**WARN**, bukan FAIL, sesuai semantik mereka sendiri (mereka mengembalikan `2`
hanya untuk kegagalan nyata). Flag: `--url=…` (base URL probe),
`--skip-403`, `--deploy`, `--hdd=…`, `--no-color`. Membutuhkan `bash`,
CLI `php`, dan `curl`.

> 💡 Komponen yang sama berjalan terpisah di CI (lihat [CI Pipeline](#-ci-pipeline-github-actions));
> skrip ini adalah padanan sekali-jalan untuk lokal / pra-rilis.

**Catatan:**
- `SsrfGuardTest` dan `ValidatingProxyTest` punya kasus **bergantung jaringan**
  (DNS lookup nyata). Di lingkungan offline kasus itu berubah jadi
  `markTestSkipped()`; di CI (GitHub Actions) dan mesin dengan resolver,
  kasus itu berjalan sungguhan.
- `ValidatingProxyTest` butuh **PHP CLI + pcntl + stream_socket_server**
  (semuanya tersedia di setup XAMPP/LAMP default) karena ia men-spawn
  `validating_proxy_server.php` sebagai subproses.
- Pemeriksaan statis wiring untuk boundary yang sama ada di
  `tests/security_test.php` (TEST 13) dan `tests/functional_test.php` (patch
  verification), dijalankan dengan:

```bash
php tests/security_test.php
php tests/functional_test.php
```

### Menulis Test Baru

#### Template Unit Test

```php
<?php
use PHPUnit\Framework\TestCase;

class MyClassTest extends TestCase
{
    public function testSomething(): void
    {
        $result = myFunction('input');
        $this->assertSame('expected', $result);
    }
}
```

#### Template Integration Test (dengan DB)

```php
<?php
use PHPUnit\Framework\TestCase;

class MyIntegrationTest extends TestCase
{
    private DbTestHelper $dbHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbHelper = new DbTestHelper();
        $this->conn = $this->dbHelper->getConnection(); // auto-start transaction
    }

    protected function tearDown(): void
    {
        $this->dbHelper->rollback(); // undo semua perubahan
        $this->dbHelper->close();
        parent::tearDown();
    }
}
```

---

## 📋 Functional Test (`tests/functional_test.php`)

Skrip test kustom yang memvalidasi alur kerja aplikasi:

```bash
php tests/functional_test.php
```

**Mencakup:**
- Autentikasi user (login, register, logout)
- Pemutaran media (video, music)
- Operasi file (upload, delete)
- RBAC (role-based access)
- Rate limiting
- Pemeriksaan kesehatan sistem

## 🛡️ Security Test (`tests/security_test.php`)

Pemindaian kerentanan otomatis:

```bash
php tests/security_test.php
```

**Mencakup:**
- SQL Injection — semua prepared statement diverifikasi
- XSS — pengecekan encoding output
- CSRF — validasi token
- Path Traversal — pemaksaan basename()
- Command Injection — penggunaan escapeshellarg()
- File Upload — validasi magic bytes
- Session Security — cookie params, deteksi hijack

## 🚀 Deployment Check (`tests/check_deploy.php`)

Health-check cepat environment deployment — mencerminkan apa yang dicek security
test, tapi fokus pada infrastruktur:

```bash
php tests/check_deploy.php                          # cek lokal + probe HTTP otomatis
php tests/check_deploy.php --url=http://localhost/MEeL   # probe HTTP eksplisit
php tests/check_deploy.php --hdd=/tmp/meel-storage/media  # override MEEL_HDD_BASE (testing/CI)
php tests/check_deploy.php --no-color               # tanpa warna ANSI (untuk CI/log)
```

**Memverifikasi:**
- `MEEL_HDD_BASE` — terdefinisi, bukan placeholder, storage ter-mount & writable
- Folder upload + **subdirektori non-auto-create** — `{video,music,books}/upload`
  resolve ke storage; `music/upload/{file,thumbnail}` & `books/upload/{pdf,thumbnail}`
  wajib ada (modul music/books **tidak** membuatnya otomatis) — jika hilang: **FAIL**
- Hardening `.htaccess` folder upload — `php_flag engine off`, `ForceType`, `Options -Indexes`
- Guard portabilitas `data_drive/` — symlink deploy ke dalam `MEEL_HDD_DRIVE` =
  **PASS**, menunjuk ke luar = **WARN**
- PWA `mod_rewrite` — aturan `.htaccess` root + probe HTTP nyata ke `/sw.js`

Exit code `0` = sehat, `1` = minimal satu FAIL (ramah CI). Panduan storage
lengkap: [Instalasi §5a](installation.md).

---

## 🤖 CI Pipeline (GitHub Actions)

CI pipeline berjalan otomatis pada push/pull request:

```
PHP Syntax (8.1, 8.2, 8.3)
    ├── Functional Tests          → php tests/functional_test.php
    ├── Security Tests            → php tests/security_test.php
    ├── PHPUnit Unit Tests        → php vendor/bin/phpunit --no-coverage --testsuite='MEeL Core Unit Tests'
    ├── HTACCESS & Integrity      → kehadiran .htaccess + permission
    └── Deployment Check         → php tests/check_deploy.php --no-color --hdd=…
            └── CI Summary
```

### Bagaimana test regresi keamanan berjalan di CI

Ketiga kelas test keamanan (`SsrfGuardTest`, `DriveSecurityTest`,
`ValidatingProxyTest`) berada di `tests/unit/`, yang otomatis diambil oleh job
`phpunit-tests` (`php vendor/bin/phpunit --no-coverage
--testsuite='MEeL Core Unit Tests'`). Tidak perlu konfigurasi CI tambahan —
mereka bagian dari testsuite **MEeL Core Unit Tests**. Kasus yang bergantung
DNS berjalan sungguhan karena runner GitHub Actions punya resolver;
`ValidatingProxyTest` lulus karena runner punya PHP CLI dengan
pcntl/stream sockets.

> **CI hanya menjalankan suite unit.** Suite `tests/integration/` butuh
> database MySQL nyata dengan data seed (`DbTestHelper` terkoneksi ke
> `localhost` dengan ID user/media hardcoded) yang tidak tersedia di runner
> CI — menjalankannya di sana menghasilkan 70+ error koneksi `mysqli`.
> Jalankan secara lokal:
> `vendor/bin/phpunit --testsuite='MEeL Integration Tests'`.
>
> Test Jepang/romaji (`JapaneseTest`, sebagian `HelpersTest`) memanggil
> **MeCab** via `proc_open`. CI meng-install-nya (`apt-get install mecab
> mecab-ipadic-utf8`), dan saat MeCab tidak tersedia test terkait menurun ke
> `markTestSkipped()` via `meel_mecab_available()` — jadi mesin tanpa mecab
> tetap mendapat suite hijau, bukan failure.

Pemeriksaan **statis wiring** untuk boundary yang sama dijalankan oleh job
`security-tests` (TEST 13 di `tests/security_test.php`) dan job
`functional-tests` (patch verification). Job `htaccess-check` memverifikasi
kehadiran `.htaccess`; aturan deny level repo untuk `data_drive/private_admins`
ditegaskan oleh pemeriksaan `data_drive/.htaccess` di security test (lapisan 1),
plus `data_drive/private_admins/.htaccess` ter-track (`Require all denied`,
lapisan 2) yang diperiksa oleh `tests/security_test.php` (TEST 13) dan
`tests/check_deploy.php`.

Job `deploy-check` di `.github/workflows/ci.yml` menjalankan suite ini
(`php tests/check_deploy.php --no-color --hdd=...`) pada layout storage yang
disimulasikan, dan menyertakan probe 403 live opsional yang berjalan hanya
saat URL staging dikonfigurasi sebagai secret GitHub:

```yaml
# .github/workflows/ci.yml — job deploy-check, langkah opsional
- name: Verify Private Drive 403 (staging)
  if: secrets.STAGING_URL != ''
  run: |
    code=$(curl -s -o /dev/null -w '%{http_code}' \
      "$STAGING_URL/data_drive/private_admins/" || true)
    [ "$code" = "403" ] || { echo "Expected 403, got $code"; exit 1; }
  env:
    STAGING_URL: ${{ secrets.STAGING_URL }}
```

### Verifikasi Manual Private Drive 403 (saat deploy)

Setelah deploy (atau sebelum melepas perubahan), pastikan web server benar-benar
menolak akses langsung ke storage Drive private — file `.htaccess` saja bukan
bukti. Gunakan file probe sekali pakai, lalu hapus:

```bash
cd /path/ke/MEeL

# 1. Buat file probe di storage Drive private.
#    Lokasi storage mengikuti MEEL_HDD_DRIVE (auth/settings.php); fallback ke
#    folder nyata ter-track data_drive/private_admins bila konstanta tidak ada
#    (modul Drive tidak lagi memakai symlink untuk storage — lihat installation.md).
BASE=$(php -r 'require "modules/core/helpers.php"; echo meel_drive_base_path();')
TARGET="$BASE/private_admins"
mkdir -p "$TARGET/zz_403_probe/video"
echo 'PROBE' > "$TARGET/zz_403_probe/video/probe.mp4"

# 2. Akses langsung → HARUS 403 (bukan 200/404 dari web server)
curl -s -o /dev/null -w 'file: %{http_code}\n' \
  'http://localhost/MEeL/data_drive/private_admins/zz_403_probe/video/probe.mp4'

# 3. Directory listing → HARUS 403
curl -s -o /dev/null -w 'dir:  %{http_code}\n' \
  'http://localhost/MEeL/data_drive/private_admins/zz_403_probe/'

# 4. Bersihkan probe
rm -rf "$TARGET/zz_403_probe"
```

**Output yang diharapkan:** keduanya `file: 403` dan `dir: 403`. Hasil lain
(200, 301, 404 yang dilayani storage) berarti `AllowOverride` / `mod_rewrite`
tidak aktif untuk `data_drive/` — perbaiki `httpd.conf` (`AllowOverride All`)
sebelum rilis.

> 💡 Tip: pemeriksaan yang sama juga bagian dari `tests/check_deploy.php`
> (aturan deny `.htaccess`) — jalankan `php tests/check_deploy.php --url=http://localhost/MEeL`
> untuk laporan kesehatan deployment lengkap.

---

## 📊 Hasil Test Terkini

| Suite | Test | Lulus | Gagal | Skor |
|---|---|---|---|---|
| **PHPUnit (unit + integration)** | 334 | 334 | 0 | ✅ 100% |
| **PHPUnit subset keamanan** (SsrfGuard + Drive + Proxy) | 108 | 108 | 0 | ✅ 100% |
| **Functional Test** | 161 | 157 pass, 4 warn | 0 | ✅ 99/100 |
| **Security Test** | 92 | 87 pass, 5 warn | 0 | ✅ 97/100 |
| **Deployment Check** | 15 | 15 | 0 | ✅ 100% |

> Angka diambil dari security-hardening pass (Agustus 2026). Jalankan sendiri
> suite tersebut untuk kondisi terkini — pemeriksaan keamanan bisa memunculkan
> warning tambahan saat storage HDD (`MEEL_HDD_BASE` / storage belum
> ter-mount) belum disiapkan di lingkungan pengembangan. Folder media
> (`books/upload`, `music/upload`, `video/upload`) adalah folder nyata
> ter-track yang disajikan lewat endpoint PHP — tanpa symlink sama sekali
> (lihat [Installation §5a](installation.md#5a-media-storage-meel_hdd_base--endpoint-php--rewrite-tanpa-symlink)).

---

<div align="center">
  <sub><a href="index.md">← Kembali ke Indeks Dokumentasi</a></sub>
</div>
