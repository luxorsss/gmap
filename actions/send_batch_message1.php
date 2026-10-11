<?php
// actions/send_batch_message1.php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/OneSenderService.php';

$pdo = getDb();

try {
    $groupId = isset($_POST['group_id']) && $_POST['group_id'] !== '' ? (int)$_POST['group_id'] : null;
    $limit = isset($_POST['limit']) ? max(1, min(100, (int)$_POST['limit'])) : 10;

    if ($groupId === null) {
        throw new Exception('Silakan pilih grup terlebih dahulu untuk mengirim Pesan 1 massal.');
    }

    // Ambil info grup
    $stmtGroup = $pdo->prepare("SELECT id, name FROM lead_groups WHERE id = :gid");
    $stmtGroup->execute([':gid' => $groupId]);
    $group = $stmtGroup->fetch();
    if (!$group) {
        throw new Exception('Grup tidak ditemukan.');
    }

    $service = new OneSenderService($pdo);

    // Ambil template langkah 1 untuk grup ini
    $templateRow = $service->getTemplateForGroup($groupId, 1);
    if (!$templateRow || empty(trim($templateRow['message_text']))) {
        throw new Exception('Template Pesan 1 untuk grup ini belum diatur. Silakan atur di menu Pengaturan.');
    }
    $templateText = $templateRow['message_text'];

    // Ambil prospek dalam grup ini yang statusnya 'belum_dihubungi'
    $stmtLeads = $pdo->prepare("
        SELECT id, name, category, address, city, phone_wa, rating 
        FROM leads 
        WHERE group_id = :gid 
          AND status = 'belum_dihubungi'
          AND last_step_sent = 0
        ORDER BY id ASC 
        LIMIT :limit
    ");
    $stmtLeads->bindValue(':gid', $groupId, PDO::PARAM_INT);
    $stmtLeads->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmtLeads->execute();
    $leads = $stmtLeads->fetchAll();

    if (empty($leads)) {
        echo json_encode([
            'success' => true,
            'count'   => 0,
            'message' => 'Tidak ada prospek dengan status "Belum dihubungi" di grup ini.'
        ]);
        exit;
    }

    // Siapkan array pesan untuk OneSender
    $messagesPayload = [];
    $preparedLeads = [];

    foreach ($leads as $lead) {
        $formattedText = OneSenderService::formatMessage($templateText, $lead);
        $cleanPhone = OneSenderService::normalizePhone($lead['phone_wa']);

        $messagesPayload[] = [
            'recipient_type' => 'individual',
            'to'             => $cleanPhone,
            'type'           => 'text',
            'text'           => [
                'body' => $formattedText
            ]
        ];

        $preparedLeads[] = [
            'lead_id'      => $lead['id'],
            'message_text' => $formattedText,
            'phone'        => $cleanPhone
        ];
    }

    // Kirim bulk ke OneSender API
    $sendResult = $service->sendMessages($messagesPayload);

    if (!$sendResult['success']) {
        throw new Exception('OneSender gagal mengirim pesan: ' . ($sendResult['error'] ?? 'Unknown error'));
    }

    // Update status leads menjadi 'dihubungi', last_step_sent = 1, step_1_sent_at = NOW()
    $updateStmt = $pdo->prepare("
        UPDATE leads 
        SET status = 'dihubungi',
            last_step_sent = 1,
            step_1_sent_at = NOW(),
            last_contacted_at = NOW()
        WHERE id = :id
    ");

    $logStmt = $pdo->prepare("
        INSERT INTO lead_message_logs (lead_id, step_number, direction, message_text, created_at)
        VALUES (:lid, 1, 'outgoing', :msg, NOW())
    ");

    $successCount = 0;
    foreach ($preparedLeads as $pLead) {
        $updateStmt->execute([':id' => $pLead['lead_id']]);
        $logStmt->execute([
            ':lid' => $pLead['lead_id'],
            ':msg' => $pLead['message_text']
        ]);
        $successCount++;
    }

    echo json_encode([
        'success'      => true,
        'count'        => $successCount,
        'group_name'   => $group['name'],
        'message'      => "Berhasil mengirim Pesan 1 ke {$successCount} prospek di grup \"{$group['name']}\"!"
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
