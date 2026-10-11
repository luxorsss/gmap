<?php
// actions/update_status.php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$status = trim($_POST['status'] ?? '');
$notes = isset($_POST['notes']) ? trim($_POST['notes']) : null;

$validStatuses = ['belum_dihubungi', 'dihubungi', 'deal', 'batal'];

if ($id <= 0 || !in_array($status, $validStatuses, true)) {
    echo json_encode(['success' => false, 'error' => 'Status tidak valid']);
    exit;
}

$db = getDb();

// Jika status diubah jadi deal atau batal, batalkan antrean follow up otomatis
$clearFu = in_array($status, ['deal', 'batal']);

if ($notes !== null) {
    $stmt = $db->prepare("
        UPDATE leads 
        SET status = :status, 
            notes = :notes,
            last_contacted_at = IF(:status_check != 'belum_dihubungi' AND last_contacted_at IS NULL, NOW(), last_contacted_at),
            next_followup_at = IF(:clear_fu = 1, NULL, next_followup_at)
        WHERE id = :id
    ");
    $stmt->execute([
        ':status'       => $status, 
        ':status_check' => $status, 
        ':notes'        => $notes, 
        ':clear_fu'     => $clearFu ? 1 : 0,
        ':id'           => $id
    ]);
} else {
    $stmt = $db->prepare("
        UPDATE leads 
        SET status = :status,
            last_contacted_at = IF(:status_check != 'belum_dihubungi' AND last_contacted_at IS NULL, NOW(), last_contacted_at),
            next_followup_at = IF(:clear_fu = 1, NULL, next_followup_at)
        WHERE id = :id
    ");
    $stmt->execute([
        ':status'       => $status, 
        ':status_check' => $status, 
        ':clear_fu'     => $clearFu ? 1 : 0,
        ':id'           => $id
    ]);
}

echo json_encode(['success' => true]);
