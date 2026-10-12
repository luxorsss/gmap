<?php
// actions/add_trigger_keyword.php
// Endpoint untuk menyimpan kata kunci pemicu baru dan (opsional) langsung mengirimkan Pesan 2 ke prospek
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/OneSenderService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$keyword = trim($_POST['keyword'] ?? '');
$leadId = isset($_POST['lead_id']) ? (int)$_POST['lead_id'] : 0;

if (empty($keyword)) {
    echo json_encode(['success' => false, 'error' => 'Kata kunci tidak boleh kosong.']);
    exit;
}

$pdo = getDb();

try {
    // 1. Ambil daftar kata kunci saat ini
    $currentKeywords = getSetting('auto_reply_step2_keywords', 'iya, ya, betul, benar, siap, siapa, dengan saya, ada apa, boleh, lanjut, waalaikumsalam, halo, silakan, minat, bisa');
    
    // Normalisasi kata kunci yang ada
    $kwArray = array_filter(array_map('trim', preg_split('/[,\n\r]+/', $currentKeywords)));
    
    // Cek apakah kata kunci baru sudah ada
    $exists = false;
    foreach ($kwArray as $kw) {
        if (mb_strtolower($kw) === mb_strtolower($keyword)) {
            $exists = true;
            break;
        }
    }

    if (!$exists) {
        $kwArray[] = $keyword;
        $updatedString = implode(', ', $kwArray);
        saveSetting('auto_reply_step2_keywords', $updatedString);
    }

    $msg = "Kata kunci \"{$keyword}\" berhasil didaftarkan sebagai trigger otomatis!";
    $sentStep2 = false;

    // 2. Jika ada lead_id dan lead tersebut berhak menerima Pesan 2, kirim seketika
    if ($leadId > 0) {
        $stmtLead = $pdo->prepare("SELECT * FROM leads WHERE id = :id LIMIT 1");
        $stmtLead->execute([':id' => $leadId]);
        $lead = $stmtLead->fetch();

        if ($lead && (int)$lead['last_step_sent'] === 1 && $lead['status'] === 'dihubungi') {
            $service = new OneSenderService($pdo);
            $templateRow = $service->getTemplateForGroup($lead['group_id'], 2);

            if ($templateRow && !empty(trim($templateRow['message_text']))) {
                $formattedMessage = OneSenderService::formatMessage($templateRow['message_text'], $lead);
                $sendResult = $service->sendMessageSingle($lead['phone_wa'], $formattedMessage);

                if ($sendResult['success']) {
                    // Log outgoing pesan 2
                    $pdo->prepare("
                        INSERT INTO lead_message_logs (lead_id, step_number, direction, message_text, created_at)
                        VALUES (:lid, 2, 'outgoing', :msg, NOW())
                    ")->execute([
                        ':lid' => $leadId,
                        ':msg' => $formattedMessage
                    ]);

                    // Update lead status
                    $pdo->prepare("
                        UPDATE leads 
                        SET last_step_sent = 2,
                            step_2_sent_at = NOW(),
                            last_contacted_at = NOW()
                        WHERE id = :id
                    ")->execute([':id' => $leadId]);

                    $sentStep2 = true;
                    $msg .= " Serta Pesan 2 langsung berhasil dikirimkan ke prospek!";
                }
            }
        }
    }

    echo json_encode([
        'success'      => true,
        'message'      => $msg,
        'sent_step_2'  => $sentStep2,
        'lead_id'      => $leadId,
        'new_keyword'  => $keyword
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Gagal memproses: ' . $e->getMessage()]);
}
