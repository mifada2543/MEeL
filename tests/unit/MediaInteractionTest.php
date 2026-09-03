<?php
use PHPUnit\Framework\TestCase;

/**
 * @covers MediaInteraction
 */
class MediaInteractionTest extends TestCase
{
    
    private function createInteraction(int $userId = 1): MediaInteraction
    {

        $conn = $this->getMockBuilder(mysqli::class)
            ->disableOriginalConstructor()
            ->getMock();

        return new MediaInteraction($conn, $userId);
    }

    public function testConstruct(): void
    {
        $interaction = $this->createInteraction(1);
        $this->assertInstanceOf(MediaInteraction::class, $interaction);
    }

    public function testGetUserId(): void
    {
        $interaction = $this->createInteraction(42);
        $this->assertSame(42, $interaction->getUserId());
    }

    public function testGetErrorReturnsEmptyString(): void
    {
        $interaction = $this->createInteraction(1);
        $this->assertSame('', $interaction->getError());
    }

    public function testDeleteCommentWithInvalidId(): void
    {
        $interaction = $this->createInteraction(1);
        $result = $interaction->deleteComment(0);
        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['http_code']);
    }

    public function testToggleLikeInvalidMediaType(): void
    {
        $interaction = $this->createInteraction(1);
        $result = $interaction->toggleLike(1, 'invalid_type', 'like');
        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['http_code']);
    }

    public function testToggleLikeInvalidLikeType(): void
    {
        $interaction = $this->createInteraction(1);
        $result = $interaction->toggleLike(1, 'music', 'invalid_type');
        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['http_code']);
    }

    public function testToggleLikeInvalidMediaId(): void
    {
        $interaction = $this->createInteraction(1);
        $result = $interaction->toggleLike(0, 'music', 'like');
        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['http_code']);
    }
}
