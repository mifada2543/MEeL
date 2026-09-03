<?php
use PHPUnit\Framework\TestCase;




require_once MEEL_ROOT . '/drive/DriveService.php';



class DriveSecurityTest extends TestCase
{
    private string $baseDir = '';

    protected function setUp(): void
    {
        $_SESSION['csrf_token'] = 'test_token_123';
        $this->baseDir = MEEL_ROOT . '/temp/drive_test_' . bin2hex(random_bytes(4));
        @mkdir($this->baseDir . '/private_admins/alice/video', 0755, true);
        @mkdir($this->baseDir . '/private_admins/bobby/video', 0755, true);
        @mkdir($this->baseDir . '/public/video', 0755, true);
        @mkdir($this->baseDir . '/private_admins/alice/audio', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->baseDir);
    }

    private function user(array $session): DriveUserContext
    {
        return DriveUserContext::fromSession($session);
    }

    private function storage(string $username = 'alice'): DriveStorage
    {
        $role = ($username === 'alice' || $username === 'bobby') ? 'member' : 'guest';
        return new DriveStorage(
            $this->baseDir,
            $this->user(['user_id' => 1, 'role' => $role, 'username' => $username])
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }

    

    public function testOwnerCanDownloadOwnPrivateFile(): void
    {
        file_put_contents($this->baseDir . '/private_admins/alice/video/x.mp4', 'data');
        $file = $this->storage('alice')->getFileForDownload('x.mp4', 'video', 'private');
        $this->assertSame('x.mp4', $file['name']);
        $this->assertSame($this->baseDir . '/private_admins/alice/video/x.mp4', $file['path']);
        $this->assertSame(4, $file['size']);
    }

    public function testOtherUserCannotDownloadPrivateFile(): void
    {
        file_put_contents($this->baseDir . '/private_admins/alice/video/x.mp4', 'data');
        $this->expectException(RuntimeException::class);
        $this->storage('bobby')->getFileForDownload('x.mp4', 'video', 'private');
    }

    public function testGuestCannotDownloadPrivateFile(): void
    {
        file_put_contents($this->baseDir . '/private_admins/alice/video/x.mp4', 'data');
        $this->expectException(RuntimeException::class);
        $this->storage('guest')->getFileForDownload('x.mp4', 'video', 'private');
    }

    public function testAnyAuthedUserCanDownloadPublicFile(): void
    {
        file_put_contents($this->baseDir . '/public/video/p.mp4', 'public-data');
        $file = $this->storage('bobby')->getFileForDownload('p.mp4', 'video', 'public');
        $this->assertSame('p.mp4', $file['name']);
    }

    

    public function testPathTraversalIsBlocked(): void
    {
        $this->expectException(RuntimeException::class);
        $this->storage('alice')->getFileForDownload('../../../etc/passwd', 'video', 'private');
    }

    public function testPathTraversalViaEncodedSlashIsBlocked(): void
    {
        $this->expectException(RuntimeException::class);
        $this->storage('alice')->getFileForDownload('..%2F..%2Fsecret', 'video', 'private');
    }

    public function testUsernamePrefixCannotBypassRealpathBoundary(): void
    {
        
        
        file_put_contents($this->baseDir . '/private_admins/bobby/video/secret.mp4', 'bob-data');
        $this->expectException(RuntimeException::class);
        $this->storage('alice')->getFileForDownload('secret.mp4', 'video', 'private');
    }

    public function testEmptyFilenameIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->storage('alice')->getFileForDownload('', 'video', 'private');
    }

    

    public function testPrivateListingUsesAuthenticatedStreamEndpoint(): void
    {
        file_put_contents($this->baseDir . '/private_admins/alice/video/x.mp4', 'data');
        $files = $this->storage('alice')->listFilesByType('video', 'private');
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('stream?file=' . rawurlencode('x.mp4'), $files[0]['path']);
        $this->assertStringContainsString('scope=private', $files[0]['path']);
        $this->assertStringContainsString('csrf_token=test_token_123', $files[0]['path']);
        
        $this->assertStringNotContainsString('private_admins', $files[0]['path']);
        $this->assertStringNotContainsString('/x.mp4', str_replace('stream?file=', '', $files[0]['path']));
    }

    public function testPublicListingUsesStreamEndpoint(): void
    {
        file_put_contents($this->baseDir . '/public/video/p.mp4', 'data');
        $files = $this->storage('alice')->listFilesByType('video', 'public');
        $this->assertCount(1, $files);
        $this->assertStringContainsString('stream?file=', $files[0]['path']);
        $this->assertStringContainsString('scope=public', $files[0]['path']);
        $this->assertStringContainsString('csrf_token=', $files[0]['path']);
    }

    

    public function testQuotaEnforcementRejectsOverLimitUpload(): void
    {
        
        file_put_contents($this->baseDir . '/private_admins/alice/audio/old.mp3', str_repeat('A', 1024));
        @mkdir($this->baseDir . '/private_admins/alice/dokumen', 0755, true);

        $file = [
            'error'    => UPLOAD_ERR_OK,
            'name'     => 'big.mp4',
            'tmp_name' => '/tmp/definitely-not-an-upload',
            'size'     => 100000,
            'type'     => 'video/mp4',
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('quota_full');
        $this->storage('alice')->upload($file, 'private', 1000); 
    }

    public function testQuotaDoesNotApplyToAdmin(): void
    {
        @mkdir($this->baseDir . '/private_admins/admin', 0755, true);
        $storage = new DriveStorage(
            $this->baseDir,
            $this->user(['user_id' => 2, 'role' => 'admin', 'username' => 'admin'])
        );
        $file = [
            'error'    => UPLOAD_ERR_OK,
            'name'     => 'big.mp4',
            'tmp_name' => '/tmp/definitely-not-an-upload',
            'size'     => 100000,
            'type'     => 'video/mp4',
        ];
        
        
        try {
            $storage->upload($file, 'private', 1);
        } catch (RuntimeException $e) {
            $this->assertNotSame('quota_full', $e->getMessage());
        }
        $this->addToAssertionCount(1);
    }

    

    public function testPrivateStorageDeniedByWebServerConfig(): void
    {
        
        
        $parent = MEEL_ROOT . '/data_drive/.htaccess';
        $this->assertFileExists($parent);
        $parentContent = (string) file_get_contents($parent);
        $this->assertStringContainsString('private_admins', $parentContent);
        $this->assertStringContainsString('[F', $parentContent);

        
        
        $nested = MEEL_ROOT . '/data_drive/private_admins/.htaccess';
        if (is_file($nested)) {
            $content = (string) file_get_contents($nested);
            $this->assertStringContainsString('Require all denied', $content);
            $this->assertStringContainsString('Options -Indexes', $content);
        }
        $this->addToAssertionCount(1);
    }
}
