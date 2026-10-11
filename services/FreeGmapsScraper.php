<?php
// services/FreeGmapsScraper.php
require_once __DIR__ . '/GooglePlaces.php';

class FreeGmapsScraper {
    /**
     * Jalankan Puppeteer scraper di latar belakang tanpa API key / kartu kredit
     */
    public static function scrape(string $query, int $maxResults = 20): array {
        // Cegah timeout pada eksekusi scraper
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        // 1. Ambil URL worker dan token dari database settings
        $workerBaseUrl = rtrim(getSetting('scraper_worker_url', 'http://127.0.0.1:3123'), '/');
        $workerToken = getSetting('scraper_secret_token', '');

        $queryParams = [
            'query' => $query,
            'max'   => $maxResults
        ];
        if (!empty($workerToken)) {
            $queryParams['token'] = $workerToken;
        }

        $workerUrl = $workerBaseUrl . '/scrape?' . http_build_query($queryParams);

        $ch = curl_init($workerUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        if (!empty($workerToken)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'X-Scraper-Token: ' . $workerToken
            ]);
        }
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200 && $resp) {
            $data = json_decode($resp, true);
            if (is_array($data) && isset($data['success'])) {
                return $data;
            }
        } elseif ($httpCode === 401) {
            return [
                'success' => false,
                'error'   => 'Akses ditolak (401): Token Scraper tidak cocok. Silakan cek Pengaturan.'
            ];
        }

        // 2. Fallback eksekusi langsung CLI (jika dijalankan via terminal atau php -S)
        $scriptPath = realpath(__DIR__ . '/../scraper/scrape.js');
        if (!$scriptPath || !file_exists($scriptPath)) {
            return [
                'success' => false,
                'error'   => 'Script scraper/scrape.js tidak ditemukan.'
            ];
        }

        $projectDir = realpath(__DIR__ . '/..');
        $cacheDir = $projectDir . '/.cache/puppeteer';
        $nodeBin = trim(shell_exec('which node') ?: 'node');

        $envPrefix = 'HOME=' . escapeshellarg($projectDir) . ' PUPPETEER_CACHE_DIR=' . escapeshellarg($cacheDir);
        $tempOut = tempnam(sys_get_temp_dir(), 'gmaps_');
        $cmd = $envPrefix . ' ' . escapeshellcmd($nodeBin) . ' ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($query) . ' ' . (int)$maxResults . ' > ' . escapeshellarg($tempOut) . ' 2>&1';

        exec($cmd);
        $output = file_get_contents($tempOut);
        @unlink($tempOut);

        @file_put_contents($projectDir . '/.cache/scraper_last_run.log', $output);

        if (!$output) {
            return [
                'success' => false,
                'error'   => 'Gagal menjalankan Puppeteer scraper. Pastikan Node.js terpasang.'
            ];
        }

        if (preg_match('/__GMAPS_ERROR_START__\s*(.*?)\s*__GMAPS_ERROR_END__/s', $output, $errMatches)) {
            return [
                'success' => false,
                'error'   => 'Scraper error: ' . trim($errMatches[1])
            ];
        }

        if (preg_match('/__GMAPS_RESULT_START__\s*(.*?)\s*__GMAPS_RESULT_END__/s', $output, $matches)) {
            $jsonStr = trim($matches[1]);
            $rawItems = json_decode($jsonStr, true);

            if (is_array($rawItems)) {
                return [
                    'success' => true,
                    'places'  => $rawItems
                ];
            }

            return [
                'success' => false,
                'error'   => 'Gagal membaca format JSON: ' . json_last_error_msg()
            ];
        }

        return [
            'success' => false,
            'error'   => 'Scraper tidak mengembalikan hasil. Cuplikan log: ' . substr(strip_tags($output), 0, 250)
        ];
    }

    /**
     * Filter data hasil Puppeteer: hanya yang TIDAK PUNYA WEBSITE dan PUNYA NOMOR WHATSAPP
     * Plus deduplikasi ketat (1 nomor telepon WA = 1 prospek unik)
     */
    public static function filterAndFormat(array $rawPlaces, bool $allowSocialLinksAsNoWebsite = true): array {
        $qualified = [];
        $seenPhones = [];
        $seenNames = [];

        foreach ($rawPlaces as $place) {
            $website = trim($place['website'] ?? '');

            // Cek apakah ada website
            if (!empty($website)) {
                if ($allowSocialLinksAsNoWebsite) {
                    $host = parse_url($website, PHP_URL_HOST) ?? '';
                    $socialHosts = ['instagram.com', 'facebook.com', 'linktr.ee', 'wa.me', 'api.whatsapp.com', 'tiktok.com', 'blogspot.com'];
                    $isSocial = false;
                    foreach ($socialHosts as $sh) {
                        if (str_contains(strtolower($host), $sh)) {
                            $isSocial = true;
                            break;
                        }
                    }

                    if (!$isSocial) {
                        continue; // Punya website resmi -> Lewati
                    }
                } else {
                    continue; // Punya website apa pun -> Lewati
                }
            }

            // Ambil dan validasi nomor WA
            $phoneRaw = $place['phone'] ?? null;
            $phoneWa = GooglePlacesService::normalizeWhatsAppNumber($phoneRaw);

            if (!$phoneWa) {
                continue; // Bukan nomor seluler/WA -> Lewati
            }

            // Anti-Duplikasi Ketat: Cek apakah nomor WA atau Nama sudah ada di batch ini
            $normName = strtolower(trim($place['name'] ?? ''));
            if (isset($seenPhones[$phoneWa]) || (!empty($normName) && isset($seenNames[$normName]))) {
                continue;
            }
            $seenPhones[$phoneWa] = true;
            if (!empty($normName)) {
                $seenNames[$normName] = true;
            }

            $address = $place['address'] ?? '';
            $city = '';
            if (preg_match('/Kota [a-zA-Z\s]+|Kabupaten [a-zA-Z\s]+|Jakarta [a-zA-Z\s]+/i', $address, $matches)) {
                $city = trim($matches[0]);
            }

            $qualified[] = [
                'place_id'           => $place['place_id'] ?: md5($place['name'] . $phoneWa),
                'name'               => $place['name'],
                'category'           => $place['category'] ?: 'Bisnis Lokal',
                'address'            => $address,
                'city'               => $city,
                'phone_raw'          => $phoneRaw,
                'phone_wa'           => $phoneWa,
                'rating'             => (float)($place['rating'] ?? 0.0),
                'user_ratings_total' => (int)($place['user_ratings_total'] ?? 0),
            ];
        }

        return $qualified;
    }
}
