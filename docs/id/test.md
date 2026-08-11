# 🧪 Panduan Testing

**Versi Dokumen:** 1.0  
**Tanggal:** 25 Juli 2026

---

## 📋 Ikhtisar

MEeL menggunakan pendekatan testing berlapis:

| Lapisan | Tools | Cakupan | Lokasi |
|---------|-------|---------|--------|
| **Unit Test** | PHPUnit 9.6 | Logika murni, DB di-mock | `tests/unit/` |
| **Integration Test** | PHPUnit 9.6 | Operasi DB real | `tests/integration/` |
| **Functional Test** | Custom PHP | Alur kerja aplikasi | `tests/functional_test.php` |
| **Security Test** | Custom PHP | Pemindaian kerentanan | `tests/security_test.php` |
| **Deployment Check** | Custom PHP | Verifikasi environment (storage, symlink, mod_rewrite) | `tests/check_deploy.php` |
| **CI Pipeline** | GitHub Actions | Validasi otomatis | `.github/workflows/ci.yml` |

---

## 🧪 PHPUnit Test Suite (125 Unit + 24 Integration = 149 Test)

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
|------|------|---------|
| `RateLimiterTest.php` | 11 | Admin bypass, role limits, blocking, cleanup, stats, fallback, independent keys |
| `HelpersTest.php` | 50 | format_bytes, time_ago, audio MIME, disk space, CSRF, dir_size, deteksi protokol (data provider) |
| `JapaneseTest.php` | 11 | Romaji conversion, analyzeJapaneseText, English translation (tanpa MeCab) |
| `GarbageCollectorTest.php` | 5 | Class existence, idempotency, graceful handling |
| `SearchEngineTest.php` | 5 | Parse params, sanitizer (`sanitizeQuery`), default values, constants |
| `MediaLibraryTest.php` | 11 | Logika pagination (pure math), BookRepository mock |
| `MediaInteractionTest.php` | 7 | Validasi input (ID/type tidak valid) |
| `BootstrapTest.php` | 9 | Env detection, konfigurasi error reporting, timezone |
| `CssManifestTest.php` | 16 | Manifest CSS modul — semua entri ada, **semua** folder ter-precache oleh `SwPrecache`, entri precache resolve, versi SW deterministik |

#### Integration Test (`tests/integration/`)

| File | Test | Cakupan |
|------|------|---------|
| `MediaInteractionIntegrationTest.php` | 24 | Like/dislike music+video, toggle, ownership check, count sync, guest denial |

### Test Helpers

| File | Fungsi |
|------|--------|
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
- Symlink upload — `books/upload`, `music/upload`, `video/upload` resolve ke storage
- Hardening `.htaccess` folder upload — `php_flag engine off`, `ForceType`, `Options -Indexes`
- PWA `mod_rewrite` — aturan `.htaccess` root + probe HTTP nyata ke `/sw.js`

Exit code `0` = sehat, `1` = minimal satu FAIL (ramah CI). Panduan storage
lengkap: [Instalasi §5a](installation.md).

---

## 🤖 CI Pipeline (GitHub Actions)

CI pipeline berjalan otomatis pada push/pull request:

```
PHP Syntax (8.1, 8.2, 8.3)
    ├── Functional Tests
    ├── Security Tests
    ├── PHPUnit Unit Tests
    └── HTACCESS & Integrity
            └── CI Summary
```

Lihat `.github/workflows/ci.yml` untuk konfigurasi lengkap.

---

## 📊 Hasil Test Terkini

| Suite | Test | Lulus | Gagal | Skor |
|-------|------|-------|-------|------|
| **PHPUnit Unit Tests** | 125 | 125 | 0 | ✅ 100% |
| **PHPUnit Integration Tests** | 24 | 24 | 0 | ✅ 100% |
| **Functional Test** | 144 | 138 pass, 6 warn | 0 | ✅ 98/100 |
| **Security Test** | 72 | 66 | 6*

> *Security test: 6 fail muncul saat storage HDD (`MEEL_HDD_BASE`, symlink
> `books/upload`/`music/upload`/`video/upload`) tidak ter-mount di lingkungan
> dev — direktori upload tidak ditemukan sehingga `.htaccess`-nya tidak
> terverifikasi. Di deployment dengan storage ter-mount, direktori upload
> punya `.htaccess` (php_flag engine off + ForceType + Options -Indexes).
> Jalankan ulang setelah storage aktif untuk konfirmasi 72/72.

---

<div align="center">
  <sub><a href="index.md">← Kembali ke Indeks Dokumentasi</a></sub>
</div>
