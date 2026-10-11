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
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed. Use POST.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../config/database.php';

// Validasi API Key
$configuredApiKey = getSetting('crm_api_key', '');
if (!empty($configuredApiKey)) {
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
            'error'   => 'Unauthorized: API Key tidak valid atau belum diisi.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

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

$db = getDb();

// Tangani Penentuan Grup
$groupId = isset($body['group_id']) && $body['group_id'] !== '' ? (int)$body['group_id'] : null;
$newGroupName = trim($body['new_group_name'] ?? ($body['group_name'] ?? ''));

if (!empty($newGroupName)) {
    $groupId = getOrCreateGroup($newGroupName);
}

$searchKeyword = trim($body['search_keyword'] ?? ($body['keyword'] ?? ''));
$searchLocation = trim($body['location'] ?? '');

$stmt = $db->prepare("
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
");

$checkStmt = $db->prepare("SELECT id FROM leads WHERE phone_wa = ? LIMIT 1");

$newlyInserted = 0;
$updatedExisting = 0;
$skipped = 0;

foreach ($leads as $lead) {
    $phoneWa = trim($lead['phone_wa'] ?? '');
    if (empty($phoneWa)) {
        $skipped++;
        continue;
    }

    $placeId = !empty($lead['place_id']) ? $lead['place_id'] : 'pl_' . md5($lead['name'] . $phoneWa);
    $name = trim($lead['name'] ?? 'Tanpa Nama');
    $category = trim($lead['category'] ?? 'Bisnis Lokal');
    $address = trim($lead['address'] ?? '');
    $city = !empty($lead['city']) ? trim($lead['city']) : $searchLocation;
    $phoneRaw = trim($lead['phone_raw'] ?? ($lead['phone'] ?? $phoneWa));
    $rating = (float)($lead['rating'] ?? 0.0);
    $reviews = (int)($lead['user_ratings_total'] ?? ($lead['reviews'] ?? 0));

    $checkStmt->execute([$phoneWa]);
    $exists = $checkStmt->fetch();

    $stmt->execute([
        ':place_id'           => $placeId,
        ':name'               => $name,
        ':category'           => $category,
        ':group_id'           => $groupId,
        ':search_keyword'     => $searchKeyword,
        ':address'            => $address,
        ':city'               => $city,
        ':phone_raw'          => $phoneRaw,
        ':phone_wa'           => $phoneWa,
        ':rating'             => $rating,
        ':user_ratings_total' => $reviews,
    ]);

    if ($exists) {
        $updatedExisting++;
    } else {
        $newlyInserted++;
    }
}

// Ambil nama grup aktual jika ada
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
