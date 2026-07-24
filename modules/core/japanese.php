<?php
/**
 * modules/core/japanese.php
 * Fungsi pemrosesan teks Jepang (MeCab + transliterasi Romaji + kamus offline JMdict).
 * Hanya di-include di halaman yang membutuhkan (upload/transcode/admin edit).
 * Tidak dibebankan ke setiap request seperti sebelumnya di config.php.
 */

// ─── HELPER: Resolve MeCab binary (static cache per request) ───────────────
if (!function_exists('getMecabPath')) {
    function getMecabPath(): string
    {
        static $path = null;
        if ($path !== null) return $path;

        // Coba gunakan resolve_binary() dari helpers.php jika tersedia
        if (function_exists('resolve_binary')) {
            $path = resolve_binary(['/usr/bin/mecab', '/usr/local/bin/mecab', 'mecab']);
            return $path;
        }

        // Fallback: cek path absolut langsung
        $candidates = ['/usr/bin/mecab', '/usr/local/bin/mecab', 'mecab'];
        foreach ($candidates as $candidate) {
            if (strpos($candidate, '/') !== false) {
                if (@is_executable($candidate)) {
                    $path = $candidate;
                    return $path;
                }
            }
        }
        $path = 'mecab';
        return $path;
    }
}

// ─── ROMAJI CONVERTER ──────────────────────────────────────────────────────────
if (!function_exists('getRomajiName')) {
    function getRomajiName(string $text): string
    {
        if (empty($text)) return 'untitled';

        // Simpan input asli sebagai cadangan jika MeCab/transliterasi gagal
        $original_text = $text;

        // 1. Kamus Koreksi Karakter Spesifik & Simbol
        $search = [
            '×', 'x', 'X', '*', '&', '/',
            '【', '】', '「', '」', '(', ')',
            '鏡音', '巡音', '初音'
        ];
        $replace = [
            ' ', ' ', ' ', ' ', ' ', ' ',
            ' ', ' ', ' ', ' ', ' ', ' ',
            'かがみね', 'めぐりね', 'hatsune'
        ];
        $text = str_replace($search, $replace, $text);

        // 2. Eksekusi MeCab — path absolut biar tidak bergantung PATH environment
        $mecab_bin = getMecabPath();
        $descriptorspec = [0 => ["pipe", "r"], 1 => ["pipe", "w"]];
        $process = proc_open(escapeshellarg($mecab_bin), $descriptorspec, $pipes);

        $parsedText = '';
        if (is_resource($process)) {
            fwrite($pipes[0], $text);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            proc_close($process);

            foreach (explode("\n", trim($output)) as $line) {
                if ($line === 'EOS' || trim($line) === '') continue;
                $parts = explode("\t", $line);
                if (count($parts) >= 2) {
                    $surface  = $parts[0];
                    $features = explode(',', $parts[1]);
                    $yomi = '*';
                    if (isset($features[7]) && $features[7] !== '*') $yomi = $features[7];
                    elseif (isset($features[8]) && $features[8] !== '*') $yomi = $features[8];
                    $parsedText .= ' ' . (($yomi !== '*' && !preg_match('/[a-zA-Z]/', $yomi)) ? $yomi : $surface);
                }
            }
            $text = trim($parsedText);
        }

        // 3. Transliterasi via php-intl
        $rule = "Katakana-Latin; Any-Latin; NFD; [:Nonspacing Mark:] Remove; NFC; Latin-ASCII; Any-Lower;";
        $transliterator = Transliterator::create($rule);
        if ($transliterator) $text = $transliterator->transliterate($text);

        // 4. Sanitasi Slug
        $clean = preg_replace('/[^a-z0-9\-]/u', '-', $text);
        $clean = preg_replace('/-+/', '-', trim($clean, '-'));

        // Fallback: jika hasil processing kosong, gunakan sanitasi dari teks asli
        if (empty($clean)) {
            $fallback = preg_replace('/[^a-z0-9\-]/u', '-', $original_text);
            $fallback = preg_replace('/-+/', '-', trim($fallback, '-'));
            return $fallback ?: 'untitled';
        }

        return $clean;
    }
}

// ─── ANALISIS GABUNGAN (romaji + english) ─────────────────────────────────────
if (!function_exists('analyzeJapaneseText')) {
    function analyzeJapaneseText(string $text): array
    {
        $result = ['romaji' => 'untitled-media', 'english' => ''];
        if (empty(trim($text))) return $result;

        // 1. Preprocessing
        $search  = ['×', 'x', 'X', '*', '&', '/', '【', '】', '「', '」', '(', ')', '鏡音', '巡音', '初音'];
        $replace = [' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', 'かがみね', 'めぐりね', 'hatsune'];
        $original_text = $text; // Simpan asli untuk fallback
        $clean_text = str_replace($search, $replace, $text);

        // 2. MeCab — 1x panggil untuk kedua kebutuhan (path absolut)
        $mecab_bin = getMecabPath();
        $descriptorspec = [0 => ["pipe", "r"], 1 => ["pipe", "w"]];
        $process = proc_open(escapeshellarg($mecab_bin), $descriptorspec, $pipes);
        if (!is_resource($process)) {
            $result['romaji'] = getRomajiName($text);
            return $result;
        }
        fwrite($pipes[0], $clean_text);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        // 3. Koneksi kamus offline (static — sekali per request)
        static $pdo = null, $dict_ready = null, $dict_stmt = null;
        if ($dict_ready === null) {
            $dict_path = __DIR__ . '/../assets/dict/jmdict.sqlite3';
            if (file_exists($dict_path)) {
                try {
                    $pdo        = new PDO('sqlite:' . $dict_path);
                    $dict_stmt  = $pdo->prepare("SELECT glosses FROM entries WHERE reading = :w LIMIT 1");
                    $dict_ready = true;
                } catch (RuntimeException $e) {
                    $dict_ready = false;
                }
            } else {
                $dict_ready = false;
            }
        }

        $parsed_romaji = '';
        $glosses = [];

        foreach (explode("\n", trim($output)) as $line) {
            if ($line === 'EOS' || trim($line) === '') continue;
            $parts = explode("\t", $line);
            if (count($parts) < 2) continue;

            $surface  = $parts[0];
            $features = explode(',', $parts[1]);

            $yomi = '*';
            if (isset($features[7]) && $features[7] !== '*') $yomi = $features[7];
            elseif (isset($features[8]) && $features[8] !== '*') $yomi = $features[8];
            $parsed_romaji .= ' ' . (($yomi !== '*' && !preg_match('/[a-zA-Z]/', $yomi)) ? $yomi : $surface);

            if ($dict_ready) {
                $base_form = $features[6] ?? '*';
                foreach (array_unique([$surface, $base_form]) as $candidate) {
                    if ($candidate === '*' || $candidate === '') continue;
                    $dict_stmt->execute([':w' => $candidate]);
                    $row = $dict_stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row && !empty($row['glosses'])) {
                        $glosses[] = explode(';', $row['glosses'])[0];
                        break;
                    }
                }
            }
        }

        // Finalisasi romaji
        $romaji_text = trim($parsed_romaji);
        $rule = "Katakana-Latin; Any-Latin; NFD; [:Nonspacing Mark:] Remove; NFC; Latin-ASCII; Any-Lower;";
        $transliterator = Transliterator::create($rule);
        if ($transliterator) $romaji_text = $transliterator->transliterate($romaji_text);
        $clean = preg_replace('/[^a-z0-9\-]/u', '-', $romaji_text);
        $clean = preg_replace('/-+/', '-', trim($clean, '-'));

        // Fallback: jika hasil processing kosong, gunakan sanitasi dari teks asli
        if (empty($clean)) {
            $fallback = preg_replace('/[^a-z0-9\-]/u', '-', $original_text);
            $fallback = preg_replace('/-+/', '-', trim($fallback, '-'));
            $result['romaji'] = $fallback ?: 'untitled';
        } else {
            $result['romaji'] = $clean;
        }

        $result['english'] = trim(implode(' ', array_unique($glosses)));
        return $result;
    }
}

// ─── ENGLISH TRANSLATION (OFFLINE) ────────────────────────────────────────────
if (!function_exists('getEnglishTranslation')) {
    function getEnglishTranslation(string $text): string
    {
        static $pdo = null, $dict_ready = null;

        if ($dict_ready === null) {
            $dict_path = __DIR__ . '/../assets/dict/jmdict.sqlite3';
            if (file_exists($dict_path)) {
                try {
                    $pdo = new PDO('sqlite:' . $dict_path);
                    $dict_ready = true;
                } catch (RuntimeException $e) {
                    $dict_ready = false;
                }
            } else {
                $dict_ready = false;
            }
        }

        if (!$dict_ready || empty(trim($text))) return '';

        $mecab_bin = getMecabPath();
        $descriptorspec = [0 => ["pipe", "r"], 1 => ["pipe", "w"]];
        $process = proc_open(escapeshellarg($mecab_bin), $descriptorspec, $pipes);
        if (!is_resource($process)) return '';

        fwrite($pipes[0], $text);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        $stmt = $pdo->prepare("SELECT glosses FROM entries WHERE reading = :w LIMIT 1");
        $glosses = [];

        foreach (explode("\n", trim($output)) as $line) {
            if ($line === 'EOS' || trim($line) === '') continue;
            $parts = explode("\t", $line);
            if (count($parts) < 2) continue;

            $surface  = $parts[0];
            $features = explode(',', $parts[1]);
            $base_form = $features[6] ?? '*';

            foreach (array_unique([$surface, $base_form]) as $candidate) {
                if ($candidate === '*' || $candidate === '') continue;
                $stmt->execute([':w' => $candidate]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && !empty($row['glosses'])) {
                    $glosses[] = explode(';', $row['glosses'])[0];
                    break;
                }
            }
        }

        return trim(implode(' ', array_unique($glosses)));
    }
}
