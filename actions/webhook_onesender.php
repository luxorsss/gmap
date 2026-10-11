<?php
// actions/webhook_onesender.php
// Receiver webhook dari OneSender untuk mencatat balasan pesan dari prospek
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/OneSenderService.php';

$rawPayload = file_get_contents('php://input');
if (empty($rawPayload)) {
    echo json_encode(['status' => 'ignored', 'message' => 'Empty payload']);
    exit;
}

$data = json_decode($rawPayload, true);
if (!$data || !is_array($data)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

// Hanya proses jika pesan dari luar (is_from_me == false) dan bukan grup (is_group == false)
$isFromMe = $data['is_from_me'] ?? false;
$isGroup = $data['is_group'] ?? false;
$senderPhone = $data['sender_phone'] ?? '';
$messageText = $data['message_text'] ?? ($data['context']['body'] ?? '');

if ($isFromMe || $isGroup || empty($senderPhone) || empty($messageText)) {
    echo json_encode(['status' => 'ignored', 'message' => 'Not an incoming direct user message']);
    exit;
}

$pdo = getDb();
$cleanPhone = OneSenderService::normalizePhone($senderPhone);

// Cari lead berdasarkan nomor WhatsApp
$stmt = $pdo->prepare("SELECT id, name, status, last_step_sent FROM leads WHERE phone_wa = :phone OR phone_wa LIKE :likephone LIMIT 1");
$stmt->execute([
    ':phone'     => $cleanPhone,
    ':likephone' => '%' . substr($cleanPhone, -9) // Cocokkan 9 digit terakhir jika ada variasi format
]);
$lead = $stmt->fetch();

if ($lead) {
    // Catat ke log pesan masuk
    $logStmt = $pdo->prepare("
        INSERT INTO lead_message_logs (lead_id, step_number, direction, message_text, created_at)
        VALUES (:lid, :step, 'incoming', :msg, NOW())
    ");
    $logStmt->execute([
        ':lid'  => $lead['id'],
        ':step' => $lead['last_step_sent'] ?: 1,
        ':msg'  => $messageText
    ]);

    echo json_encode([
        'status'  => 'success',
        'lead_id' => $lead['id'],
        'message' => 'Pesan masuk berhasil dicatat di log'
    ]);
    exit;
}

echo json_encode(['status' => 'not_found', 'message' => 'Lead number not found in database']);
