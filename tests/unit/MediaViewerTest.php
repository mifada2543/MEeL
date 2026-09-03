<?php
use PHPUnit\Framework\TestCase;

/**
 * @covers MediaViewer
 */
class MediaViewerTest extends TestCase
{
    
    private array $sqls = [];

    
    private array $bindCalls = [];

    

    private function buildConn(?array $currentRow, ?array $nextRow): array
    {
        $this->bindCalls = [];
        $recordBind = function (mysqli_stmt $stmt): void {
            $stmt->method('bind_param')->willReturnCallback(
                function ($types, ...$vars) {
                    $this->bindCalls[] = [
                        'types' => (string)$types,
                        'count' => count($vars),
                    ];
                    return true;
                }
            );
        };

        $resultQ = $this->createMock(mysqli_result::class);
        $stmtQ = $this->createMock(mysqli_stmt::class);
        $recordBind($stmtQ);
        $stmtQ->method('execute')->willReturn(true);
        $stmtQ->method('get_result')->willReturn($resultQ);

        $resultCur = $this->createMock(mysqli_result::class);
        $resultCur->method('fetch_assoc')->willReturn($currentRow);
        $stmtCur = $this->createMock(mysqli_stmt::class);
        $recordBind($stmtCur);
        $stmtCur->method('execute')->willReturn(true);
        $stmtCur->method('get_result')->willReturn($resultCur);

        $resultNext = $this->createMock(mysqli_result::class);
        $resultNext->method('fetch_assoc')->willReturn($nextRow);
        $stmtNext = $this->createMock(mysqli_stmt::class);
        $recordBind($stmtNext);
        $stmtNext->method('execute')->willReturn(true);
        $stmtNext->method('get_result')->willReturn($resultNext);

        $conn = $this->createMock(mysqli::class);
        $this->sqls = [];
        $conn->method('prepare')->willReturnCallback(
            function ($sql) use ($stmtQ, $stmtCur, $stmtNext) {
                $this->sqls[] = $sql;
                $count = count($this->sqls);
                return $count === 1 ? $stmtQ : ($count === 2 ? $stmtCur : $stmtNext);
            }
        );

        return [$conn, $stmtQ, $stmtCur, $stmtNext];
    }

    public function testQueueUsesDeterministicOrderWithTieBreaker(): void
    {
        [$conn] = $this->buildConn(
            ['added_at' => '2026-08-08 10:00:00', 'id' => '5'],
            ['music_id' => '7']
        );

        $viewer = new MediaViewer($conn, null, 'music', 5);
        $result = $viewer->getPlaylistQueue(3);

        
        $this->assertStringContainsString(
            'ORDER BY pt.added_at DESC, pt.id DESC',
            $this->sqls[0]
        );

        
        $this->assertStringContainsString(
            'SELECT added_at, id FROM playlist_tracks',
            $this->sqls[1]
        );
        $this->assertStringContainsString('ORDER BY id DESC LIMIT 1', $this->sqls[1]);

        
        $this->assertStringContainsString('(added_at, id) < (?, ?)', $this->sqls[2]);
        $this->assertStringContainsString(
            'ORDER BY added_at DESC, id DESC LIMIT 1',
            $this->sqls[2]
        );

        
        
        
        $this->assertCount(3, $this->bindCalls);
        $this->assertSame('isi', $this->bindCalls[2]['types']);
        $this->assertSame(3, $this->bindCalls[2]['count']);
        $this->assertSame(strlen($this->bindCalls[2]['types']), $this->bindCalls[2]['count']);

        
        foreach ([0, 1, 2] as $i) {
            $this->assertSame(
                substr_count($this->sqls[$i], '?'),
                strlen($this->bindCalls[$i]['types']),
                "Jumlah placeholder tidak cocok dengan bind_param di query #$i"
            );
        }

        
        $this->assertSame('watch.php?id=7&playlist_id=3', $result['next_url']);
    }

    public function testNextUrlEmptyWhenCurrentTrackNotInPlaylist(): void
    {
        [$conn] = $this->buildConn(null, null);

        $viewer = new MediaViewer($conn, null, 'music', 999);
        $result = $viewer->getPlaylistQueue(3);

        $this->assertSame('', $result['next_url']);
        
        $this->assertCount(2, $this->sqls);
    }

    public function testReturnsNullForNonMusicType(): void
    {
        $conn = $this->createMock(mysqli::class);
        $conn->expects($this->never())->method('prepare');

        $viewer = new MediaViewer($conn, null, 'video', 1);
        $this->assertNull($viewer->getPlaylistQueue(3));
    }

    public function testReturnsNullForEmptyPlaylistId(): void
    {
        $conn = $this->createMock(mysqli::class);
        $conn->expects($this->never())->method('prepare');

        $viewer = new MediaViewer($conn, null, 'music', 1);
        $this->assertNull($viewer->getPlaylistQueue(0));
    }
}
