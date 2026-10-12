<?php
// actions/webhook_onesender.php
// Receiver webhook dari OneSender untuk mencatat balasan pesan dari prospek & otomasi Pesan 2
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/OneSenderService.php';

$rawPayload = file_get_contents('php://input');
if (empty($rawPayload) && PHP_SAPI === 'cli') {
    $rawPayload = file_get_contents('php://stdin');
}
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
$isFromMe = !empty($data['is_from_me']) || !empty($data['from_me']) || !empty($data['key']['fromMe']);
$isGroup = !empty($data['is_group']) || !empty($data['group']) || (isset($data['chat']) && is_string($data['chat']) && strpos($data['chat'], '@g.us') !== false) || (isset($data['from']) && is_string($data['from']) && strpos($data['from'], '@g.us') !== false);
$senderPhone = $data['sender_phone'] ?? ($data['phone'] ?? ($data['from'] ?? ($data['sender'] ?? '')));
$messageText = trim(
    $data['message_text'] 
    ?? ($data['message'] 
    ?? ($data['body'] 
    ?? ($data['text'] 
    ?? ($data['context']['body'] 
    ?? ($data['message']['conversation'] 
    ?? '')))))
);

if ($isFromMe || $isGroup || empty($senderPhone) || empty($messageText)) {
    echo json_encode(['status' => 'ignored', 'message' => 'Not an incoming direct user message']);
    exit;
}

$pdo = getDb();
$cleanPhone = OneSenderService::normalizePhone($senderPhone);

// Cari lead berdasarkan nomor WhatsApp
$stmt = $pdo->prepare("SELECT * FROM leads WHERE phone_wa = :phone OR phone_wa LIKE :likephone LIMIT 1");
$stmt->execute([
    ':phone'     => $cleanPhone,
    ':likephone' => '%' . substr($cleanPhone, -9) // Cocokkan 9 digit terakhir jika ada variasi format
]);
$lead = $stmt->fetch();

if (!$lead) {
    echo json_encode(['status' => 'not_found', 'message' => 'Lead number not found in database']);
    exit;
}

// 1. Catat ke tabel riwayat lead_message_logs
try {
    $logStmt = $pdo->prepare("
        INSERT INTO lead_message_logs (lead_id, step_number, direction, message_text, created_at)
        VALUES (:lid, :step, 'incoming', :msg, NOW())
    ");
    $logStmt->execute([
        ':lid'  => $lead['id'],
        ':step' => $lead['last_step_sent'] ?: 1,
        ':msg'  => $messageText
    ]);
} catch (Throwable $e) {
    // Abaikan jika error log agar proses utama tetap berjalan
}

// 2. Simpan pesan masuk terakhir ke tabel leads agar tampil langsung di dashboard
try {
    $updLeadStmt = $pdo->prepare("
        UPDATE leads 
        SET last_incoming_message = :msg, 
            last_incoming_at = NOW() 
        WHERE id = :id
    ");
    $updLeadStmt->execute([
        ':msg' => $messageText,
        ':id'  => $lead['id']
    ]);
} catch (Throwable $e) {
    // Jika kolom belum ada di database tertentu, lanjut tanpa crash
}

// 3. Evaluasi Otomasi Pengiriman Pesan ke-2
$autoReplyEnabled = getSetting('auto_reply_step2_enabled', '1') === '1';
$currentStep = (int)($lead['last_step_sent'] ?? 0);
$leadStatus = $lead['status'] ?? '';

// Syarat: Fitur aktif, Pesan 1 sudah terkirim (step == 1), belum pernah kirim Pesan 2, dan status masih 'dihubungi'
if ($autoReplyEnabled && $currentStep === 1 && $leadStatus === 'dihubungi') {
    $rawKeywords = getSetting('auto_reply_step2_keywords', 'iya, ya, betul, benar, siap, siapa, dengan saya, ada apa, boleh, lanjut, waalaikumsalam, halo, silakan, minat, bisa');
    
    // Normalisasi keywords menjadi array
    $keywordList = array_filter(array_map(function($kw) {
        return mb_strtolower(trim($kw));
    }, preg_split('/[,\n\r]+/', $rawKeywords)));

    $incomingLower = mb_strtolower($messageText);
    $matchedKeyword = null;

    foreach ($keywordList as $kw) {
        if ($kw !== '' && mb_stripos($incomingLower, $kw) !== false) {
            $matchedKeyword = $kw;
            break;
        }
    }

    if ($matchedKeyword !== null) {
        // Kata kunci cocok! Kirim Pesan 2 seketika
        try {
            $service = new OneSenderService($pdo);
            $templateRow = $service->getTemplateForGroup($lead['group_id'], 2);

            if ($templateRow && !empty(trim($templateRow['message_text']))) {
                $formattedMessage = OneSenderService::formatMessage($templateRow['message_text'], $lead);
                $sendResult = $service->sendMessageSingle($lead['phone_wa'], $formattedMessage);

                if ($sendResult['success']) {
                    // Catat pesan keluar step 2 ke log
                    $logOutStmt = $pdo->prepare("
                        INSERT INTO lead_message_logs (lead_id, step_number, direction, message_text, created_at)
                        VALUES (:lid, 2, 'outgoing', :msg, NOW())
                    ");
                    $logOutStmt->execute([
                        ':lid' => $lead['id'],
                        ':msg' => $formattedMessage
                    ]);

                    // Update lead: step 2 terkirim
                    $updStep2 = $pdo->prepare("
                        UPDATE leads 
                        SET last_step_sent = 2,
                            step_2_sent_at = NOW(),
                            last_contacted_at = NOW()
                        WHERE id = :id
                    ");
                    $updStep2->execute([':id' => $lead['id']]);

                    echo json_encode([
                        'status'          => 'success',
                        'auto_reply'      => true,
                        'lead_id'         => $lead['id'],
                        'matched_keyword' => $matchedKeyword,
                        'message'         => 'Pesan balasan cocok dengan kata kunci trigger. Pesan 2 berhasil dikirim otomatis!'
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                } else {
                    echo json_encode([
                        'status'     => 'partial_success',
                        'auto_reply' => false,
                        'lead_id'    => $lead['id'],
                        'error'      => 'Kata kunci cocok namun gagal mengirim via OneSender: ' . ($sendResult['error'] ?? 'Unknown error')
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }
        } catch (Throwable $err) {
            echo json_encode([
                'status'     => 'partial_success',
                'auto_reply' => false,
                'lead_id'    => $lead['id'],
                'error'      => 'Kesalahan saat mengirim auto-reply Pesan 2: ' . $err->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

echo json_encode([
    'status'     => 'logged',
    'auto_reply' => false,
    'lead_id'    => $lead['id'],
    'message'    => 'Pesan balasan berhasil dicatat. Menunggu tindakan manual atau kata kunci belum cocok.'
], JSON_UNESCAPED_UNICODE);
