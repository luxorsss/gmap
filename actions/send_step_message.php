<?php
// actions/send_step_message.php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/OneSenderService.php';

$pdo = getDb();

try {
    $leadId = isset($_POST['lead_id']) ? (int)$_POST['lead_id'] : 0;
    $stepNumber = isset($_POST['step_number']) ? (int)$_POST['step_number'] : 0;

    if ($leadId <= 0) {
        throw new Exception('ID prospek tidak valid.');
    }

    if (!in_array($stepNumber, [1, 2, 3])) {
        throw new Exception('Hanya Pesan 1, 2, dan 3 yang dapat dikirim secara manual.');
    }

    // Ambil data prospek
    $stmtLead = $pdo->prepare("SELECT * FROM leads WHERE id = :id LIMIT 1");
    $stmtLead->execute([':id' => $leadId]);
    $lead = $stmtLead->fetch();

    if (!$lead) {
        throw new Exception('Prospek tidak ditemukan.');
    }

    // Validasi urutan pengiriman
    if ($stepNumber === 1 && (int)$lead['last_step_sent'] !== 0) {
        throw new Exception('Pesan 1 sudah pernah dikirim untuk prospek ini.');
    }
    if ($stepNumber === 2 && (int)$lead['last_step_sent'] !== 1) {
        throw new Exception('Pesan 2 hanya dapat dikirim setelah Pesan 1 berhasil terkirim.');
    }
    if ($stepNumber === 3 && (int)$lead['last_step_sent'] !== 2) {
        throw new Exception('Pesan 3 hanya dapat dikirim setelah Pesan 2 berhasil terkirim.');
    }

    $service = new OneSenderService($pdo);

    // Ambil template untuk step yang bersangkutan
    $templateRow = $service->getTemplateForGroup($lead['group_id'], $stepNumber);
    if (!$templateRow || empty(trim($templateRow['message_text']))) {
        throw new Exception("Template Pesan {$stepNumber} belum diatur. Silakan periksa di menu Pengaturan.");
    }

    // Format teks pesan
    $messageText = OneSenderService::formatMessage($templateRow['message_text'], $lead);

    // Kirim via OneSender
    $sendResult = $service->sendMessageSingle($lead['phone_wa'], $messageText);

    if (!$sendResult['success']) {
        throw new Exception('Gagal mengirim WhatsApp via OneSender: ' . ($sendResult['error'] ?? 'Unknown error'));
    }

    // Catat log pengiriman
    $stmtLog = $pdo->prepare("
        INSERT INTO lead_message_logs (lead_id, step_number, direction, message_text, created_at)
        VALUES (:lid, :step, 'outgoing', :msg, NOW())
    ");
    $stmtLog->execute([
        ':lid'  => $leadId,
        ':step' => $stepNumber,
        ':msg'  => $messageText
    ]);

    // Update database lead
    if ($stepNumber === 1) {
        $updateStmt = $pdo->prepare("
            UPDATE leads 
            SET status = 'dihubungi',
                last_step_sent = 1,
                step_1_sent_at = NOW(),
                last_contacted_at = NOW()
            WHERE id = :id
        ");
        $updateStmt->execute([':id' => $leadId]);

        echo json_encode([
            'success'        => true,
            'lead_id'        => $leadId,
            'next_step'      => 2,
            'last_step_sent' => 1,
            'new_status'     => 'dihubungi',
            'message'        => 'Pesan 1 berhasil dikirim! Status prospek kini "Dihubungi" dan tombol beralih ke Pesan 2.'
        ]);
        exit;
    }

    if ($stepNumber === 2) {
        $updateStmt = $pdo->prepare("
            UPDATE leads 
            SET last_step_sent = 2,
                step_2_sent_at = NOW(),
                last_contacted_at = NOW()
            WHERE id = :id
        ");
        $updateStmt->execute([':id' => $leadId]);

        echo json_encode([
            'success'        => true,
            'lead_id'        => $leadId,
            'next_step'      => 3,
            'last_step_sent' => 2,
            'message'        => 'Pesan 2 berhasil dikirim! Sekarang tombol beralih ke Pesan 3.'
        ]);
        exit;
    }

    if ($stepNumber === 3) {
        // Ambil delay untuk Follow-up 1 (Step 4)
        $fu1Template = $service->getTemplateForGroup($lead['group_id'], 4);
        $fu1Days = isset($fu1Template['delay_days']) ? (int)$fu1Template['delay_days'] : 1;
        $fu1Hours = isset($fu1Template['delay_hours']) ? (int)$fu1Template['delay_hours'] : 0;

        // Jadwalkan FU 1 hanya jika status masih 'dihubungi'
        $updateStmt = $pdo->prepare("
            UPDATE leads 
            SET last_step_sent = 3,
                step_3_sent_at = NOW(),
                last_contacted_at = NOW(),
                next_followup_at = DATE_ADD(DATE_ADD(NOW(), INTERVAL :days DAY), INTERVAL :hours HOUR)
            WHERE id = :id
        ");
        $updateStmt->bindValue(':days', $fu1Days, PDO::PARAM_INT);
        $updateStmt->bindValue(':hours', $fu1Hours, PDO::PARAM_INT);
        $updateStmt->bindValue(':id', $leadId, PDO::PARAM_INT);
        $updateStmt->execute();

        // Hitung estimasi waktu FU 1 untuk respon JSON
        $stmtUpdated = $pdo->prepare("SELECT next_followup_at FROM leads WHERE id = :id");
        $stmtUpdated->execute([':id' => $leadId]);
        $nextFu = $stmtUpdated->fetchColumn();

        echo json_encode([
            'success'          => true,
            'lead_id'          => $leadId,
            'next_step'        => 4,
            'last_step_sent'   => 3,
            'next_followup_at' => $nextFu,
            'message'          => 'Pesan 3 berhasil dikirim! Tombol dihilangkan dan Follow-up 1 otomatis dijadwalkan pada ' . date('d M Y H:i', strtotime($nextFu))
        ]);
        exit;
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
