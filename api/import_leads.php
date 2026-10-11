<?php
// api/import_leads.php
// Endpoint untuk menerima bulk leads dari scraper lokal
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed. Gunakan POST.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../config/database.php';

// 1. Validasi API Key
$configuredApiKey = getSetting('crm_api_key', '');
if (empty($configuredApiKey)) {
    // Jika belum diset di pengaturan cPanel, buat kunci default otomatis agar sistem tetap aman
    $configuredApiKey = 'mls_key_' . bin2hex(random_bytes(16));
    saveSetting('crm_api_key', $configuredApiKey);
}

$providedKey = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['api_key'] ?? '');
if (empty($providedKey) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    if (preg_match('/Bearer\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
        $providedKey = trim($matches[1]);
    }
}

if ($providedKey !== $configuredApiKey) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Unauthorized: API Key salah atau belum diisi di Scraper Lokal.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. Baca Input JSON
$rawInput = file_get_contents('php://input');
$body = json_decode($rawInput, true);

if (!is_array($body)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Invalid JSON payload.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$leads = $body['leads'] ?? [];
if (!is_array($leads) || empty($leads)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Daftar leads kosong atau tidak valid.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = getDb();

    // Deteksi apakah tabel leads di database memiliki kolom 'search_keyword'
    static $hasSearchKeywordCol = null;
    if ($hasSearchKeywordCol === null) {
        $colCheck = $db->query("SHOW COLUMNS FROM leads LIKE 'search_keyword'")->fetch();
        $hasSearchKeywordCol = !empty($colCheck);
    }

    // Tangani Penentuan Grup
    $groupId = isset($body['group_id']) && $body['group_id'] !== '' ? (int)$body['group_id'] : null;
    $newGroupName = trim($body['new_group_name'] ?? ($body['group_name'] ?? ''));

    if (!empty($newGroupName)) {
        $groupId = getOrCreateGroup($newGroupName);
    }

    $searchKeyword = trim($body['search_keyword'] ?? ($body['keyword'] ?? ''));
    $searchLocation = trim($body['location'] ?? '');

    // Bangun kueri adaptif sesuai kolom database
    if ($hasSearchKeywordCol) {
        $insertSql = "
            INSERT INTO leads (place_id, name, category, group_id, search_keyword, address, city, phone_raw, phone_wa, rating, user_ratings_total, status)
            VALUES (:place_id, :name, :category, :group_id, :search_keyword, :address, :city, :phone_raw, :phone_wa, :rating, :user_ratings_total, 'belum_dihubungi')
            ON DUPLICATE KEY UPDATE 
                name = VALUES(name),
                category = VALUES(category),
                group_id = COALESCE(VALUES(group_id), group_id),
                search_keyword = COALESCE(VALUES(search_keyword), search_keyword),
                address = VALUES(address),
                city = COALESCE(VALUES(city), city),
                rating = VALUES(rating),
                user_ratings_total = VALUES(user_ratings_total),
                updated_at = NOW()
        ";
    } else {
        $insertSql = "
            INSERT INTO leads (place_id, name, category, group_id, address, city, phone_raw, phone_wa, rating, user_ratings_total, status)
            VALUES (:place_id, :name, :category, :group_id, :address, :city, :phone_raw, :phone_wa, :rating, :user_ratings_total, 'belum_dihubungi')
            ON DUPLICATE KEY UPDATE 
                name = VALUES(name),
                category = VALUES(category),
                group_id = COALESCE(VALUES(group_id), group_id),
                address = VALUES(address),
                city = COALESCE(VALUES(city), city),
                rating = VALUES(rating),
                user_ratings_total = VALUES(user_ratings_total),
                updated_at = NOW()
        ";
    }

    $stmt = $db->prepare($insertSql);
    $checkStmt = $db->prepare("SELECT id FROM leads WHERE phone_wa = ? LIMIT 1");

    $newlyInserted = 0;
    $updatedExisting = 0;
    $skipped = 0;

    // Gunakan Transaksi Database agar ratusan data terinsert dalam hitungan milidetik
    $db->beginTransaction();

    foreach ($leads as $lead) {
        $phoneWa = trim($lead['phone_wa'] ?? '');
        if (empty($phoneWa)) {
            $skipped++;
            continue;
        }

        $placeId = !empty($lead['place_id']) ? $lead['place_id'] : 'pl_' . md5(($lead['name'] ?? '') . $phoneWa);
        $name = trim($lead['name'] ?? 'Tanpa Nama');
        $category = trim($lead['category'] ?? 'Bisnis Lokal');
        $address = trim($lead['address'] ?? '');
        $city = !empty($lead['city']) ? trim($lead['city']) : $searchLocation;
        $phoneRaw = trim($lead['phone_raw'] ?? ($lead['phone'] ?? $phoneWa));
        $rating = (float)($lead['rating'] ?? 0.0);
        $reviews = (int)($lead['user_ratings_total'] ?? ($lead['reviews'] ?? 0));

        $checkStmt->execute([$phoneWa]);
        $exists = $checkStmt->fetch();

        $params = [
            ':place_id'           => $placeId,
            ':name'               => $name,
            ':category'           => $category,
            ':group_id'           => $groupId,
            ':address'            => $address,
            ':city'               => $city,
            ':phone_raw'          => $phoneRaw,
            ':phone_wa'           => $phoneWa,
            ':rating'             => $rating,
            ':user_ratings_total' => $reviews,
        ];

        if ($hasSearchKeywordCol) {
            $params[':search_keyword'] = $searchKeyword;
        }

        $stmt->execute($params);

        if ($exists) {
            $updatedExisting++;
        } else {
            $newlyInserted++;
        }
    }

    $db->commit();

    $groupName = null;
    if ($groupId) {
        $gStmt = $db->prepare("SELECT name FROM lead_groups WHERE id = ?");
        $gStmt->execute([$groupId]);
        $groupName = $gStmt->fetchColumn() ?: null;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Impor data leads berhasil diproses.',
        'stats'   => [
            'total_received' => count($leads),
            'new_saved'      => $newlyInserted,
            'updated'        => $updatedExisting,
            'skipped'        => $skipped,
        ],
        'group' => [
            'id'   => $groupId,
            'name' => $groupName
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Database Error: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
