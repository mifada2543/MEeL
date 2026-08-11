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

## 🧪 PHPUnit Test Suite (255 Unit + 79 Integration = 334 Tests)

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
| `JapaneseTest.php` | 14 | Romaji conversion, analyzeJapaneseText, English translation (MeCab-optional) |
| `GarbageCollectorTest.php` | 5 | Class existence, idempotency, graceful handling |
| `SearchEngineTest.php` | 5 | Parse params, sanitizer (`sanitizeQuery`), default values, constants |
| `MediaLibraryTest.php` | 11 | Pagination logic (pure math), BookRepository mock |
| `MediaInteractionTest.php` | 7 | Input validation (invalid IDs, types) |
| `MediaViewerTest.php` | 4 | Media rendering / viewer logic |
| `BootstrapTest.php` | 9 | Env detection, error reporting config, timezone |
| `CssManifestTest.php` | 16 | CSS module manifests — every entry exists, **all** folders pre-cached by `SwPrecache`, precache entries resolve, deterministic SW version |
| `SharedJsTest.php` | 7 | Shared JS harness — download-backup-codes flow |
| `StreamAuthTest.php` | 8 | Stream endpoint authorization guards |
| `SsrfGuardTest.php` | 76 | **SSRF guard** — protocol allowlist, private/public IP ranges (v4 & v6), DNS mixed-record rejection, hostname denylist, HTTP pinning (see below) |
| `DriveSecurityTest.php` | 13 | **Private Drive** — cross-user access, path traversal, symlink escape, realpath boundary, quota, atomic filename reservation (see below) |
| `ValidatingProxyTest.php` | 19 | **Validating forward proxy** — real CONNECT/GET probes: private targets refused (502), public targets tunneled, loopback-only bind (see below) |

#### Integration Tests (`tests/integration/`)

| File | Tests | Coverage |
|------|-------|----------|
| `MediaInteractionIntegrationTest.php` | 24 | Like/dislike music+video, toggle, ownership check, count sync, guest denial |
| `ChessGameOverIntegrationTest.php` | 13 | Chess game-over flow against real DB |
| `ChessHelpersIntegrationTest.php` | 6 | Chess helper functions with real DB |
| `ChessRematchIntegrationTest.php` | 21 | Chess rematch flow against real DB |
| `GarbageCollectorChessRoomsIntegrationTest.php` | 15 | Chess room garbage collection with real DB |

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

### Security Regression Tests (SSRF Guard, Validating Proxy, Private Drive)

Three dedicated test classes cover the security boundaries implemented in the
security-hardening pass. Run them together or individually:

```bash
# Semua test keamanan (SSRF + Drive + proxy) sekaligus
vendor/bin/phpunit --no-coverage --filter 'SsrfGuardTest|DriveSecurityTest|ValidatingProxyTest'

# Atau per file:
vendor/bin/phpunit --no-coverage tests/unit/SsrfGuardTest.php
vendor/bin/phpunit --no-coverage tests/unit/DriveSecurityTest.php
vendor/bin/phpunit --no-coverage tests/unit/ValidatingProxyTest.php
```

| Test file | What it verifies (real rejection/acceptance, not just existence) |
|-----------|------------------------------------------------------------------|
| `SsrfGuardTest.php` | Private IPv4/IPv6 ranges rejected, public IPs allowed, hostname denylist, unsupported protocols, malformed URLs, credentials rejection, hostname→private-IP DNS rejection, HTTP pinning (`pinHttpUrl`) |
| `DriveSecurityTest.php` | Cross-user download blocked, path traversal blocked, symlink escape blocked, realpath prefix boundary, quota enforcement (atomic), atomic filename reservation |
| `ValidatingProxyTest.php` | **Spawns a real proxy process** and sends real CONNECT/absolute-URI requests: private targets refused with 502, public targets tunneled/relayed, loopback-only bind, process lifecycle |

### One-command verification (`scripts/verify_security.sh`)

All three security suites plus the Private Drive 403 probe can be run with a
single command:

```bash
scripts/verify_security.sh
# optional flags: --url=https://staging.example/MEeL --deploy --hdd=... --no-color
```

The script runs, in order:

1. **PHPUnit security subset** — `SsrfGuardTest | DriveSecurityTest | ValidatingProxyTest`
2. **Security Test** — `php tests/security_test.php` (static scan)
3. **Functional Test** — `php tests/functional_test.php` (patch verification)
4. **Private Drive 403 probe** — direct HTTP access to `data_drive/private_admins/`
   and a throwaway file path must both return `403` (the `.htaccess` deny rule)
5. *Optional* (`--deploy`) — `php tests/check_deploy.php --no-color [--hdd=...]`

Exit code `0` = all suites passed (warnings allowed), `1` = at least one
failure — CI-friendly. Missing tools (`vendor/bin/phpunit`) or an unreachable
web server produce warnings and are **skipped, not failed**. `security_test.php`
and `functional_test.php` return `1` when there are only warnings (Score A —
e.g. HDD storage not mounted); the script maps that to **WARN**, not FAIL,
matching their own semantics (they return `2` only for real failures). Flags:
`--url=…` (probe base URL), `--skip-403`, `--deploy`, `--hdd=…`,
`--no-color`. Requires `bash`, `php` CLI and `curl`.

> 💡 The same components run separately in CI (see [CI Pipeline](#-ci-pipeline-github-actions));
> this script is the local / pre-release one-shot equivalent.

**Notes:**
- `SsrfGuardTest` and `ValidatingProxyTest` contain **network-dependent cases**
  (real DNS lookups). In an offline environment they degrade to
  `markTestSkipped()`; in CI (GitHub Actions) and on a machine with a resolver
  they run for real.
- `ValidatingProxyTest` requires **PHP CLI + pcntl + stream_socket_server**
  (all present on the default XAMPP/LAMP setup) because it spawns
  `validating_proxy_server.php` as a subprocess.
- The static wiring checks for the same boundaries live in
  `tests/security_test.php` (TEST 13) and `tests/functional_test.php` (patch
  verification), run via:

```bash
php tests/security_test.php
php tests/functional_test.php
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
    ├── Functional Tests          → php tests/functional_test.php
    ├── Security Tests            → php tests/security_test.php
    ├── PHPUnit Unit Tests        → php vendor/bin/phpunit --no-coverage
    ├── HTACCESS & Integrity      → .htaccess presence + permissions
    └── Deployment Check         → php tests/check_deploy.php --no-color --hdd=…
            └── CI Summary
```

### How the security regression tests run in CI

All three security test classes (`SsrfGuardTest`, `DriveSecurityTest`,
`ValidatingProxyTest`) live in `tests/unit/`, which is picked up by the
`phpunit-tests` job (`php vendor/bin/phpunit --no-coverage`). No extra CI
configuration is needed — they are part of the **MEeL Core Unit Tests**
testsuite. The DNS-dependent cases run for real because GitHub Actions runners
have a resolver; `ValidatingProxyTest` passes because the runner has PHP CLI
with pcntl/stream sockets.

The **static wiring checks** for the same boundaries are exercised by the
`security-tests` job (TEST 13 in `tests/security_test.php`) and the
`functional-tests` job (patch verification). The `htaccess-check` job verifies
`.htaccess` presence; the repo-level deny rule for `data_drive/private_admins`
is asserted by the security test's `data_drive/.htaccess` check.

The `deploy-check` job in `.github/workflows/ci.yml` runs this suite
(`php tests/check_deploy.php --no-color --hdd=...`) on a simulated storage
layout, and includes an optional live-403 probe that runs only when a staging
URL is configured as a GitHub secret:

```yaml
# .github/workflows/ci.yml — job deploy-check, optional step
- name: Verify Private Drive 403 (staging)
  if: secrets.STAGING_URL != ''
  run: |
    code=$(curl -s -o /dev/null -w '%{http_code}' \
      "$STAGING_URL/data_drive/private_admins/" || true)
    [ "$code" = "403" ] || { echo "Expected 403, got $code"; exit 1; }
  env:
    STAGING_URL: ${{ secrets.STAGING_URL }}
```

### Verifying Private Drive 403 manually (deploy-time)

After deploying (or before releasing a change), confirm the web server really
rejects direct access to private Drive storage — a `.htaccess` file alone is
not proof. Use a throwaway probe file, then remove it:

```bash
cd /path/to/MEeL

# 1. Buat file probe di storage private Drive (symlink ke media storage)
TARGET=$(readlink -f data_drive/private_admins)
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

**Expected output:** both `file: 403` and `dir: 403`. Anything else (200, 301,
404 served by the storage) means `AllowOverride` / `mod_rewrite` is not active
for `data_drive/` — fix `httpd.conf` (`AllowOverride All`) before release.

> 💡 Tip: this exact check is also part of `tests/check_deploy.php`
> (`.htaccess` deny rule) — run `php tests/check_deploy.php --url=http://localhost/MEeL`
> for the full deployment health report.

---

## 📊 Current Test Results

| Suite | Tests | Pass | Fail | Score |
|-------|-------|------|------|-------|
| **PHPUnit (unit + integration)** | 334 | 334 | 0 | ✅ 100% |
| **PHPUnit security subset** (SsrfGuard + Drive + Proxy) | 108 | 108 | 0 | ✅ 100% |
| **Functional Test** | 161 | 157 pass, 4 warn | 0 | ✅ 99/100 |
| **Security Test** | 92 | 87 pass, 5 warn | 0 | ✅ 97/100 |
| **Deployment Check** | 15 | 15 | 0 | ✅ 100% |

> Numbers are from the hardening pass (August 2026). Run the suites yourself
> to get the current state — the security checks may additionally produce
> warnings when HDD storage (`MEEL_HDD_BASE` / upload symlinks) is not
> mounted in a development environment.

---

<div align="center">
  <sub><a href="index.md">← Back to Documentation Index</a></sub>
</div>
