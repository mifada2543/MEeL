# 🧪 Testing Guide

**Document Version:** 1.0  
**Date:** July 25, 2026

---

## 📋 Overview

MEeL uses a multi-layered testing approach:

| Layer | Tool | Scope | Location |
|-------|------|-------|----------|
| **Unit Tests** | PHPUnit 9.6 | Pure logic, mocked DB | `tests/unit/` |
| **Integration Tests** | PHPUnit 9.6 | Real DB operations | `tests/integration/` |
| **Functional Tests** | Custom PHP | Application workflows | `tests/functional_test.php` |
| **Security Tests** | Custom PHP | Vulnerability scanning | `tests/security_test.php` |
| **Deployment Check** | Custom PHP | Environment verification (storage, symlink, mod_rewrite) | `tests/check_deploy.php` |
| **CI Pipeline** | GitHub Actions | Automated validation | `.github/workflows/ci.yml` |

---

## 🧪 PHPUnit Test Suite (125 Unit + 24 Integration = 149 Tests)

### Installation

PHPUnit is installed as a Composer dev dependency:

```bash
cd /path/to/MEeL
composer install --no-progress
```

### Running Tests

```bash
# Run all tests
vendor/bin/phpunit --no-coverage

# Run only unit tests
vendor/bin/phpunit --no-coverage --testsuite='MEeL Core Unit Tests'

# Run only integration tests
vendor/bin/phpunit --no-coverage --testsuite='MEeL Integration Tests'

# Run specific test file
vendor/bin/phpunit --no-coverage tests/unit/RateLimiterTest.php
```

### Test Logs

All test output is logged to `logs/tests/`:

```
logs/tests/
└── .htaccess          # Deny all — protected from public access
```

### Test Architecture

#### Unit Tests (`tests/unit/`)

| File | Tests | Coverage |
|------|-------|----------|
| `RateLimiterTest.php` | 11 | Admin bypass, role limits, blocking, cleanup, stats, fallback, independent keys |
| `HelpersTest.php` | 50 | format_bytes, time_ago, audio MIME types, disk space, CSRF, dir_size, protocol detection (data providers) |
| `JapaneseTest.php` | 11 | Romaji conversion, analyzeJapaneseText, English translation (MeCab-optional) |
| `GarbageCollectorTest.php` | 5 | Class existence, idempotency, graceful handling |
| `SearchEngineTest.php` | 5 | Parse params, sanitizer (`sanitizeQuery`), default values, constants |
| `MediaLibraryTest.php` | 11 | Pagination logic (pure math), BookRepository mock |
| `MediaInteractionTest.php` | 7 | Input validation (invalid IDs, types) |
| `BootstrapTest.php` | 9 | Env detection, error reporting config, timezone |
| `CssManifestTest.php` | 16 | CSS module manifests — every entry exists, **all** folders pre-cached by `SwPrecache`, precache entries resolve, deterministic SW version |

#### Integration Tests (`tests/integration/`)

| File | Tests | Coverage |
|------|-------|----------|
| `MediaInteractionIntegrationTest.php` | 24 | Like/dislike music+video, toggle, ownership check, count sync, guest denial |

### Test Helpers

| File | Purpose |
|------|---------|
| `tests/DbTestHelper.php` | Real DB connection with transaction rollback isolation |
| `tests/bootstrap.php` | Autoloader, `$_SERVER` defaults, temp directory setup |

### PHPUnit Configuration (`phpunit.xml`)

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

### Writing New Tests

#### Unit Test Template

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

#### Integration Test Template (with DB)

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
        $this->dbHelper->rollback(); // undo all changes
        $this->dbHelper->close();
        parent::tearDown();
    }
}
```

---

## 📋 Functional Tests (`tests/functional_test.php`)

Custom test script that validates application workflows:

```bash
php tests/functional_test.php
```

**Covers:**
- User authentication (login, register, logout)
- Media playback (video, music)
- File operations (upload, delete)
- RBAC (role-based access)
- Rate limiting
- System health checks

## 🛡️ Security Tests (`tests/security_test.php`)

Automated vulnerability scanning:

```bash
php tests/security_test.php
```

**Covers:**
- SQL Injection — all prepared statements verified
- XSS — output encoding checks
- CSRF — token validation
- Path Traversal — basename() enforcement
- Command Injection — escapeshellarg() usage
- File Upload — magic bytes validation
- Session Security — cookie params, hijack detection

## 🚀 Deployment Check (`tests/check_deploy.php`)

Quick health-check of the deployment environment — mirrors what the security
test checks, but focused on infrastructure:

```bash
php tests/check_deploy.php                          # local check + auto HTTP probe
php tests/check_deploy.php --url=http://localhost/MEeL   # explicit HTTP probe
php tests/check_deploy.php --hdd=/tmp/meel-storage/media  # override MEEL_HDD_BASE (testing/CI)
php tests/check_deploy.php --no-color               # no ANSI colors (for CI/logs)
```

**Verifies:**
- `MEEL_HDD_BASE` — defined, not a placeholder, storage mounted & writable
- Upload symlinks — `books/upload`, `music/upload`, `video/upload` resolve to storage
- `.htaccess` hardening in upload dirs — `php_flag engine off`, `ForceType`, `Options -Indexes`
- PWA `mod_rewrite` — root `.htaccess` rule + real HTTP probe of `/sw.js`

Exit code `0` = healthy, `1` = at least one FAIL (CI-friendly). Full storage
guide: [Installation §5a](installation.md).

---

## 🤖 CI Pipeline (GitHub Actions)

The CI pipeline runs automatically on push/pull request:

```
PHP Syntax (8.1, 8.2, 8.3)
    ├── Functional Tests
    ├── Security Tests
    ├── PHPUnit Unit Tests
    └── HTACCESS & Integrity
            └── CI Summary
```

See `.github/workflows/ci.yml` for full configuration.

---

## 📊 Current Test Results

| Suite | Tests | Pass | Fail | Score |
|-------|-------|------|------|-------|
| **PHPUnit Unit Tests** | 125 | 125 | 0 | ✅ 100% |
| **PHPUnit Integration Tests** | 24 | 24 | 0 | ✅ 100% |
| **Functional Test** | 144 | 138 pass, 6 warn | 0 | ✅ 98/100 |
| **Security Test** | 72 | 66 | 6*

> *Security test: 6 fail muncul saat HDD storage (`MEEL_HDD_BASE`, symlink
> `books/upload`/`music/upload`/`video/upload`) tidak ter-mount di lingkungan
> dev — direktori upload tidak ditemukan sehingga `.htaccess`-nya tidak
> terverifikasi. Di deployment dengan storage ter-mount, direktori upload
> punya `.htaccess` (php_flag engine off + ForceType + Options -Indexes).
> Jalankan ulang setelah storage aktif untuk konfirmasi 72/72.

---

<div align="center">
  <sub><a href="index.md">← Back to Documentation Index</a></sub>
</div>
