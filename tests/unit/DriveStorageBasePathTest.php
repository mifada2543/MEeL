<?php
use PHPUnit\Framework\TestCase;


require_once MEEL_ROOT . '/drive/DriveService.php';



class DriveStorageBasePathTest extends TestCase
{
    private function hddValue(): string
    {
        return '/media/someuser/MEeL/media/drive/';
    }

    

    public function testDefaultBasePathUsesMeelHddDriveWhenDefined(): void
    {
        $this->assertSame(
            '/media/someuser/MEeL/media/drive',
            DriveStorage::defaultBasePath($this->hddValue())
        );
    }

    public function testDefaultBasePathStripsTrailingSlash(): void
    {
        $this->assertSame('/x/drive', DriveStorage::defaultBasePath('/x/drive/'));
        $this->assertSame('/x/drive', DriveStorage::defaultBasePath('/x/drive//'));
    }

    public function testDefaultBasePathKeepsPathWithoutTrailingSlash(): void
    {
        $this->assertSame('/x/drive', DriveStorage::defaultBasePath('/x/drive'));
    }

    

    public function testDefaultBasePathFallsBackToLocalDataDrive(): void
    {
        $this->assertSame(
            MEEL_ROOT . '/data_drive',
            DriveStorage::defaultBasePath('') 
        );
    }

    public function testDefaultBasePathWithoutOverrideUsesCurrentEnvironment(): void
    {
        
        
        
        $expected = defined('MEEL_HDD_DRIVE') && (string) MEEL_HDD_DRIVE !== ''
            ? rtrim((string) MEEL_HDD_DRIVE, '/\\')
            : MEEL_ROOT . '/data_drive';
        $this->assertSame($expected, DriveStorage::defaultBasePath());
    }

    

    public function testHelperMatchesDriveStorageResolution(): void
    {
        $this->assertSame(
            DriveStorage::defaultBasePath($this->hddValue()),
            meel_drive_base_path($this->hddValue())
        );
        $this->assertSame(
            DriveStorage::defaultBasePath(''),
            meel_drive_base_path('')
        );
        $this->assertSame(
            DriveStorage::defaultBasePath(),
            meel_drive_base_path()
        );
    }

    public function testHelperStripsTrailingSlash(): void
    {
        $this->assertSame('/media/u/MEeL/drive', meel_drive_base_path('/media/u/MEeL/drive/'));
    }

    

    public function testEnsureDirectoryCreatesRealFoldersUnderFallbackBase(): void
    {
        
        
        
        $base = MEEL_ROOT . '/temp/drive_base_test_' . bin2hex(random_bytes(4));
        $user = DriveUserContext::fromSession(['user_id' => 1, 'role' => 'admin', 'username' => 'admin']);
        $storage = new DriveStorage($base, $user);

        $files = $storage->listFilesByType('video', DriveStorage::SCOPE_PUBLIC);
        $this->assertIsArray($files);

        $publicDir = $base . '/public/video';
        $this->assertDirectoryExists($publicDir);
        $this->assertFalse(is_link($publicDir), 'Folder storage harus folder nyata, bukan symlink.');

        
        $this->removeDir($base);
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
}
