<?php
// actions/delete.php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID tidak valid']);
    exit;
}

$db = getDb();
try {
    $db->prepare("DELETE FROM lead_message_logs WHERE lead_id = ?")->execute([$id]);
} catch (Throwable $e) {}
$stmt = $db->prepare("DELETE FROM leads WHERE id = ?");
$stmt->execute([$id]);

echo json_encode(['success' => true]);
