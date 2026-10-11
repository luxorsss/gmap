<?php
// actions/stream_scrape.php
if (function_exists('opcache_reset')) {
    @opcache_reset();
}
@set_time_limit(600);
@ini_set('max_execution_time', '600');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/GooglePlaces.php';

// Atur header Server-Sent Events (SSE)
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Matikan buffering di Nginx/Apache reverse proxy

// Matikan semua output buffering PHP
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

function sendSSE(array $data) {
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level() > 0) ob_flush();
    flush();
}

$keyword = trim($_GET['keyword'] ?? '');
$location = trim($_GET['location'] ?? '');
$limit = min(50, max(5, (int)($_GET['limit'] ?? 20)));
$allowSocial = isset($_GET['allow_social']) && $_GET['allow_social'] === '1';

// Opsi Grup
$groupId = isset($_GET['group_id']) && $_GET['group_id'] !== '' && $_GET['group_id'] !== '__new__' ? (int)$_GET['group_id'] : null;
$newGroupName = trim($_GET['new_group_name'] ?? '');

$db = getDb();

if (!empty($newGroupName)) {
    $groupId = getOrCreateGroup($newGroupName);
} elseif ($groupId !== null && $groupId <= 0) {
    $groupId = null;
}

$groupName = null;
if ($groupId) {
    $gStmt = $db->prepare("SELECT name FROM lead_groups WHERE id = ?");
    $gStmt->execute([$groupId]);
    $groupName = $gStmt->fetchColumn() ?: null;
}

if (empty($keyword)) {
    sendSSE(['type' => 'error', 'message' => 'Kata kunci pencarian tidak boleh kosong.']);
    exit;
}

// Ambil URL worker dan token dari database settings
$workerBaseUrl = rtrim(getSetting('scraper_worker_url', 'http://127.0.0.1:3123'), '/');
$workerToken = getSetting('scraper_secret_token', '');

// Cek apakah scraper worker dapat dihubungi
function isWorkerAlive(string $baseUrl, string $token = ''): array {
    $url = $baseUrl . '/status';
    if (!empty($token)) {
        $url .= '?token=' . urlencode($token);
    }
    $ch = @curl_init($url);
    if (!$ch) return ['alive' => false, 'error' => 'cURL init failed'];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    if (!empty($token)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Scraper-Token: ' . $token]);
    }
    $res = @curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    @curl_close($ch);

    if ($code === 200 && $res) {
        $json = json_decode($res, true);
        return ['alive' => true, 'data' => $json];
    }
    if ($code === 401) {
        return ['alive' => false, 'error' => 'Token Scraper tidak valid (401 Unauthorized)'];
    }
    return ['alive' => false, 'error' => $err ?: "HTTP Code $code"];
}

$workerCheck = isWorkerAlive($workerBaseUrl, $workerToken);

// Jika worker adalah localhost dan mati, coba nyalakan otomatis di background (hanya berlaku di server lokal)
if (!$workerCheck['alive'] && (str_contains($workerBaseUrl, '127.0.0.1') || str_contains($workerBaseUrl, 'localhost'))) {
    $serverScript = realpath(__DIR__ . '/../scraper/server.js');
    if ($serverScript && file_exists($serverScript)) {
        $nodeBin = trim(shell_exec('which node') ?: 'node');
        @exec("nohup $nodeBin " . escapeshellarg($serverScript) . " > /dev/null 2>&1 &");
        for ($i = 0; $i < 6; $i++) {
            usleep(500000);
            $workerCheck = isWorkerAlive($workerBaseUrl, $workerToken);
            if ($workerCheck['alive']) break;
        }
    }
}

if (!$workerCheck['alive']) {
    $isLocal = (str_contains($workerBaseUrl, '127.0.0.1') || str_contains($workerBaseUrl, 'localhost'));
    $errMsg = $isLocal 
        ? 'Scraper Engine (Node.js) belum aktif di port 3123. Silakan jalankan di terminal: node /srv/http/gmaps-leads/scraper/server.js'
        : 'Tidak dapat terhubung ke Worker Scraper di laptop/remote (' . htmlspecialchars($workerBaseUrl) . '). Pastikan scraper di laptop dan Cloudflare Tunnel / Ngrok sudah berjalan. Error: ' . htmlspecialchars($workerCheck['error']);

    sendSSE([
        'type'    => 'error',
        'message' => $errMsg
    ]);
    exit;
}

$fullQuery = !empty($location) ? "$keyword di $location" : $keyword;
$streamQueryParams = [
    'query' => $fullQuery,
    'max'   => $limit
];
if (!empty($workerToken)) {
    $streamQueryParams['token'] = $workerToken;
}

$workerUrl = $workerBaseUrl . '/stream?' . http_build_query($streamQueryParams);

sendSSE(['type' => 'status', 'status' => 'Menghubungkan ke Scraper Engine...', 'scanned' => 0, 'qualified' => 0]);

$insertStmt = $db->prepare("
    INSERT INTO leads (place_id, name, category, group_id, search_keyword, address, city, phone_raw, phone_wa, rating, user_ratings_total, status)
    VALUES (:place_id, :name, :category, :group_id, :search_keyword, :address, :city, :phone_raw, :phone_wa, :rating, :user_ratings_total, 'belum_dihubungi')
    ON DUPLICATE KEY UPDATE 
        name = VALUES(name),
        category = VALUES(category),
        group_id = COALESCE(VALUES(group_id), group_id),
        search_keyword = COALESCE(VALUES(search_keyword), search_keyword),
        address = VALUES(address),
        rating = VALUES(rating),
        user_ratings_total = VALUES(user_ratings_total),
        updated_at = NOW()
");

$checkStmt = $db->prepare("SELECT id FROM leads WHERE phone_wa = ? LIMIT 1");

$waTemplate = getSetting('wa_template', '');

$stats = [
    'scanned'   => 0,
    'qualified' => 0,
    'new'       => 0,
    'existing'  => 0,
];

$seenBatchPhones = [];
$hasSentDone = false;

$ch = curl_init($workerUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 600);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

// Buffer parsing baris SSE dari Node.js worker
$lineBuffer = '';

curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (
    &$lineBuffer, &$stats, &$seenBatchPhones, &$hasSentDone, $db, $insertStmt, $checkStmt, $location, $allowSocial, $waTemplate, $fullQuery, $groupId, $groupName
) {
    $lineBuffer .= $data;
    while (($pos = strpos($lineBuffer, "\n\n")) !== false) {
        $eventBlock = substr($lineBuffer, 0, $pos);
        $lineBuffer = substr($lineBuffer, $pos + 2);

        $lines = explode("\n", $eventBlock);
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'data: ')) {
                $payloadJson = substr($line, 6);
                $payload = json_decode($payloadJson, true);
                if (!$payload) continue;

                if ($payload['type'] === 'progress') {
                    sendSSE([
                        'type'    => 'progress',
                        'status'  => $payload['status'],
                        'scanned' => $payload['scanned'] ?? $stats['scanned'],
                        'found'   => $stats['qualified']
                    ]);
                } elseif ($payload['type'] === 'item' && !empty($payload['place'])) {
                    $item = $payload['place'];
                    $stats['scanned'] = $payload['scanned'] ?? ($stats['scanned'] + 1);

                    // Filter Website
                    $web = trim($item['website'] ?? '');
                    if (!empty($web)) {
                        if ($allowSocial) {
                            $host = parse_url($web, PHP_URL_HOST) ?? '';
                            $socialHosts = ['instagram.com', 'facebook.com', 'linktr.ee', 'wa.me', 'api.whatsapp.com', 'tiktok.com', 'blogspot.com'];
                            $isSocial = false;
                            foreach ($socialHosts as $sh) {
                                if (str_contains(strtolower($host), $sh)) {
                                    $isSocial = true;
                                    break;
                                }
                            }
                            if (!$isSocial) {
                                // Punya website resmi -> lewati
                                sendSSE([
                                    'type'    => 'progress',
                                    'status'  => "Dilewati: {$item['name']} (sudah punya website resmi)",
                                    'scanned' => $stats['scanned'],
                                    'found'   => $stats['qualified']
                                ]);
                                continue;
                            }
                        } else {
                            continue;
                        }
                    }

                    // Filter dan normalisasi nomor WhatsApp
                    $phoneRaw = $item['phone'] ?? null;
                    $phoneWa = GooglePlacesService::normalizeWhatsAppNumber($phoneRaw);
                    if (!$phoneWa) {
                        sendSSE([
                            'type'    => 'progress',
                            'status'  => "Dilewati: {$item['name']} (bukan nomor HP WhatsApp)",
                            'scanned' => $stats['scanned'],
                            'found'   => $stats['qualified']
                        ]);
                        continue;
                    }

                    // Anti-duplikasi di batch ini (hanya berdasarkan nomor WA)
                    if (isset($seenBatchPhones[$phoneWa])) {
                        continue;
                    }
                    $seenBatchPhones[$phoneWa] = true;

                    // Simpan ke database
                    $checkStmt->execute([$phoneWa]);
                    $isExisting = $checkStmt->fetch();

                    $addr = $item['address'] ?? '';
                    $city = '';
                    if (preg_match('/Kota [a-zA-Z\s]+|Kabupaten [a-zA-Z\s]+|Jakarta [a-zA-Z\s]+/i', $addr, $m)) {
                        $city = trim($m[0]);
                    }

                    $insertStmt->execute([
                        ':place_id'           => $item['place_id'] ?: md5($item['name'] . $phoneWa),
                        ':name'               => $item['name'],
                        ':category'           => $item['category'] ?: 'Bisnis Lokal',
                        ':group_id'           => $groupId,
                        ':search_keyword'     => $fullQuery,
                        ':address'            => $addr,
                        ':city'               => !empty($city) ? $city : $location,
                        ':phone_raw'          => $phoneRaw,
                        ':phone_wa'           => $phoneWa,
                        ':rating'             => (float)($item['rating'] ?? 0.0),
                        ':user_ratings_total' => (int)($item['user_ratings_total'] ?? 0),
                    ]);

                    if ($isExisting) {
                        $stats['existing']++;
                    } else {
                        $stats['new']++;
                    }
                    $stats['qualified']++;

                    // Generate link WA
                    $waMsg = $waTemplate;
                    $waMsg = str_replace('[nama_usaha]', $item['name'], $waMsg);
                    $waMsg = str_replace('[kategori]', $item['category'] ?: 'Bisnis', $waMsg);
                    $waMsg = str_replace('[alamat]', $addr, $waMsg);
                    $waMsg = str_replace('[kota]', $city ?: $location, $waMsg);
                    $waMsg = str_replace('[rating]', (string)$item['rating'], $waMsg);
                    $waUrl = "https://api.whatsapp.com/send?phone={$phoneWa}&text=" . rawurlencode($waMsg);

                    // Kirim ke browser secara live!
                    sendSSE([
                        'type' => 'lead_found',
                        'lead' => [
                            'name'               => $item['name'],
                            'category'           => $item['category'] ?: 'Bisnis Lokal',
                            'group_name'         => $groupName,
                            'address'            => $addr,
                            'city'               => $city ?: $location,
                            'phone_wa'           => $phoneWa,
                            'rating'             => (float)($item['rating'] ?? 0.0),
                            'user_ratings_total' => (int)($item['user_ratings_total'] ?? 0),
                            'is_new'             => !$isExisting,
                            'wa_url'             => $waUrl
                        ],
                        'stats' => $stats
                    ]);
                } elseif ($payload['type'] === 'done') {
                    if (!$hasSentDone) {
                        $hasSentDone = true;
                        sendSSE([
                            'type'  => 'done',
                            'stats' => $stats
                        ]);
                    }
                } elseif ($payload['type'] === 'error') {
                    sendSSE([
                        'type'    => 'error',
                        'message' => $payload['error'] ?? 'Terjadi kesalahan pada worker scraper'
                    ]);
                }
            }
        }
    }
    return strlen($data);
});

$curlOk = curl_exec($ch);
$curlErr = curl_error($ch);
curl_close($ch);

if (!$curlOk && $curlErr) {
    sendSSE([
        'type'    => 'error',
        'message' => 'Gagal berkomunikasi dengan scraper worker: ' . $curlErr
    ]);
} elseif (!$hasSentDone) {
    $hasSentDone = true;
    sendSSE([
        'type'  => 'done',
        'stats' => $stats
    ]);
}
