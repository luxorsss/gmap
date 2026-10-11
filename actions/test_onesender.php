<?php
// actions/test_onesender.php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/OneSenderService.php';

try {
    $apiUrl = trim($_POST['api_url'] ?? getSetting('onesender_api_url', 'http://localhost:3001/api/v1'));
    $token = trim($_POST['token'] ?? getSetting('onesender_token', ''));

    $service = new OneSenderService();
    $result = $service->testConnection($apiUrl, $token);

    echo json_encode([
        'success'   => $result['connected'],
        'message'   => $result['message'],
        'http_code' => $result['http_code'] ?? null
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
