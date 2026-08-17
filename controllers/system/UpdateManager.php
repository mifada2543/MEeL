<?php
/* UpdateManager Menangani aksi admin: simpan sidebar_settings & CRUD update entry. */
class UpdateManager
{
    private mysqli $db;
    private array  $flash = [];

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /* ─── Entry point ─── */

    public function handle(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
        if (($_SESSION['role'] ?? '') !== 'admin')  return;

        // Verifikasi token
        if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
            $this->setFlash('error', 'CSRF Token tidak valid.');
            $this->redirect();
            return;
        }

        $action = $_POST['action'] ?? '';

        match ($action) {
            'sidebar'       => $this->saveSidebar(),
            'update'        => $this->saveUpdate(),
            'edit_update'   => $this->saveEditUpdate(),   // Aksi Edit Baru
            'delete_update' => $this->deleteUpdate(),     // Aksi Hapus Baru
            default         => null,
        };
    }

    /* ─── Aksi: sidebar ─── */

    private function saveSidebar(): void
    {
        $imp = $this->clean($_POST['important']    ?? '');
        $ann = $this->clean($_POST['announcement'] ?? '');

        $stmt = $this->db->prepare(
            "UPDATE sidebar_settings SET important_content = ?, announcement_content = ? WHERE id = 1"
        );
        $stmt->bind_param('ss', $imp, $ann);
        $ok = $stmt->execute();
        $stmt->close();

        $this->setFlash(
            $ok ? 'success' : 'error',
            $ok ? 'Sidebar berhasil diperbarui.'
                : 'Gagal memperbarui sidebar: ' . $this->db->error
        );

        $this->redirect();
    }

    /* ─── Aksi: tambah update entry (Create) ─── */

    private function saveUpdate(): void
    {
        $version = $this->clean($_POST['version'] ?? '');
        $content = $this->clean($_POST['content'] ?? '');
        $date    = $_POST['created_at'] ?? '';

        if ($version === '' || $content === '') {
            $this->setFlash('error', 'Versi dan konten tidak boleh kosong.');
            $this->redirect();
            return;
        }

        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }

        // PREPARED STATEMENT untuk keamanan SQL Injection
        $stmt = $this->db->prepare(
            "INSERT INTO updates (version, content, created_at) VALUES (?, ?, ?)"
        );
        $stmt->bind_param('sss', $version, $content, $date);
        $ok = $stmt->execute();
        $stmt->close();

        $this->setFlash(
            $ok ? 'success' : 'error',
            $ok ? "Update v{$version} berhasil ditambahkan."
                : 'Gagal menyimpan update: ' . $this->db->error
        );

        $this->redirect();
    }

    /* ─── Aksi: edit update entry (Update) ─── */

    private function saveEditUpdate(): void
    {
        $id      = (int)($_POST['id'] ?? 0);
        $version = $this->clean($_POST['version'] ?? '');
        $content = $this->clean($_POST['content'] ?? '');
        $date    = $_POST['created_at'] ?? '';

        if ($id <= 0 || $version === '' || $content === '') {
            $this->setFlash('error', 'Data tidak valid atau ada form yang kosong.');
            $this->redirect();
            return;
        }

        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }

        // PREPARED STATEMENT untuk Update Data
        $stmt = $this->db->prepare(
            "UPDATE updates SET version = ?, content = ?, created_at = ? WHERE id = ?"
        );
        $stmt->bind_param('sssi', $version, $content, $date, $id);
        $ok = $stmt->execute();
        $stmt->close();

        $this->setFlash(
            $ok ? 'success' : 'error',
            $ok ? "Update v{$version} berhasil diperbarui."
                : 'Gagal memperbarui update: ' . $this->db->error
        );

        $this->redirect();
    }

    /* ─── Aksi: hapus update entry (Delete) ─── */

    private function deleteUpdate(): void
    {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            $this->setFlash('error', 'ID tidak valid.');
            $this->redirect();
            return;
        }

        // PREPARED STATEMENT untuk Delete Data secara aman
        $stmt = $this->db->prepare("DELETE FROM updates WHERE id = ?");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $stmt->close();

        $this->setFlash(
            $ok ? 'success' : 'error',
            $ok ? "Catatan update berhasil dihapus."
                : 'Gagal menghapus update: ' . $this->db->error
        );

        $this->redirect();
    }

    /* ─── Query helpers ─── */

    public function getSidebarData(): array
    {
        $result = $this->db->query("SELECT * FROM sidebar_settings WHERE id = 1 LIMIT 1");
        return $result ? ($result->fetch_assoc() ?? []) : [];
    }

    public function getUpdates(): array
    {
        $result = $this->db->query("SELECT * FROM updates ORDER BY created_at DESC, id DESC");
        if (!$result) return [];
        $rows = [];
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        return $rows;
    }

    /* ─── Flash message ─── */

    public function getFlash(): array
    {
        if ($this->flash) return $this->flash;

        if (!empty($_SESSION['flash'])) {
            $flash = $_SESSION['flash'];
            unset($_SESSION['flash']);
            return $flash;
        }

        return [];
    }

    private function setFlash(string $type, string $msg): void
    {
        $this->flash               = ['type' => $type, 'msg' => $msg];
        $_SESSION['flash']         = $this->flash;
    }

    /* ─── Utilities ─── */

    private function clean(string $val): string
    {

        return htmlspecialchars(trim($val), ENT_QUOTES, 'UTF-8');
    }

    private function redirect(): void
    {

        header("Location: " . base_url('/update'));
        exit;
    }
}
