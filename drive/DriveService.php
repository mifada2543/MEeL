<?php
final class DriveUserContext
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';

    public ?int $userId;
    public string $role;
    public string $username;
    public ?string $profile_picture = null;

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

    public function loadProfilePicture(mysqli $conn): void
    {
        if ($this->userId === null) {
            return;
        }

        $stmt = $conn->prepare('SELECT profile_picture FROM users WHERE id = ?');
        if (!$stmt) {
            return;
        }

        $stmt->bind_param('i', $this->userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->profile_picture = $row['profile_picture'] ?? null;
    }

    public function authorize(): void
    {
        if (!$this->isAllowedRole()) {
            $_GET['code'] = 'denied';
            die(include __DIR__ . '/../err/index.php');
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

    

    public static function defaultBasePath(?string $hddDriveOverride = null): string
    {
        return meel_drive_base_path($hddDriveOverride);
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

            
            
            $path = 'stream?file=' . rawurlencode($fileInfo->getFilename())
                . '&type=' . rawurlencode($type)
                . '&scope=' . rawurlencode($scope)
                . '&csrf_token=' . rawurlencode(get_csrf_token());

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

    

    public function upload(array $file, ?string $requestedScope, int $quotaLimitBytes = 0): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Berkas gagal diterima dari browser.');
        }

        $scope = $this->resolveUploadScope($requestedScope);
        $cleanName = $this->sanitizeFileName((string) ($file['name'] ?? ''));
        $type = $this->detectTypeFromFilename($cleanName);
        $directory = $this->getDirectoryForType($type, $scope);
        $this->ensureDirectoryExists($directory);

        $fileSize = (int) ($file['size'] ?? 0);
        $requiredBytes = max(100 * 1024 * 1024, $fileSize * 2);
        try {
            require_disk_space($requiredBytes, $directory, 'Drive storage');
        } catch (\RuntimeException $e) {
            throw new RuntimeException($e->getMessage());
        }

        $lockFp = null;
        if ($quotaLimitBytes > 0 && $this->user->isMember()) {
            $lockFp = @fopen($this->quotaLockPath(), 'c');
            if ($lockFp === false) {
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

        $finalName = $this->reserveUniqueFilename($directory, $cleanName);
        $destination = $directory . '/' . $finalName;

        if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
            @unlink($destination);
            if (is_resource($lockFp)) {
                @flock($lockFp, LOCK_UN);
                @fclose($lockFp);
            }
            throw new RuntimeException('Gagal mengunggah file. Cek izin folder penyimpanan.');
        }

        if (!$this->validateFileByMagicBytes($destination, $type)) {
            @unlink($destination);
            if (is_resource($lockFp)) {
                @flock($lockFp, LOCK_UN);
                @fclose($lockFp);
            }
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

        if ($safeScope === self::SCOPE_PRIVATE && !$this->verifyPrivateFileAccess($filePath)) {
            throw new RuntimeException('Anda tidak memiliki akses ke file ini.');
        }

        if (!unlink($filePath)) {
            throw new RuntimeException('Gagal menghapus file. Periksa izin folder.');
        }
    }

    private function verifyPrivateFileAccess(string $filePath): bool
    {
        $userPath = $this->privateRootForUser($this->user->username);

        $realPath = realpath($filePath);
        $realUserPath = realpath($userPath);

        if ($realPath === false || $realUserPath === false) {
            return false;
        }

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
            if (str_starts_with($header, "\x1A\x45\xDF\xA3")) {
                return true;
            }
            if (substr($header, 4, 4) === 'ftyp') {
                return true;
            }
            return false;
        }

        if ($detectedType === self::TYPE_AUDIO) {
            $audioSignatures = [0xFFFB, 0xFFA, 0x664C6143, 0x4F676753];
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
