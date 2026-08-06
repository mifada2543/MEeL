<?php
use PHPUnit\Framework\TestCase;

/**
 * @covers authorize_stream
 * @covers is_stream_authorized
 *
 * Otorisasi streaming audio (modules/core/helpers/stream_auth.php):
 * halaman MEeL menandai id media via authorize_stream(), dan
 * music/stream.php hanya melayani id yang sudah ditandai.
 */
class StreamAuthTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION['stream_ok'] = [];
    }

    public function testAuthorizedAfterPageRender(): void
    {
        authorize_stream(145);
        $this->assertTrue(is_stream_authorized(145));
    }

    public function testDifferentIdNotAuthorized(): void
    {
        authorize_stream(145);
        $this->assertFalse(is_stream_authorized(146));
    }

    public function testNeverVisitedDenied(): void
    {
        $this->assertFalse(is_stream_authorized(999));
    }

    public function testInvalidIdNeverAuthorized(): void
    {
        authorize_stream(0);
        authorize_stream(-5);
        $this->assertFalse(is_stream_authorized(0));
        $this->assertFalse(is_stream_authorized(-5));
    }

    public function testExpiredMarkerDenied(): void
    {
        // Marker sangat tua (jauh melebihi TTL default 12 jam)
        $_SESSION['stream_ok'] = [145 => time() - 99999];
        $this->assertFalse(is_stream_authorized(145));
    }

    public function testCustomTtl(): void
    {
        $_SESSION['stream_ok'] = [7 => time() - 60]; // 1 menit lalu
        $this->assertTrue(is_stream_authorized(7, 3600));
        $this->assertFalse(is_stream_authorized(7, 30));
    }

    public function testMarkerListIsCapped(): void
    {
        for ($i = 1; $i <= 150; $i++) {
            authorize_stream($i);
        }
        $this->assertLessThanOrEqual(100, count($_SESSION['stream_ok']));
        // Marker terbaru tetap tersimpan
        $this->assertTrue(is_stream_authorized(150));
    }

    public function testEmptySessionDenied(): void
    {
        unset($_SESSION['stream_ok']);
        $this->assertFalse(is_stream_authorized(145));
    }
}
