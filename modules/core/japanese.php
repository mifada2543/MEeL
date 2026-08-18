<?php
// HELPER: Resolve MeCab binary (static cache per request)
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

// ROMAJI CONVERTER
if (!function_exists('getRomajiName')) {
    function getRomajiName(string $text): string
    {
        if (empty($text)) return 'untitled';
        $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
        $original_text = $text;

        // Kamus koreksi karakter spesifik & simbol
        $search = [
            '×',
            'x',
            'X',
            '*',
            '&',
            '/',
            '【',
            '】',
            '「',
            '」',
            '(',
            ')',
            '鏡音',
            '巡音',
            '初音'
        ];
        $replace = [
            ' ',
            ' ',
            ' ',
            ' ',
            ' ',
            ' ',
            ' ',
            ' ',
            ' ',
            ' ',
            ' ',
            ' ',
            'かがみね',
            'めぐりね',
            'hatsune'
        ];
        $text = str_replace($search, $replace, $text);

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

        $rule = "Katakana-Latin; Any-Latin; NFD; [:Nonspacing Mark:] Remove; NFC; Latin-ASCII; Any-Lower;";
        $transliterator = Transliterator::create($rule);
        if ($transliterator) $text = $transliterator->transliterate($text);

        $clean = preg_replace('/[^a-z0-9\-]/u', '-', $text);
        $clean = preg_replace('/-+/', '-', trim($clean, '-'));

        if (empty($clean)) {
            $fallback = preg_replace('/[^a-z0-9\-]/u', '-', $original_text);
            $fallback = preg_replace('/-+/', '-', trim($fallback, '-'));
            return $fallback ?: 'untitled';
        }

        return $clean;
    }
}

// ANALISIS GABUNGAN (romaji + english)
if (!function_exists('analyzeJapaneseText')) {
    function analyzeJapaneseText(string $text): array
    {
        $result = ['romaji' => 'untitled-media', 'english' => ''];
        if (empty(trim($text))) return $result;
        $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;

        $search  = ['×', 'x', 'X', '*', '&', '/', '【', '】', '「', '」', '(', ')', '鏡音', '巡音', '初音'];
        $replace = [' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', 'かがみね', 'めぐりね', 'hatsune'];
        $original_text = $text; // Simpan asli untuk fallback
        $clean_text = str_replace($search, $replace, $text);

        static $aliases = null;
        if ($aliases === null) {
            $alias_path = __DIR__ . '/japanese_aliases.php';
            $aliases = file_exists($alias_path) ? require $alias_path : [];

            uksort($aliases, fn($a, $b) => mb_strlen($b) - mb_strlen($a));
        }

        $alias_glosses = [];
        $full_cover    = null; // alias yang menutupi seluruh teks → dipakai sebagai terjemahan final
        foreach ($aliases as $phrase => $translation) {
            if ($phrase !== '' && mb_strpos($original_text, $phrase) !== false) {
                $alias_glosses[$phrase] = $translation;
                if (trim($phrase) === trim($original_text)) {
                    $full_cover = $translation;
                }
            }
        }
        // Buang alias yang merupakan substring dari alias lain yang lebih panjang
        // (mis. 'ならば' ⊆ 'のならば') — hindari duplikasi makna seperti "in case if".
        foreach (array_keys($alias_glosses) as $p1) {
            foreach (array_keys($alias_glosses) as $p2) {
                if ($p1 !== $p2 && mb_strlen($p2) > mb_strlen($p1) && mb_strpos($p2, $p1) !== false) {
                    unset($alias_glosses[$p1]);
                    break;
                }
            }
        }
        $matched_phrases = array_keys($alias_glosses);
        $alias_glosses = array_values($alias_glosses);

        // MeCab — 1x panggil untuk kedua kebutuhan (path absolut)
        $mecab_bin = getMecabPath();
        $descriptorspec = [0 => ["pipe", "r"], 1 => ["pipe", "w"]];
        $mecab_cmd = 'export LD_LIBRARY_PATH=\'\'; ' . escapeshellarg($mecab_bin);
        $process = proc_open($mecab_cmd, $descriptorspec, $pipes);
        if (!is_resource($process)) {
            $result['romaji'] = getRomajiName($text);

            $result['english'] = trim(implode(' ', array_unique($alias_glosses)));
            return $result;
        }
        fwrite($pipes[0], $clean_text);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        // Koneksi kamus offline (static — sekali per request)
        static $pdo = null, $dict_ready = null, $dict_stmt = null;
        if ($dict_ready === null) {

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

            // FILTER KATA FUNGSIONAL
            // Partikel & kata fungsional tidak diterjemahkan per-token:
            // lookup JMdict berbasis reading rentan homofon tanpa konteks frasa.
            $pos = $features[0] ?? '';
            $sub = $features[1] ?? '';
            $is_functional = in_array($pos, ['助詞', '助動詞', '接続詞', '感動詞', '連体詞', '記号', '補助記号'], true)
                || ($pos === '名詞' && $sub === '非自立' && $surface === 'の');
            $inside_alias = false;
            foreach ($matched_phrases as $phrase) {
                if ($phrase !== '' && mb_strpos($phrase, $surface) !== false) {
                    $inside_alias = true;
                    break;
                }
            }

            if ($dict_ready && !$is_functional && !$inside_alias) {
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

        if (empty($clean)) {
            $fallback = preg_replace('/[^a-z0-9\-]/u', '-', $original_text);
            $fallback = preg_replace('/-+/', '-', trim($fallback, '-'));
            $result['romaji'] = $fallback ?: 'untitled';
        } else {
            $result['romaji'] = $clean;
        }

        // Alias frasa penuh didahulukan; gloss per-token merusak makna frasa.
        if ($full_cover !== null) {
            $result['english'] = $full_cover;
        } else {
            $glosses = array_merge($alias_glosses, $glosses);
            $result['english'] = trim(implode(' ', array_unique($glosses)));
        }
        return $result;
    }
}
