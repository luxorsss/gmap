<?php
// api/groups.php
// Endpoint untuk mengambil daftar grup leads untuk integrasi scraper eksternal
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
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

try {
    $groups = getAllGroups();
    $formatted = array_map(function($g) {
        return [
            'id'          => (int)$g['id'],
            'name'        => $g['name'],
            'color'       => $g['color'],
            'total_leads' => (int)$g['total_leads']
        ];
    }, $groups);

    echo json_encode([
        'success' => true,
        'groups'  => $formatted
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Server Error: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
