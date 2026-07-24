# 👨‍💻 Development & Contribution Guide

Guide for developers who want to contribute or understand coding standards in MEeL-HUB.

---

## 📋 Table of Contents

- [Development Environment](#development-environment)
- [Coding Standards](#coding-standards)
- [Database Structure](#database-structure)
- [Coding Conventions](#coding-conventions)
- [Testing](#testing)
- [Pull Request Guide](#pull-request-guide)
- [Troubleshooting Development](#troubleshooting-development)

---

## Development Environment

### Setup

1. **Install dependencies:**
```bash
git clone https://github.com/mifada2543/MEeL.git
cd MEeL
cp auth/config.example.php auth/config.php
```

2. **Enable debug mode:**
```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

3. **Disable HDD check for development:**
```php
// modules/helpers.php - comment out:
// if (!is_dir($hdd_check_path)) { ... }
```

4. **Recommended tools:**
- Editor: VS Code with PHP Intelephense
- Database: MySQL Workbench / phpMyAdmin
- API Testing: Postman / Insomnia
- Browser: Chrome DevTools for HTMX debugging

---

## Coding Standards

### PHP

#### 1. PSR-12 Basic Coding Style

```php
<?php
declare(strict_types=1);

namespace MEeL\Modules;

class MediaLibrary
{
    private mysqli $conn;
    
    public function __construct(mysqli $connection)
    {
        $this->conn = $connection;
    }
}
```

#### 2. Prepared Statements REQUIRED

```php
// ✅ CORRECT - Prepared Statement
$stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();

// ❌ WRONG - Don't use query() with concatenation
// $result = $conn->query("SELECT * FROM users WHERE username = '$username'");
```

#### 3. Class Naming Convention

```php
// Class: PascalCase
class MediaLibrary {}
class BookRepository {}

// Methods: camelCase
public function getVideos();
public function toggleLike();
```

#### 4. Type Hints

Properties and constructor parameters **must** have type hints (PHP 7.4+):

```php
// ✅ CORRECT
private \mysqli $conn;
private int $user_id;
private string $username;

public function __construct(\mysqli $db, int $user_id, string $username) { }
```

### JavaScript

```javascript
// ✅ CORRECT - Named functions
function handleSearch(event) {
    const query = event.target.value;
}

// Event listeners preferred over inline HTML
document.getElementById('search-input').addEventListener('input', handleSearch);

// HTMX event monitoring
document.body.addEventListener('htmx:afterOnLoad', function(evt) {
    lucide.createIcons();
});
```

### CSS

Project uses **TailwindCSS (self-hosted, purged)** for main styling with minimal custom CSS for special effects.

---

## Database Structure

### Entity Relationship Diagram

```
users ──1:N── video
users ──1:N── music
users ──1:N── books
users ──1:N── comments
users ──1:N── playlists
users ──1:N── interactions
users ──1:N── upload_queue
users ──1:N── drive_files

comments ──1:N── comments (parent_id, nested)
playlists ──1:N── playlist_tracks
music ──1:N── playlist_tracks
```

### Key Relationships

| Table | Foreign Key | References | Type |
|-------|-------------|-----------|------|
| `video` | `user_id` | `users.id` | CASCADE |
| `music` | `user_id` | `users.id` | CASCADE |
| `books` | `user_id` | `users.id` | SET NULL |
| `comments` | `user_id` | `users.id` | CASCADE |
| `comments` | `parent_id` | `comments.id` | CASCADE |
| `interactions` | `user_id` | `users.id` | NO ACTION |
| `playlists` | `user_id` | `users.id` | CASCADE |
| `playlist_tracks` | `playlist_id` | `playlists.id` | CASCADE |
| `playlist_tracks` | `music_id` | `music.id` | CASCADE |

---

## Coding Conventions

### Security

1. **Always Prepared Statement** — No SQL concat
2. **Always htmlspecialchars()** — For output
3. **CSRF Token** — Every POST form required
4. **Role Check** — Before sensitive actions
5. **Input Validation** — Type, size, file extension

### File Structure per Module

```
[module]/
├── index.php          # Catalog / listing
├── watch.php          # Player / detail
├── upload.php         # Upload form
├── search_[module].php  # Search (HTMX)
├── load_more.php      # Pagination (HTMX)
└── [module]_item.php  # Card component
```

---

## Testing

### Manual Testing Checklist

**Frontend:**
- [ ] No errors in browser console
- [ ] HTMX request/response working
- [ ] Mobile responsive (min width 320px)
- [ ] Dark mode consistent
- [ ] All buttons and links functional

**Backend:**
- [ ] Prepared statements not erroring
- [ ] CSRF validation working
- [ ] Role-based access working
- [ ] File upload validation working
- [ ] Error handling showing appropriate messages

---

## Pull Request Guide

### 📜 License & Contribution

This project is licensed under **GNU General Public License v3.0 (GPLv3)**. See the [`LICENSE`](../../LICENSE) file for full text.

> **By submitting a Pull Request, you agree that your contributions will be licensed under GPL v3.**

#### Copyright Header on New Files

```php
/**
 * MEeL - Media Hub Platform
 *
 * @copyright Copyright (C) 2026 Mifada
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3
 */
```

### Contribution Checklist

- [ ] Use **Prepared Statements** for all database queries
- [ ] Sanitize POST/GET input
- [ ] CSRF token on every new POST form
- [ ] Role check before sensitive operations
- [ ] Update `update.php` with changelog
- [ ] Every new file has **GPL v3 copyright header**
- [ ] Changes clearly marked with **modification notice**

### Git Commit Convention

```
[type]: Short description (max 50 chars)

- Detailed changes if needed
- Multi-line allowed
```

**Types:**
| Type | Usage |
|------|------------|
| `feat` | New feature |
| `fix` | Bug fix |
| `security` | Security fix |
| `perf` | Performance optimization |
| `refactor` | Code refactoring |
| `docs` | Documentation |
| `style` | CSS/UI fix |

### Branch Strategy

```
main (stable)
  └── Experiment (development branch)
       ├── feature/[feature-name]
       └── fix/[fix-name]
```

---

## Resource for Developers

### Key Files to Understand

| File | Reason |
|------|--------|
| `auth/config.php` | Configuration entry point |
| `auth/auth.php` | Authentication middleware |
| `modules/helpers.php` | Global utility functions |
| `modules/Transcoder.php` | Main engine (most complex) |
| `modules/Uploader.php` | File upload process |
| `modules/System.php` | Queue & monitoring |
| `partials/ui.php` | Overlay UI system (JS heavy) |

### Key Processes

1. **Upload Pipeline** — Uploader → FFmpeg → HDD → DB
2. **Download Pipeline** — URL → yt-dlp → FFmpeg → HDD → DB
3. **Auth Flow** — Login → Session → RBAC → Activity Log
4. **HTMX Flow** — Event → Request → Server → Response → DOM swap

---

<div align="center">
  <sub><a href="index.md">← Back to Documentation Index</a></sub>
</div>
