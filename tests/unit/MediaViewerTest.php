<?php
use PHPUnit\Framework\TestCase;

/**
 * @covers MediaViewer
 *
 * Regression test untuk determinisme urutan queue playlist (bug: urutan bisa
 * berubah-ubah saat berpindah antara full-player watch.php dan mini-player
 * index.php karena ORDER BY tanpa tie-breaker).
 *
 * Yang diverifikasi:
 * 1. Query queue memakai tie-breaker: ORDER BY pt.added_at DESC, pt.id DESC
 * 2. Query "current" ikut mengambil pt.id (untuk row-value comparison)
 * 3. Query "next" memakai row-value comparison (added_at, id) < (?, ?) dengan
 * urutan yang sama, sehingga track ber-added_at identik (detik yang sama)
 * tidak terlewat / urutannya tidak ambigu.
 */
class MediaViewerTest extends TestCase
{
    /** @var array<int,string> SQL yang diteruskan ke conn->prepare() */
    private array $sqls = [];

    /** @var array<int,array{types:string,count:int}> bind_param() per stmt (urut pemanggilan) */
    private array $bindCalls = [];

    /**
     * Bangun mock mysqli yang merekam SQL prepare() dan mengembalikan stmt mock.
     *
     * @param array|null $currentRow Baris hasil query "current" (added_at + id), null = tidak ditemukan
     * @param array|null $nextRow    Baris hasil query "next" (music_id), null = tidak ada
     * @return array{0: mysqli, 1: mysqli_stmt, 2: mysqli_stmt, 3: mysqli_stmt}
     */
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

        // 1. Query queue: tie-breaker pt.id wajib ada
        $this->assertStringContainsString(
            'ORDER BY pt.added_at DESC, pt.id DESC',
            $this->sqls[0]
        );

        // 2. Query current: ambil added_at DAN id
        $this->assertStringContainsString(
            'SELECT added_at, id FROM playlist_tracks',
            $this->sqls[1]
        );
        $this->assertStringContainsString('ORDER BY id DESC LIMIT 1', $this->sqls[1]);

        // 3. Query next: row-value comparison + urutan identik dengan queue
        $this->assertStringContainsString('(added_at, id) < (?, ?)', $this->sqls[2]);
        $this->assertStringContainsString(
            'ORDER BY added_at DESC, id DESC LIMIT 1',
            $this->sqls[2]
        );

        // bind_param: 3 placeholder (= jumlah ? di SQL), tipe "isi" (int, string, int).
        // Jumlah tipe HARUS sama dengan jumlah variabel, kalau tidak mysqli
        // melempar ArgumentCountError saat runtime.
        $this->assertCount(3, $this->bindCalls);
        $this->assertSame('isi', $this->bindCalls[2]['types']);
        $this->assertSame(3, $this->bindCalls[2]['count']);
        $this->assertSame(strlen($this->bindCalls[2]['types']), $this->bindCalls[2]['count']);

        // Invariant: jumlah placeholder '?' di SQL == jumlah tipe bind_param
        foreach ([0, 1, 2] as $i) {
            $this->assertSame(
                substr_count($this->sqls[$i], '?'),
                strlen($this->bindCalls[$i]['types']),
                "Jumlah placeholder tidak cocok dengan bind_param di query #$i"
            );
        }

        // next_url tetap membawa playlist_id
        $this->assertSame('watch.php?id=7&playlist_id=3', $result['next_url']);
    }

    public function testNextUrlEmptyWhenCurrentTrackNotInPlaylist(): void
    {
        [$conn] = $this->buildConn(null, null);

        $viewer = new MediaViewer($conn, null, 'music', 999);
        $result = $viewer->getPlaylistQueue(3);

        $this->assertSame('', $result['next_url']);
        // Hanya 2 query yang dijalankan (queue + current) — "next" dilewati
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
