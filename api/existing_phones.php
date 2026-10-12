<?php
// api/existing_phones.php
// Endpoint super-ringan untuk mengambil daftar nomor WA yang sudah ada di database cPanel
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/database.php';

// Validasi API Key
$configuredApiKey = getSetting('crm_api_key', '');
if (empty($configuredApiKey)) {
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
        'error'   => 'Unauthorized: API Key salah atau belum diisi.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = getDb();
    // Ambil hanya kolom phone_wa yang tidak kosong
    $stmt = $db->query("SELECT DISTINCT phone_wa FROM leads WHERE phone_wa IS NOT NULL AND phone_wa != ''");
    $phones = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'success' => true,
        'total'   => count($phones),
        'phones'  => $phones
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Server Error: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
