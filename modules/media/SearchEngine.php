<?php


require_once __DIR__ . '/MediaLibrary.php';

class SearchEngine
{
    private MediaLibrary $library;
    private static array $cache = [];
    private static int $cacheSize = 50;

    
    const VIDEO_LIMIT = 15;
    const MUSIC_LIMIT = 10;
    
    
    const SIDEBAR_LIMIT = 15;
    const MIN_SEARCH_QUERY = 3;
    const MAX_SEARCH_QUERY = 255;

    public function __construct(mysqli $db_connection)
    {
        $this->library = new MediaLibrary($db_connection);
    }

    public function parseParams(): array
    {
        $query = self::sanitizeQuery($_GET['search'] ?? '');

        return [
            'query'   => $query,
            'exclude' => isset($_GET['exclude']) ? max(0, (int)$_GET['exclude']) : 0,
            'offset'  => isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0,
            'sidebar' => $this->detectSidebar(),
            'target'  => $_SERVER['HTTP_HX_TARGET'] ?? '',
            'valid'   => $this->isValidSearchQuery($query),
        ];
    }

    public static function sanitizeQuery(string $q): string
    {
        $q = trim($q);
        $q = mb_substr($q, 0, self::MAX_SEARCH_QUERY);

        $q = preg_replace('/[<>()~@]+/', '', $q);

        $tokens = preg_split('/\s+/', $q);
        $tokens = array_filter($tokens, static function ($t) {
            return $t !== '' && !preg_match('/^[+\-*"]+$/', $t);
        });
        $q = implode(' ', $tokens);

        if (substr_count($q, '"') % 2 === 1) {
            $q = str_replace('"', '', $q);
        }

        $q = preg_replace('/(^|\s)\*(?=\S)/', '$1', $q);

        return trim($q);
    }

    private function isValidSearchQuery(string $q): bool
    {
        if (empty($q)) {
            return true;
        }
        return mb_strlen($q) >= self::MIN_SEARCH_QUERY;
    }

    private function detectSidebar(): bool
    {
        $target = $_SERVER['HTTP_HX_TARGET'] ?? '';
        return in_array($target, ['recommendation-column', 'music-recommendation-column'], true);
    }

    private function buildResult(?mysqli_result $data, array $params, int $limit, int $total = 0): array
    {
        $totalPages = max(1, (int)ceil($total / max($limit, 1)));

        if (!$data) {
            return [
                'results'     => [],
                'count'       => 0,
                'limit'       => $limit,
                'offset'      => $params['offset'],
                'hasMore'     => false,
                'sidebar'     => $params['sidebar'],
                'query'       => $params['query'],
                'exclude'     => $params['exclude'],
                'total'       => $total,
                'total_pages' => $totalPages,
            ];
        }

        $rows = [];
        $idx = 0;

        while ($row = $data->fetch_assoc()) {
            if ($idx >= $limit) {
                break;
            }
            $rows[] = $row;
            $idx++;
        }

        return [
            'results'     => $rows,
            'count'       => count($rows),
            'limit'       => $limit,
            'offset'      => $params['offset'],
            'hasMore'     => ($data->num_rows > $limit),
            'sidebar'     => $params['sidebar'],
            'query'       => $params['query'],
            'exclude'     => $params['exclude'],
            'total'       => $total,
            'total_pages' => $totalPages,
        ];
    }

    private static function getCacheKey(string $type, array $params): string
    {

        $key = $type . ':' . md5(json_encode([
            'query'   => $params['query'],
            'exclude' => $params['exclude'],
            'sidebar' => $params['sidebar'],
            'offset'  => $params['offset'],
        ]));
        return $key;
    }

    private static function getFromCache(string $type, array $params): ?array
    {
        $key = self::getCacheKey($type, $params);
        return self::$cache[$key] ?? null;
    }

    private static function setCache(string $type, array $params, array $result): void
    {
        if (count(self::$cache) >= self::$cacheSize) {
            array_shift(self::$cache);
        }
        $key = self::getCacheKey($type, $params);
        self::$cache[$key] = $result;
    }

    public function searchVideo(array $params): array
    {
        $cached = self::getFromCache('video', $params);
        if ($cached !== null) {
            return $cached;
        }

        if (!$params['valid'] && !empty($params['query'])) {
            return $this->buildResult(null, $params, self::VIDEO_LIMIT);
        }

        $limit = $params['sidebar'] ? self::SIDEBAR_LIMIT : self::VIDEO_LIMIT;

        $data = $this->library->searchVideo(
            $params['query'],
            $params['exclude'],
            $params['sidebar'],
            $params['offset'],
            $limit + 1
        );

        $total = 0;
        if (!$params['sidebar']) {
            $total = $this->library->countSearchVideo($params['query'], $params['exclude']);
        }

        $result = $this->buildResult($data, $params, $limit, $total);
        self::setCache('video', $params, $result);
        return $result;
    }

    public function searchMusic(array $params): array
    {
        $cached = self::getFromCache('music', $params);
        if ($cached !== null) {
            return $cached;
        }

        if (!$params['valid'] && !empty($params['query'])) {
            return $this->buildResult(null, $params, self::MUSIC_LIMIT);
        }

        $limit = $params['sidebar'] ? self::SIDEBAR_LIMIT : self::MUSIC_LIMIT;

        $data = $this->library->searchMusic(
            $params['query'],
            $params['exclude'],
            $params['sidebar'],
            $params['offset'],
            $limit + 1
        );

        $total = 0;
        if (!$params['sidebar']) {
            $total = $this->library->countSearchMusic($params['query'], $params['exclude']);
        }

        $result = $this->buildResult($data, $params, $limit, $total);
        self::setCache('music', $params, $result);
        return $result;
    }

}
