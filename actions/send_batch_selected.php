<?php
// actions/send_batch_selected.php
// Kirim Pesan 1 ke prospek-prospek yang dipilih (dicentang) di tabel
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/OneSenderService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Metode request tidak valid']);
    exit;
}

$rawIds = $_POST['ids'] ?? [];
if (!is_array($rawIds) && !empty($rawIds)) {
    $rawIds = explode(',', (string)$rawIds);
}

$ids = array_filter(array_map('intval', (array)$rawIds), function($id) {
    return $id > 0;
});

if (empty($ids)) {
    echo json_encode(['success' => false, 'error' => 'Pilih setidaknya satu prospek.']);
    exit;
}

try {
    $pdo = getDb();
    $service = new OneSenderService($pdo);

    // Ambil prospek yang valid untuk Pesan 1 (belum_dihubungi dan last_step_sent = 0)
    $inQuery = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("
        SELECT id, name, category, address, city, phone_wa, rating, group_id, last_step_sent, status 
        FROM leads 
        WHERE id IN ($inQuery) 
          AND status = 'belum_dihubungi' 
          AND last_step_sent = 0
    ");
    $stmt->execute(array_values($ids));
    $leads = $stmt->fetchAll();

    if (empty($leads)) {
        echo json_encode([
            'success' => false,
            'error'   => 'Tidak ada prospek terpilih yang berstatus "Belum Dihubungi" (Pesan 1).'
        ]);
        exit;
    }

    $messagesPayload = [];
    $preparedLeads = [];

    foreach ($leads as $lead) {
        $cleanPhone = OneSenderService::normalizePhone($lead['phone_wa'] ?? '');
        if (empty($cleanPhone)) {
            continue;
        }

        $templateRow = $service->getTemplateForGroup($lead['group_id'], 1);
        if (!$templateRow || empty(trim($templateRow['message_text']))) {
            continue;
        }

        $formattedMsg = OneSenderService::formatMessage($templateRow['message_text'], $lead);

        $messagesPayload[] = [
            'recipient_type' => 'individual',
            'to'             => $cleanPhone,
            'type'           => 'text',
            'text'           => [
                'body' => $formattedMsg
            ]
        ];

        $preparedLeads[] = [
            'lead'    => $lead,
            'message' => $formattedMsg
        ];
    }

    if (empty($messagesPayload)) {
        throw new Exception('Template Pesan 1 belum diatur atau nomor WA prospek tidak valid.');
    }

    // Kirim massal ke OneSender
    $sendResult = $service->sendMessages($messagesPayload);

    if (!$sendResult['success']) {
        throw new Exception('Gagal mengirim WhatsApp via OneSender: ' . ($sendResult['error'] ?? 'Unknown error'));
    }

    // Update database & log
    $stmtLog = $pdo->prepare("
        INSERT INTO lead_message_logs (lead_id, step_number, direction, message_text, created_at)
        VALUES (:lid, 1, 'outgoing', :msg, NOW())
    ");

    $updateStmt = $pdo->prepare("
        UPDATE leads 
        SET status = 'dihubungi',
            last_step_sent = 1,
            step_1_sent_at = NOW(),
            last_contacted_at = NOW()
        WHERE id = :id
    ");

    $successCount = 0;
    foreach ($preparedLeads as $item) {
        $leadId = $item['lead']['id'];
        $updateStmt->execute([':id' => $leadId]);
        $stmtLog->execute([
            ':lid' => $leadId,
            ':msg' => $item['message']
        ]);
        $successCount++;
    }

    echo json_encode([
        'success' => true,
        'count'   => $successCount,
        'message' => "Berhasil mengirim Pesan 1 ke {$successCount} prospek terpilih!"
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}
