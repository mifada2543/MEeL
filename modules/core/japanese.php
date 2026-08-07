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
        // Penting: kosongkan LD_LIBRARY_PATH. Di XAMPP/LAMPP, environment Apache
        // mewarisi LD_LIBRARY_PATH=/opt/lampp/lib yang berisi libstdc++.so.6 versi
        // LAMA — MeCab sistem (/usr/bin/mecab) gagal load GLIBCXX_3.4.32 dari sana
        // (error: version `GLIBCXX_*' not found). Konsisten dgn pola `export
        // LD_LIBRARY_PATH=''` yang dipakai Uploader/auto_metadata utk ffmpeg/ffprobe.
        $mecab_bin = getMecabPath();
        $descriptorspec = [0 => ["pipe", "r"], 1 => ["pipe", "w"]];
        $mecab_cmd = 'export LD_LIBRARY_PATH=\'\'; ' . escapeshellarg($mecab_bin);
        $process = proc_open($mecab_cmd, $descriptorspec, $pipes);

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

        // 1b. Alias lookup (brand/franchise/nama di luar JMdict) — substring match
        static $aliases = null;
        if ($aliases === null) {
            $alias_path = __DIR__ . '/japanese_aliases.php';
            $aliases = file_exists($alias_path) ? require $alias_path : [];
            // Frasa terpanjang dulu — cegah duplikasi untuk substring yang tumpang tindih
            uksort($aliases, fn($a, $b) => mb_strlen($b) - mb_strlen($a));
        }

        $alias_glosses = [];
        foreach ($aliases as $phrase => $translation) {
            if ($phrase !== '' && mb_strpos($original_text, $phrase) !== false) {
                $alias_glosses[] = $translation;
            }
        }

        // 2. MeCab — 1x panggil untuk kedua kebutuhan (path absolut)
        // LD_LIBRARY_PATH dikosongkan (sama seperti getRomajiName): di XAMPP,
        // environment Apache berisi LD_LIBRARY_PATH=/opt/lampp/lib yang merusak
        // load libstdc++ MeCab sistem (GLIBCXX_* not found) → MeCab gagal total.
        $mecab_bin = getMecabPath();
        $descriptorspec = [0 => ["pipe", "r"], 1 => ["pipe", "w"]];
        $mecab_cmd = 'export LD_LIBRARY_PATH=\'\'; ' . escapeshellarg($mecab_bin);
        $process = proc_open($mecab_cmd, $descriptorspec, $pipes);
        if (!is_resource($process)) {
            $result['romaji'] = getRomajiName($text);
            // Alias tetap dipakai walau MeCab gagal dibuka (tidak menyentuh logic MeCab)
            $result['english'] = trim(implode(' ', array_unique($alias_glosses)));
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
            // File ini berada di modules/core/, jadi naik 2 level ke root project
            // (sebelumnya ../ saja → resolve ke modules/assets/ yang tidak ada).
            $dict_path = __DIR__ . '/../../assets/dict/jmdict.sqlite3';
            if (file_exists($dict_path)) {
                try {
                    $pdo        = new PDO('sqlite:' . $dict_path);
                    $dict_stmt  = $pdo->prepare("SELECT glosses FROM entries WHERE reading = :w LIMIT 1");
                    $dict_ready = true;
                } catch (RuntimeException $e) {
                    $dict_ready = false;
                    error_log('[japanese.php] Gagal buka jmdict.sqlite3: ' . $e->getMessage());
                }
            } else {
                $dict_ready = false;
                error_log('[japanese.php] Dictionary tidak ditemukan di: ' . $dict_path);
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

        // Alias (brand/franchise) didahulukan, lalu glosses JMdict
        $glosses = array_merge($alias_glosses, $glosses);
        $result['english'] = trim(implode(' ', array_unique($glosses)));
        return $result;
    }
}

