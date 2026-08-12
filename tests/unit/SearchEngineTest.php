<?php
use PHPUnit\Framework\TestCase;

/**
 * @covers SearchEngine
 */
class SearchEngineTest extends TestCase
{
    private $mockConn;
    private SearchEngine $searchEngine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockConn = $this->createMock(mysqli::class);
        $this->searchEngine = new SearchEngine($this->mockConn);
    }

    public function testSearchEngineConstructs(): void
    {
        $this->assertInstanceOf(SearchEngine::class, $this->searchEngine);
    }

    public function testParseParamsDefaults(): void
    {
        // Ensure no GET params set
        unset($_GET['search'], $_GET['exclude'], $_GET['offset']);

        $params = $this->searchEngine->parseParams();
        $this->assertSame('', $params['query']);
        $this->assertSame(0, $params['exclude']);
        $this->assertSame(0, $params['offset']);
        $this->assertFalse($params['sidebar']);
        $this->assertSame('', $params['target']);
    }

    public function testParseParamsWithQuery(): void
    {
        $_GET['search'] = 'test query';
        $_GET['exclude'] = '5';
        $_GET['offset'] = '10';

        $params = $this->searchEngine->parseParams();
        $this->assertSame('test query', $params['query']);
        $this->assertSame(5, $params['exclude']);
        $this->assertSame(10, $params['offset']);
    }

    public function testConstants(): void
    {
        $this->assertSame(15, SearchEngine::VIDEO_LIMIT);
        $this->assertSame(10, SearchEngine::MUSIC_LIMIT);
        $this->assertSame(15, SearchEngine::SIDEBAR_LIMIT);

        $this->assertSame(3, SearchEngine::MIN_SEARCH_QUERY);
    }

    public function testSanitizeQueryNeutralizesFulltextBreakingInput(): void
    {
        // Operator murni / kosong → hasil kosong (bukan SQL error)
        $this->assertSame('', SearchEngine::sanitizeQuery('*'));
        $this->assertSame('', SearchEngine::sanitizeQuery('-'));
        $this->assertSame('', SearchEngine::sanitizeQuery('+'));
        $this->assertSame('', SearchEngine::sanitizeQuery('"'));
        $this->assertSame('', SearchEngine::sanitizeQuery('<<>>()~@'));

        // Operator di akhir/awal token dinetralkan
        $this->assertSame('foo', SearchEngine::sanitizeQuery('foo -'));
        $this->assertSame('foo', SearchEngine::sanitizeQuery('*foo'));
        $this->assertSame('a b', SearchEngine::sanitizeQuery('a - b'));

        $this->assertSame('hello', SearchEngine::sanitizeQuery('"hello'));
        $this->assertSame('hello', SearchEngine::sanitizeQuery('hello"'));
        $this->assertSame('"a b"', SearchEngine::sanitizeQuery('"a b"'));

        // Prefix search (kata*) tetap valid
        $this->assertSame('foo*', SearchEngine::sanitizeQuery('foo*'));

        // Query normal tidak berubah
        $this->assertSame('test query', SearchEngine::sanitizeQuery('test query'));
    }
}
