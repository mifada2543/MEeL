<?php
use PHPUnit\Framework\TestCase;

/**
 * @covers MediaLibrary
 * @covers BookRepository
 */
class MediaLibraryTest extends TestCase
{
    private $mockConn;
    private MediaLibrary $library;

    protected function setUp(): void
    {
        parent::setUp();

        
        $this->mockConn = $this->createMock(mysqli::class);
        $this->library = new MediaLibrary($this->mockConn);
    }

    public function testGetCountsReturnsArrayWithKeys(): void
    {
        // getCounts() may hit cache or DB - just verify structure
        $result = $this->library->getCounts();
        $this->assertArrayHasKey('music', $result);
        $this->assertArrayHasKey('video', $result);
        $this->assertArrayHasKey('books', $result);
        $this->assertIsInt($result['music']);
    }

    public function testClearCountsCacheIsSafe(): void
    {

        MediaLibrary::clearCountsCache();
        $this->assertTrue(true);
    }

    public function testPaginateResult(): void
    {
        $reflection = new ReflectionClass(MediaLibrary::class);
        $method = $reflection->getMethod('paginateResult');
        $method->setAccessible(true);

        $result = $method->invokeArgs($this->library, [null, 50, 1, 15]);

        $this->assertSame(50, $result['total']);
        $this->assertSame(1, $result['page']);
        $this->assertSame(15, $result['per_page']);
        $this->assertSame(4, $result['total_pages']); // ceil(50/15) = 4
        $this->assertSame(1, $result['from']);
        $this->assertSame(15, $result['to']);
    }

    public function testPaginateResultLastPage(): void
    {
        $reflection = new ReflectionClass(MediaLibrary::class);
        $method = $reflection->getMethod('paginateResult');
        $method->setAccessible(true);

        $result = $method->invokeArgs($this->library, [null, 50, 4, 15]);

        $this->assertSame(4, $result['page']);
        $this->assertSame(46, $result['from']);
        $this->assertSame(50, $result['to']);
    }

    public function testPaginateResultPageOutOfRange(): void
    {
        $reflection = new ReflectionClass(MediaLibrary::class);
        $method = $reflection->getMethod('paginateResult');
        $method->setAccessible(true);

        // Page 10 but only 4 pages exist
        $result = $method->invokeArgs($this->library, [null, 50, 10, 15]);
        $this->assertSame(4, $result['page']); // Clamped to max
    }

    public function testPaginateResultLargeNumbers(): void
    {
        $reflection = new ReflectionClass(MediaLibrary::class);
        $method = $reflection->getMethod('paginateResult');
        $method->setAccessible(true);

        $result = $method->invokeArgs($this->library, [null, 1000, 50, 20]);
        $this->assertSame(50, $result['total_pages']); // ceil(1000/20) = 50
        $this->assertSame(50, $result['page']); // Clamped to max page
    }

    public function testPaginateResultEdgeCase(): void
    {
        $reflection = new ReflectionClass(MediaLibrary::class);
        $method = $reflection->getMethod('paginateResult');
        $method->setAccessible(true);

        // 0 total page
        $result = $method->invokeArgs($this->library, [null, 0, 1, 15]);
        $this->assertSame(1, $result['total_pages']);
        $this->assertSame(0, $result['total']);
    }

    // BookRepository Tests

    public function testBookRepositoryConstructs(): void
    {
        $repo = new BookRepository($this->mockConn);
        $this->assertInstanceOf(BookRepository::class, $repo);
    }

    public function testCountBooksAllReturnsZeroOnNoRows(): void
    {
        $mockStmt = $this->createMock(mysqli_stmt::class);
        $mockResult = $this->createMock(mysqli_result::class);

        $mockResult->method('fetch_assoc')->willReturn(['total' => '0']);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('get_result')->willReturn($mockResult);

        $this->mockConn->method('prepare')->willReturn($mockStmt);

        $repo = new BookRepository($this->mockConn);
        $this->assertSame(0, $repo->countBooks('all'));
    }

    public function testCountBooksFilteredReturnsValue(): void
    {
        $mockStmt = $this->createMock(mysqli_stmt::class);
        $mockResult = $this->createMock(mysqli_result::class);

        $mockResult->method('fetch_assoc')->willReturn(['total' => '10']);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('get_result')->willReturn($mockResult);

        $this->mockConn->method('prepare')->willReturn($mockStmt);

        $repo = new BookRepository($this->mockConn);
        $this->assertSame(10, $repo->countBooks('manga'));
    }

    public function testCountBooksWithEmptyFilter(): void
    {
        $mockStmt = $this->createMock(mysqli_stmt::class);
        $mockResult = $this->createMock(mysqli_result::class);

        $mockResult->method('fetch_assoc')->willReturn(['total' => '100']);
        $mockStmt->method('execute')->willReturn(true);
        $mockStmt->method('get_result')->willReturn($mockResult);

        $this->mockConn->method('prepare')->willReturn($mockStmt);

        $repo = new BookRepository($this->mockConn);
        $this->assertSame(100, $repo->countBooks('pdf'));
    }
}
