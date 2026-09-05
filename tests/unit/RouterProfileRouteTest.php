<?php
use PHPUnit\Framework\TestCase;

require_once MEEL_ROOT . '/modules/core/Router.php';

/**
 * @covers MeelRouter
 */
class RouterProfileRouteTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_GET['u']);
    }

    public function testProfileUsernameResolvesToProfileRoute(): void
    {
        $route = MeelRouter::routeFor('profile/john_doe');
        $this->assertSame('profile/index.php', $route['handler']);
        $this->assertSame('john_doe', $_GET['u']);
    }

    public function testGuestProfileUsername(): void
    {
        $route = MeelRouter::routeFor('profile/guest');
        $this->assertSame('profile/index.php', $route['handler']);
        $this->assertSame('guest', $_GET['u']);
    }

    public function testReservedProfileRoutesKeepPriority(): void
    {
        foreach (['profile/manage', 'profile/edit', 'profile/manage-action', 'profile/edit-video', 'profile/edit-music'] as $path) {
            $route = MeelRouter::routeFor($path);
            $this->assertNotSame('profile/index.php', $route['handler'], "{$path} harus tetap rute eksak, bukan profil user");
            $this->assertArrayNotHasKey('u', $_GET, "{$path} tidak boleh mengisi \$_GET['u']");
        }
    }

    public function testNestedProfilePathDoesNotMatch(): void
    {
        $this->assertNull(MeelRouter::routeFor('profile/user/extra'));
    }

    public function testTrailingSlashProfilePathDoesNotMatch(): void
    {
        $this->assertNull(MeelRouter::routeFor('profile/'));
    }

    public function testPlainProfileRouteStaysExact(): void
    {
        $route = MeelRouter::routeFor('profile');
        $this->assertSame('profile/index.php', $route['handler']);
        $this->assertArrayNotHasKey('u', $_GET);
    }

    public function testUrlBuildsCleanProfilePath(): void
    {
        $url = MeelRouter::url('profile/john_doe');
        $this->assertStringEndsWith('/profile/john_doe', $url);
    }
}