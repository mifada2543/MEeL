<?php
use PHPUnit\Framework\TestCase;

/**
 * @requires extension mysqli
 * @group integration
 * @covers MediaInteraction
 */
class MediaInteractionIntegrationTest extends TestCase
{
    private DbTestHelper $dbHelper;
    private mysqli $conn;
    private MediaInteraction $interaction;
    private MediaInteraction $memberInteraction;
    private MediaInteraction $adminInteraction;
    private MediaInteraction $admin2Interaction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbHelper = new DbTestHelper();
        $this->conn = $this->dbHelper->getConnection();

        
        $this->interaction = new MediaInteraction($this->conn, DbTestHelper::REGULAR_USER_ID);
        $this->memberInteraction = new MediaInteraction($this->conn, DbTestHelper::MEMBER_USER_ID);
        $this->adminInteraction = new MediaInteraction($this->conn, DbTestHelper::ADMIN_USER_ID);
        $this->admin2Interaction = new MediaInteraction($this->conn, DbTestHelper::ADMIN2_USER_ID);
    }

    protected function tearDown(): void
    {
        // Rollback all changes made during the test
        $this->dbHelper->rollback();
        $this->dbHelper->close();
        parent::tearDown();
    }

    // LIKE / DISLIKE — MUSIC
    public function testLikeMusic(): void
    {
        $result = $this->interaction->toggleLike(
            DbTestHelper::MUSIC_ID_1, 'music', 'like'
        );

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['http_code']);
        $this->assertSame('like', $result['data']['user_interaction']);
        $this->assertGreaterThanOrEqual(1, $result['data']['likes']);
    }

    public function testDislikeMusic(): void
    {
        $result = $this->interaction->toggleLike(
            DbTestHelper::MUSIC_ID_1, 'music', 'dislike'
        );

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['http_code']);
        $this->assertSame('dislike', $result['data']['user_interaction']);
        $this->assertGreaterThanOrEqual(1, $result['data']['dislikes']);
    }

    public function testToggleLikeOffMusic(): void
    {
        // Step 1: Like a music item
        $this->interaction->toggleLike(DbTestHelper::MUSIC_ID_1, 'music', 'like');

        // Step 2: Like again (toggle OFF — should remove the like)
        $result = $this->interaction->toggleLike(
            DbTestHelper::MUSIC_ID_1, 'music', 'like'
        );

        $this->assertTrue($result['success']);

        $this->assertNull($result['data']['user_interaction']);
    }

    public function testSwitchFromLikeToDislike(): void
    {
        // Step 1: Like
        $this->interaction->toggleLike(DbTestHelper::MUSIC_ID_1, 'music', 'like');

        // Step 2: Switch to dislike
        $result = $this->interaction->toggleLike(
            DbTestHelper::MUSIC_ID_1, 'music', 'dislike'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('dislike', $result['data']['user_interaction']);
    }

    public function testDifferentUsersIndependentLikes(): void
    {
        // User A likes music
        $this->interaction->toggleLike(DbTestHelper::MUSIC_ID_1, 'music', 'like');
        $userAStatus = $this->interaction->getUserInteractionStatus(
            DbTestHelper::MUSIC_ID_1, 'music'
        );

        // User B has no interaction (yet)
        $userBStatus = $this->memberInteraction->getUserInteractionStatus(
            DbTestHelper::MUSIC_ID_1, 'music'
        );

        $this->assertSame('like', $userAStatus);
        $this->assertNull($userBStatus);
    }

    public function testGetUserInteractionStatusNoInteraction(): void
    {
        // Fresh user on fresh music item — should be null
        $status = $this->interaction->getUserInteractionStatus(
            DbTestHelper::MUSIC_ID_3, 'music'
        );
        $this->assertNull($status);
    }

    // LIKE / DISLIKE — VIDEO
    public function testLikeVideo(): void
    {
        $result = $this->interaction->toggleLike(
            DbTestHelper::VIDEO_ID_1, 'video', 'like'
        );

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['http_code']);
        $this->assertSame('like', $result['data']['user_interaction']);
    }

    public function testDislikeVideo(): void
    {
        $result = $this->interaction->toggleLike(
            DbTestHelper::VIDEO_ID_1, 'video', 'dislike'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('dislike', $result['data']['user_interaction']);
    }

    public function testToggleVideoLikeTwice(): void
    {
        // Like → Like again = toggle OFF
        $this->interaction->toggleLike(DbTestHelper::VIDEO_ID_1, 'video', 'like');
        $result = $this->interaction->toggleLike(
            DbTestHelper::VIDEO_ID_1, 'video', 'like'
        );

        $this->assertTrue($result['success']);
        $this->assertNull($result['data']['user_interaction']);
    }

    public function testVideoLikeDislikeCountSync(): void
    {
        
        $initial = $this->dbHelper->getVideoLikesCount(DbTestHelper::VIDEO_ID_1);

        // User A likes
        $this->interaction->toggleLike(DbTestHelper::VIDEO_ID_1, 'video', 'like');
        $result = $this->interaction->toggleLike(
            DbTestHelper::VIDEO_ID_1, 'video', 'like'
        );

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('likes', $result['data']);
        $this->assertArrayHasKey('dislikes', $result['data']);
    }

    // GET LIKES COUNT
    public function testGetLikesCountForMusic(): void
    {
        $counts = $this->interaction->getLikesCount('music', DbTestHelper::MUSIC_ID_1);

        $this->assertArrayHasKey('likes', $counts);
        $this->assertArrayHasKey('dislikes', $counts);
        $this->assertIsInt($counts['likes']);
        $this->assertIsInt($counts['dislikes']);
    }

    public function testGetLikesCountForVideo(): void
    {
        $counts = $this->interaction->getLikesCount('video', DbTestHelper::VIDEO_ID_1);

        $this->assertArrayHasKey('likes', $counts);
        $this->assertArrayHasKey('dislikes', $counts);
        $this->assertIsInt($counts['likes']);
        $this->assertIsInt($counts['dislikes']);
    }

    // COMMENT DELETION
    public function testDeleteOwnComment(): void
    {
        
        $commentId = $this->dbHelper->createTestComment(
            DbTestHelper::REGULAR_USER_ID,
            DbTestHelper::MUSIC_ID_1,
            null,
            'Integration test comment'
        );

        // Verify comment exists
        $ownerId = $this->dbHelper->getCommentOwner($commentId);
        $this->assertSame(DbTestHelper::REGULAR_USER_ID, $ownerId);

        
        $result = $this->interaction->deleteComment($commentId);
        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['http_code']);
        $this->assertSame('Komentar berhasil dihapus', $result['message']);
    }

    public function testDeleteOtherUsersCommentFails(): void
    {
        
        $commentId = $this->dbHelper->createTestComment(
            DbTestHelper::MEMBER_USER_ID,
            DbTestHelper::MUSIC_ID_1,
            null,
            'Comment by member'
        );

        // Try to delete as regular user (not the owner)
        $result = $this->interaction->deleteComment($commentId);

        $this->assertFalse($result['success']);
        $this->assertSame(404, $result['http_code']);
    }

    public function testDeleteNonExistentComment(): void
    {
        $result = $this->interaction->deleteComment(9999999);

        $this->assertFalse($result['success']);
        $this->assertSame(404, $result['http_code']);
    }

    public function testDeleteCommentAsDifferentUser(): void
    {
        
        $commentId = $this->dbHelper->createTestComment(
            DbTestHelper::ADMIN_USER_ID,
            DbTestHelper::MUSIC_ID_1,
            null,
            'Admin comment'
        );

        // Member tries to delete admin's comment — should fail
        $result = $this->memberInteraction->deleteComment($commentId);
        $this->assertFalse($result['success']);

        // Admin deletes own comment — should succeed
        $result = $this->adminInteraction->deleteComment($commentId);
        $this->assertTrue($result['success']);
    }

    public function testUploaderCanDeleteOtherUsersCommentOnMusic(): void
    {
        // ADMIN2_USER_ID (9) adalah uploader MUSIC_ID_1 (49)
        $commentId = $this->dbHelper->createTestComment(
            DbTestHelper::REGULAR_USER_ID,
            DbTestHelper::MUSIC_ID_1,
            null,
            'Comment by regular user on uploader music'
        );

        $result = $this->admin2Interaction->deleteComment($commentId);

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['http_code']);
        $this->assertSame('Komentar berhasil dihapus', $result['message']);
    }

    public function testUploaderCanDeleteOtherUsersCommentOnVideo(): void
    {
        // ADMIN2_USER_ID (9) adalah uploader VIDEO_ID_1 (4)
        $commentId = $this->dbHelper->createTestComment(
            DbTestHelper::MEMBER_USER_ID,
            null,
            DbTestHelper::VIDEO_ID_1,
            'Comment by member on uploader video'
        );

        $result = $this->admin2Interaction->deleteComment($commentId);

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['http_code']);
    }

    public function testNonUploaderCannotDeleteOtherUsersComment(): void
    {

        $commentId = $this->dbHelper->createTestComment(
            DbTestHelper::MEMBER_USER_ID,
            DbTestHelper::MUSIC_ID_1,
            null,
            'Comment by member'
        );

        // Regular (bukan owner, bukan uploader) → tetap ditolak
        $result = $this->interaction->deleteComment($commentId);

        $this->assertFalse($result['success']);
        $this->assertSame(404, $result['http_code']);
    }

    public function testAdminCanDeleteAnyCommentOnMusic(): void
    {

        $commentId = $this->dbHelper->createTestComment(
            DbTestHelper::REGULAR_USER_ID,
            DbTestHelper::MUSIC_ID_1,
            null,
            'Comment by regular user on someone else media'
        );

        $result = $this->adminInteraction->deleteComment($commentId);

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['http_code']);
        $this->assertSame('Komentar berhasil dihapus', $result['message']);
    }

    public function testAdminCanDeleteAnyCommentOnVideo(): void
    {

        $commentId = $this->dbHelper->createTestComment(
            DbTestHelper::MEMBER_USER_ID,
            null,
            DbTestHelper::VIDEO_ID_1,
            'Comment by member on someone else video'
        );

        $result = $this->adminInteraction->deleteComment($commentId);

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['http_code']);
    }

    // EDGE CASES
    public function testMultipleInteractionsOnDifferentMedia(): void
    {
        // Like multiple items as the same user
        $r1 = $this->interaction->toggleLike(DbTestHelper::MUSIC_ID_1, 'music', 'like');
        $r2 = $this->interaction->toggleLike(DbTestHelper::MUSIC_ID_2, 'music', 'like');
        $r3 = $this->interaction->toggleLike(DbTestHelper::VIDEO_ID_1, 'video', 'like');

        $this->assertTrue($r1['success']);
        $this->assertTrue($r2['success']);
        $this->assertTrue($r3['success']);

        // Verify all three are independent
        $s1 = $this->interaction->getUserInteractionStatus(DbTestHelper::MUSIC_ID_1, 'music');
        $s2 = $this->interaction->getUserInteractionStatus(DbTestHelper::MUSIC_ID_2, 'music');
        $s3 = $this->interaction->getUserInteractionStatus(DbTestHelper::VIDEO_ID_1, 'video');

        $this->assertSame('like', $s1);
        $this->assertSame('like', $s2);
        $this->assertSame('like', $s3);
    }

    public function testLikeDislikeCountsAreAccurate(): void
    {
        
        $initial = $this->dbHelper->getMusicLikesCount(DbTestHelper::MUSIC_ID_1);

        // User A likes
        $r1 = $this->interaction->toggleLike(DbTestHelper::MUSIC_ID_1, 'music', 'like');
        $this->assertTrue($r1['success']);

        // Verify likes increased
        $afterLike = $this->dbHelper->getMusicLikesCount(DbTestHelper::MUSIC_ID_1);
        $this->assertSame($initial['likes'] + 1, $afterLike['likes']);

        // User A switches to dislike
        $r2 = $this->interaction->toggleLike(DbTestHelper::MUSIC_ID_1, 'music', 'dislike');
        $this->assertTrue($r2['success']);

        // Verify likes decreased back, dislikes increased
        $afterDislike = $this->dbHelper->getMusicLikesCount(DbTestHelper::MUSIC_ID_1);
        $this->assertSame($initial['likes'], $afterDislike['likes']);
        $this->assertSame($initial['dislikes'] + 1, $afterDislike['dislikes']);
    }

    /* Test that guest (user_id=0) cannot interact. */
    public function testGuestCannotInteract(): void
    {
        $guestInteraction = new MediaInteraction($this->conn, 0);

        $result = $guestInteraction->toggleLike(
            DbTestHelper::MUSIC_ID_1, 'music', 'like'
        );

        $this->assertFalse($result['success']);
        $this->assertSame(403, $result['http_code']);
        $this->assertSame('User tidak terautentikasi', $result['message']);
    }
}
