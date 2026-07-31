<?php
/**
 * modules/media/SearchEngine.php
 *
 * SearchEngine — Centralized search query handler optimized for performance.
 * Handles parameter parsing, input validation, and result wrapping dengan
 * efficient query execution dan caching support.
 *
 * @package MEeL
 */

require_once __DIR__ . '/MediaLibrary.php';

class SearchEngine
{
    private mysqli $conn;
    private MediaLibrary $library;
    private static array $cache = [];
    private static int $cacheSize = 50; // Max cached queries

    // Limit default, harus sinkron dengan @ MediaLibrary
    const VIDEO_LIMIT = 20;
    const MUSIC_LIMIT = 20;
    const MIN_SEARCH_QUERY = 2;  // Minimum search length
    const MAX_SEARCH_QUERY = 255; // Maximum search length

    public function __construct(mysqli $db_connection)
    {
        $this->conn    = $db_connection;
        $this->library = new MediaLibrary($db_connection);
    }

    // ── PARAMETER PARSING ───────────────────────────────────────────────────

    /**
     * Parse & validate parameter request yang umum dipakai di semua halaman search.
     * Includes input sanitization untuk mencegah query injection.
     *
     * @return array{
     *   query:    string,
     *   exclude:  int,
     *   offset:   int,
     *   sidebar:  bool,
     *   target:   string,
     *   valid:    bool,
     * }
     */
    public function parseParams(): array
    {
        $query = $this->sanitizeSearchQuery($_GET['search'] ?? '');
        
        return [
            'query'   => $query,
            'exclude' => isset($_GET['exclude']) ? max(0, (int)$_GET['exclude']) : 0,
            'offset'  => isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0,
            'sidebar' => $this->detectSidebar(),
            'target'  => $_SERVER['HTTP_HX_TARGET'] ?? '',
            'valid'   => $this->isValidSearchQuery($query),
        ];
    }

    /**
     * Sanitize search query:
     * - Trim whitespace
     * - Limit length
     * - Remove special characters yang bisa break fulltext search
     */
    private function sanitizeSearchQuery(string $q): string
    {
        $q = trim($q);
        $q = mb_substr($q, 0, self::MAX_SEARCH_QUERY);
        
        // Remove atau normalize special fulltext operators yang bisa cause issues
        // Tapi retain basic operators untuk performance: + - " *
        $q = preg_replace('/[<>()~@]+/', '', $q);
        
        return $q;
    }

    /**
     * Validate search query — must have minimum length untuk avoid inefficient searches.
     */
    private function isValidSearchQuery(string $q): bool
    {
        if (empty($q)) {
            return true; // Empty query is valid (returns random or latest)
        }
        return mb_strlen($q) >= self::MIN_SEARCH_QUERY;
    }

    /**
     * Deteksi apakah request berasal dari sidebar HTMX (recommendation).
     */
    private function detectSidebar(): bool
    {
        $target = $_SERVER['HTTP_HX_TARGET'] ?? '';
        return in_array($target, ['recommendation-column', 'music-recommendation-column'], true);
    }

    // ── SEARCH RESULT BUILDER ────────────────────────────────────────────────

    /**
     * Bungkus mysqli_result ke array asosiatif + metadata.
     * Optimized: Fetch limit+1 untuk efficiently check hasMore tanpa COUNT query.
     */
    private function buildResult(?mysqli_result $data, array $params, int $limit): array
    {
        if (!$data) {
            return [
                'results' => [],
                'count'   => 0,
                'limit'   => $limit,
                'offset'  => $params['offset'],
                'hasMore' => false,
                'sidebar' => $params['sidebar'],
                'query'   => $params['query'],
                'exclude' => $params['exclude'],
            ];
        }

        $rows = [];
        $actualLimit = $limit + 1; // Fetch 1 extra untuk check hasMore
        $idx = 0;
        
        while ($row = $data->fetch_assoc()) {
            if ($idx >= $limit) {
                break; // Jangan store row ke-21, tapi set hasMore = true
            }
            $rows[] = $row;
            $idx++;
        }

        return [
            'results' => $rows,
            'count'   => count($rows),
            'limit'   => $limit,
            'offset'  => $params['offset'],
            'hasMore' => ($data->num_rows > $limit), // True jika ada row ke-21
            'sidebar' => $params['sidebar'],
            'query'   => $params['query'],
            'exclude' => $params['exclude'],
        ];
    }

    // ── CACHING HELPERS ──────────────────────────────────────────────────────

    /**
     * Generate cache key dari search parameters.
     */
    private static function getCacheKey(string $type, array $params): string
    {
        $key = $type . ':' . md5(json_encode([
            'query'   => $params['query'],
            'exclude' => $params['exclude'],
            'sidebar' => $params['sidebar'],
        ]));
        return $key;
    }

    /**
     * Get cached result jika ada.
     */
    private static function getFromCache(string $type, array $params): ?array
    {
        $key = self::getCacheKey($type, $params);
        return self::$cache[$key] ?? null;
    }

    /**
     * Store result ke cache (simple in-memory caching untuk request lifecycle).
     */
    private static function setCache(string $type, array $params, array $result): void
    {
        if (count(self::$cache) >= self::$cacheSize) {
            array_shift(self::$cache); // Simple FIFO eviction
        }
        $key = self::getCacheKey($type, $params);
        self::$cache[$key] = $result;
    }

    // ── VIDEO SEARCH ─────────────────────────────────────────────────────────

    /**
     * Cari video — delegasi ke MediaLibrary::searchVideo(),
     * lalu bungkus dalam format terstandarisasi dengan caching.
     */
    public function searchVideo(array $params): array
    {
        // Check cache dahulu
        $cached = self::getFromCache('video', $params);
        if ($cached !== null) {
            return $cached;
        }

        // Skip search jika query terlalu pendek
        if (!$params['valid'] && !empty($params['query'])) {
            return $this->buildResult(null, $params, self::VIDEO_LIMIT);
        }

        $data = $this->library->searchVideo(
            $params['query'],
            $params['exclude'],
            $params['sidebar'],
            $params['offset']
        );

        $result = $this->buildResult($data, $params, self::VIDEO_LIMIT);
        self::setCache('video', $params, $result);
        return $result;
    }

    // ── MUSIC SEARCH ─────────────────────────────────────────────────────────

    /**
     * Cari musik — delegasi ke MediaLibrary::searchMusic(),
     * lalu bungkus dalam format terstandarisasi dengan caching & offset support.
     */
    public function searchMusic(array $params): array
    {
        // Check cache dahulu
        $cached = self::getFromCache('music', $params);
        if ($cached !== null) {
            return $cached;
        }

        // Skip search jika query terlalu pendek
        if (!$params['valid'] && !empty($params['query'])) {
            return $this->buildResult(null, $params, self::MUSIC_LIMIT);
        }

        $data = $this->library->searchMusic(
            $params['query'],
            $params['exclude'],
            $params['sidebar'],
            $params['offset']
        );

        $result = $this->buildResult($data, $params, self::MUSIC_LIMIT);
        self::setCache('music', $params, $result);
        return $result;
    }

    /**
     * Clear cache — useful untuk testing atau manual cache invalidation.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
