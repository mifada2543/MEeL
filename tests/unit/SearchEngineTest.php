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
        $this->assertSame(20, SearchEngine::VIDEO_LIMIT);
        $this->assertSame(20, SearchEngine::MUSIC_LIMIT);
    }
}
