<?php
final class DriveUserContext
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';

    public ?int $userId;
    public string $role;
    public string $username;

    private function __construct(?int $userId, string $role, string $username)
    {
        $this->userId = $userId;
        $this->role = $role;
        $this->username = $username;
    }

    public static function fromSession(array $session): self
    {
        return new self(
            isset($session['user_id']) ? (int) $session['user_id'] : null,
            (string) ($session['role'] ?? 'guest'),
            (string) ($session['username'] ?? 'User')
        );
    }

    public function authorize(): void
    {
        if (!$this->isAllowedRole()) {
            die(include __DIR__ . '/../err/denied.php');
        }
    }

    public function isAllowedRole(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_MEMBER], true);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isMember(): bool
    {
        return $this->role === self::ROLE_MEMBER;
    }
}

final class DriveStorage
{
    public const SCOPE_PUBLIC = 'public';
    public const SCOPE_PRIVATE = 'private';

    private const TYPE_VIDEO = 'video';
    private const TYPE_AUDIO = 'audio';
    private const TYPE_DOCUMENT = 'dokumen';

    private const VIDEO_EXTENSIONS = ['mp4', 'mkv', 'mov', 'webm', 'avi'];
    private const AUDIO_EXTENSIONS = ['mp3', 'flac', 'ogg', 'wav', 'm4a'];
    private const ALLOWED_TYPES = [
        self::TYPE_VIDEO,
        self::TYPE_AUDIO,
        self::TYPE_DOCUMENT,
    ];

    private string $basePath;
    private DriveUserContext $user;
    private string $webBasePath;

    public function __construct(
        string $basePath,
        DriveUserContext $user,
        string $webBasePath = '../data_drive'
    ) {
        $this->basePath = $basePath;
        $this->user = $user;
        $this->webBasePath = $webBasePath;
    }

    public function normalizeScope(?string $scope): string
    {
        return $scope === self::SCOPE_PRIVATE ? self::SCOPE_PRIVATE : self::SCOPE_PUBLIC;
    }

    public function normalizeType(?string $type): string
    {
        return in_array($type, self::ALLOWED_TYPES, true) ? $type : self::TYPE_DOCUMENT;
    }

    public function resolveUploadScope(?string $requestedScope): string
    {
        $scope = $this->normalizeScope($requestedScope);

        if ($scope === self::SCOPE_PUBLIC && !$this->user->isAdmin()) {
            return self::SCOPE_PRIVATE;
        }

        return $scope;
    }

    public function listFilesByType(string $type, string $scope): array
    {
        $directory = $this->getDirectoryForType($type, $scope);
        $webDirectory = $this->getWebDirectoryForType($type, $scope);
        $this->ensureDirectoryExists($directory);

        $files = [];
        $iterator = new DirectoryIterator($directory);

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDot() || !$fileInfo->isFile()) {
                continue;
            }

            if ($scope === self::SCOPE_PRIVATE) {
                // Private files TIDAK boleh direferensikan lewat path web
                // langsung (subtree storage di-deny web server). URL pratinjau
                // harus lewat endpoint stream ber-otorisasi.
                $path = 'stream.php?file=' . rawurlencode($fileInfo->getFilename())
                    . '&type=' . rawurlencode($type)
                    . '&scope=private'
                    . '&csrf_token=' . rawurlencode(get_csrf_token());
            } else {
                $path = $webDirectory . '/' . rawurlencode($fileInfo->getFilename());
            }

            $files[] = [
                'name' => $fileInfo->getFilename(),
                'size' => $fileInfo->getSize(),
                'time' => $fileInfo->getMTime(),
                'path' => $path,
                'ext' => strtolower($fileInfo->getExtension()),
            ];
        }

        usort(
            $files,
            static fn(array $left, array $right): int => $right['time'] <=> $left['time']
        );

        return $files;
    }

    /**
     * @param array $file Entry $_FILES untuk satu file
     * @param string|null $requestedScope Scope yang diminta (public/private)
     * @param int $quotaLimitBytes Batas kuota member (0 = tanpa penegakan kuota).
     *        Penegakan kuota dilakukan ATOMIK di dalam lock per-user sehingga
     *        upload berbarengan tidak bisa melewati kuota secara kolektif.
     * @throws RuntimeException 'quota_full' saat kuota terlampaui
     */
    public function upload(array $file, ?string $requestedScope, int $quotaLimitBytes = 0): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Berkas gagal diterima dari browser.');
        }

        // PRE-FLIGHT: Cek ruang disk drive — minimal 100MB free
        $scope = $this->resolveUploadScope($requestedScope);
        $cleanName = $this->sanitizeFileName((string) ($file['name'] ?? ''));
        $type = $this->detectTypeFromFilename($cleanName);
        $directory = $this->getDirectoryForType($type, $scope);
        $this->ensureDirectoryExists($directory);

        $fileSize = (int) ($file['size'] ?? 0);
        $requiredBytes = max(100 * 1024 * 1024, $fileSize * 2); // minimal 100MB atau 2x ukuran file
        try {
            require_disk_space($requiredBytes, $directory, 'Drive storage');
        } catch (\RuntimeException $e) {
            throw new RuntimeException($e->getMessage());
        }

        // ─── Kuota ATOMIK ───
        // Lock per-user menjadikan rangkaian "cek usage → cek kuota → tulis
        // file" satu unit tak-terputus; usage dihitung segar (bypass cache 5
        // menit) sehingga angka yang dipakai akurat.
        $lockFp = null;
        if ($quotaLimitBytes > 0 && $this->user->isMember()) {
            $lockFp = @fopen($this->quotaLockPath(), 'c');
            if ($lockFp === false) {
                // Lock tidak bisa dibuat (temp dir bermasalah) → tetap tegakkan
                // kuota secara non-atomik. Jangan pernah melewati pengecekan
                // kuota sama sekali untuk member.
                $currentUsage = dir_size($this->privateRootForUser($this->user->username), 0);
                if (($currentUsage + $fileSize) > $quotaLimitBytes) {
                    throw new RuntimeException('quota_full');
                }
            } else {
                @flock($lockFp, LOCK_EX);
                $currentUsage = dir_size($this->privateRootForUser($this->user->username), 0);
                if (($currentUsage + $fileSize) > $quotaLimitBytes) {
                    @flock($lockFp, LOCK_UN);
                    @fclose($lockFp);
                    throw new RuntimeException('quota_full');
                }
            }
        }

        // ─── Reservasi nama ATOMIK ───
        // fopen('x') = O_CREAT|O_EXCL mengklaim nama file secara atomik, jadi
        // dua upload berbarengan dengan nama sama tidak bisa sama-sama menang
        // (menutup TOCTOU antara cek file_exists dan move_uploaded_file).
        $finalName = $this->reserveUniqueFilename($directory, $cleanName);
        $destination = $directory . '/' . $finalName;

        if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
            @unlink($destination); // buang reservasi kosong
            if (is_resource($lockFp)) { @flock($lockFp, LOCK_UN); @fclose($lockFp); }
            throw new RuntimeException('Gagal mengunggah file. Cek izin folder penyimpanan.');
        }

        // Validasi file type menggunakan magic bytes
        if (!$this->validateFileByMagicBytes($destination, $type)) {
            @unlink($destination);
            if (is_resource($lockFp)) { @flock($lockFp, LOCK_UN); @fclose($lockFp); }
            throw new RuntimeException('Tipe file tidak sesuai dengan extension yang diberikan.');
        }

        if (is_resource($lockFp)) {
            @flock($lockFp, LOCK_UN);
            @fclose($lockFp);
        }

        return [
            'scope' => $scope,
            'type' => $type,
            'filename' => $finalName,
            'path' => $destination,
        ];
    }

    public function getFileForDownload(?string $filename, ?string $type, ?string $scope): array
    {
        $safeType = $this->normalizeType($type);
        $safeScope = $this->normalizeScope($scope);
        $safeFilename = $this->sanitizeRequestedFilename($filename);
        $filePath = $this->buildFilePath($safeType, $safeScope, $safeFilename);

        if (!is_file($filePath)) {
            throw new RuntimeException('File fisik tidak ditemukan di server.');
        }

        // Validasi access control untuk private files
        if ($safeScope === self::SCOPE_PRIVATE && !$this->verifyPrivateFileAccess($filePath)) {
            throw new RuntimeException('Anda tidak memiliki akses ke file ini.');
        }

        return [
            'name' => $safeFilename,
            'path' => $filePath,
            'size' => filesize($filePath),
        ];
    }

    public function delete(?string $filename, ?string $type, ?string $scope): void
    {
        $safeType = $this->normalizeType($type);
        $safeScope = $this->normalizeScope($scope);
        $safeFilename = $this->sanitizeRequestedFilename($filename);
        $filePath = $this->buildFilePath($safeType, $safeScope, $safeFilename, true);

        if (!is_file($filePath)) {
            throw new RuntimeException('File tidak ditemukan.');
        }

        if ($safeScope === self::SCOPE_PUBLIC && !$this->user->isAdmin()) {
            throw new RuntimeException('Hanya Admin yang dapat menghapus file di Public Space.');
        }

        // Validasi access control untuk private files - HANYA OWNER
        if ($safeScope === self::SCOPE_PRIVATE && !$this->verifyPrivateFileAccess($filePath)) {
            throw new RuntimeException('Anda tidak memiliki akses ke file ini.');
        }

        if (!unlink($filePath)) {
            throw new RuntimeException('Gagal menghapus file. Periksa izin folder.');
        }
    }

    /* Verifikasi user dapat mengakses private file */
    private function verifyPrivateFileAccess(string $filePath): bool
    {
        $userPath = $this->privateRootForUser($this->user->username);

        // Normalize paths untuk comparison
        $realPath = realpath($filePath);
        $realUserPath = realpath($userPath);

        if ($realPath === false || $realUserPath === false) {
            return false;
        }

        // Ensure file is within user's private directory
        return $realPath === $realUserPath
            || str_starts_with($realPath, $realUserPath . DIRECTORY_SEPARATOR);
    }

    private function buildFilePath(string $type, string $scope, string $filename, bool $forDelete = false): string
    {
        if ($scope === self::SCOPE_PUBLIC) {
            if ($forDelete && !$this->user->isAdmin()) {
                throw new RuntimeException('Hanya Admin yang dapat menghapus file di Public Space.');
            }

            return $this->publicRoot() . '/' . $type . '/' . $filename;
        }

        return $this->privateRootForUser($this->user->username) . '/' . $type . '/' . $filename;
    }

    private function getDirectoryForType(string $type, string $scope): string
    {
        $safeType = $this->normalizeType($type);
        $safeScope = $this->normalizeScope($scope);

        if ($safeScope === self::SCOPE_PRIVATE) {
            return $this->privateRootForUser($this->user->username) . '/' . $safeType;
        }

        return $this->publicRoot() . '/' . $safeType;
    }

    private function getWebDirectoryForType(string $type, string $scope): string
    {
        $safeType = $this->normalizeType($type);
        $safeScope = $this->normalizeScope($scope);

        if ($safeScope === self::SCOPE_PRIVATE) {
            return $this->webBasePath . '/private_admins/' . rawurlencode($this->user->username) . '/' . $safeType;
        }

        return $this->webBasePath . '/public/' . $safeType;
    }

    private function publicRoot(): string
    {
        return $this->basePath . '/public';
    }

    private function privateRootForUser(string $username): string
    {
        return $this->basePath . '/private_admins/' . $username;
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Folder penyimpanan gagal dibuat.');
        }
    }

    /* Validasi file type menggunakan magic bytes */
    private function validateFileByMagicBytes(string $filePath, string $detectedType): bool
    {
        if (!is_file($filePath)) {
            return false;
        }
        if ($detectedType === self::TYPE_DOCUMENT) {
            return true;
        }

        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return false;
        }
        $header = fread($handle, 16);
        fclose($handle);

        if ($detectedType === self::TYPE_VIDEO) {
            if (str_starts_with($header, "\x1A\x45\xDF\xA3")) { // WebM / MKV
                return true;
            }
            if (substr($header, 4, 4) === 'ftyp') { // MP4 / MOV
                return true;
            }
            return false;
        }

        // Validasi Audio
        if ($detectedType === self::TYPE_AUDIO) {
            $audioSignatures = [0xFFFB, 0xFFA, 0x664C6143, 0x4F676753]; // MP3, FLAC, OGG
            $fileSignature = unpack('N', substr($header, 0, 4))[1] ?? 0;
            return in_array($fileSignature, $audioSignatures);
        }

        return true;
    }

    private function detectTypeFromFilename(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($extension, self::VIDEO_EXTENSIONS, true)) {
            return self::TYPE_VIDEO;
        }

        if (in_array($extension, self::AUDIO_EXTENSIONS, true)) {
            return self::TYPE_AUDIO;
        }

        return self::TYPE_DOCUMENT;
    }

    private function sanitizeFileName(string $filename): string
    {
        $baseName = basename($filename);
        $cleanName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $baseName) ?: 'file';

        return trim($cleanName, '._') !== '' ? $cleanName : 'file';
    }

    private function sanitizeRequestedFilename(?string $filename): string
    {
        $safeFilename = basename((string) $filename);

        if ($safeFilename === '') {
            throw new RuntimeException('Parameter file tidak lengkap.');
        }

        return $safeFilename;
    }

    /**
     * Klaim nama file unik secara atomik. fopen('x') gagal bila nama sudah
     * dipegang proses lain, jadi tidak ada jendela TOCTOU.
     */
    private function reserveUniqueFilename(string $directory, string $filename): string
    {
        $candidate = $filename;
        $nameOnly = pathinfo($filename, PATHINFO_FILENAME);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $counter = 1;

        while (true) {
            $destination = $directory . '/' . $candidate;
            $reservation = @fopen($destination, 'x');
            if ($reservation !== false) {
                fclose($reservation);
                return $candidate;
            }
            if ($counter > 1000) {
                // Gagal berulang bukan karena nama bentrok (mis. direktori
                // tidak writable) — berhenti agar tidak loop tanpa batas.
                throw new RuntimeException('Gagal membuat nama file unik. Cek izin folder penyimpanan.');
            }
            $suffix = '_(' . $counter . ')';
            $candidate = $extension !== ''
                ? $nameOnly . $suffix . '.' . $extension
                : $nameOnly . $suffix;
            $counter++;
        }
    }

    private function quotaLockPath(): string
    {
        // File ini berada di <root>/drive/, jadi dirname(__DIR__) = root proyek.
        return dirname(__DIR__) . '/temp/drive_quota_' . md5($this->user->username) . '.lock';
    }
}
final class DriveViewRenderer
{
    public function renderFileGrid(array $files, string $accent, string $icon, string $type, string $scope): void
    {
        $csrfToken = get_csrf_token();

        include __DIR__ . '/templates/file_grid.php';
    }
}
