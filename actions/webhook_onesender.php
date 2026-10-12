<?php
// actions/webhook_onesender.php
// Receiver webhook dari OneSender untuk mencatat balasan pesan dari prospek & otomasi Pesan 2
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/OneSenderService.php';

function logWebhook(string $message, $data = null): void {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    $logFile = $logDir . '/webhook.log';
    $time = date('Y-m-d H:i:s');
    $entry = "[{$time}] {$message}";
    if ($data !== null) {
        $entry .= "\nDATA: " . (is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    $entry .= "\n----------------------------------------\n";
    @file_put_contents($logFile, $entry, FILE_APPEND);
}

// Fitur Viewer Log langsung di browser via URL ?view_log=1
if (isset($_GET['view_log'])) {
    header('Content-Type: text/plain; charset=utf-8');
    $logFile = __DIR__ . '/../logs/webhook.log';
    if (file_exists($logFile)) {
        $content = file_get_contents($logFile);
        echo substr($content, -30000) ?: 'Log masih kosong.';
    } else {
        echo "Log belum dibuat (belum ada request webhook masuk dari OneSender).";
    }
    exit;
}

// Fitur Bersihkan Log via URL ?clear_log=1
if (isset($_GET['clear_log'])) {
    header('Content-Type: text/plain; charset=utf-8');
    $logFile = __DIR__ . '/../logs/webhook.log';
    @unlink($logFile);
    echo "Log webhook berhasil dibersihkan.";
    exit;
}

$rawPayload = file_get_contents('php://input');
if (empty($rawPayload) && PHP_SAPI === 'cli') {
    $rawPayload = file_get_contents('php://stdin');
}

if (empty($rawPayload)) {
    logWebhook("Request DITOLAK: Payload kosong (Empty payload)");
    echo json_encode(['status' => 'ignored', 'message' => 'Empty payload']);
    exit;
}

// Catat setiap payload masuk
logWebhook("RAW INCOMING WEBHOOK", $rawPayload);

$data = json_decode($rawPayload, true);
if (!$data || !is_array($data)) {
    logWebhook("Request DITOLAK: Invalid JSON format");
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

// Normalisasi nesting jika dibungkus dalam data atau array messages
if (isset($data['data']) && is_array($data['data'])) {
    $item = $data['data'];
    if (isset($item['messages'][0]) && is_array($item['messages'][0])) {
        $data = array_merge($data, $item['messages'][0]);
    } else {
        $data = array_merge($data, $item);
    }
} elseif (isset($data['messages'][0]) && is_array($data['messages'][0])) {
    $data = array_merge($data, $data['messages'][0]);
}

// 1. Ekstraksi Pengirim (Sender / From)
$rawSender = $data['sender_phone'] 
    ?? ($data['phone'] 
    ?? ($data['from'] 
    ?? ($data['sender'] 
    ?? ($data['key']['remoteJid'] 
    ?? ($data['participant'] ?? '')))));

if (is_string($rawSender)) {
    // Hilangkan JID suffix seperti @s.whatsapp.net, @c.us, atau device identifier :1
    $rawSender = explode('@', $rawSender)[0];
    $rawSender = explode(':', $rawSender)[0];
}
$cleanPhone = OneSenderService::normalizePhone((string)$rawSender);

// 2. Ekstraksi Teks Pesan (Mendukung extendedTextMessage.text dari WhatsApp HP)
$rawText = '';
if (isset($data['message_text']) && is_string($data['message_text'])) {
    $rawText = $data['message_text'];
} elseif (isset($data['message']) && is_string($data['message'])) {
    $rawText = $data['message'];
} elseif (isset($data['body']) && is_string($data['body'])) {
    $rawText = $data['body'];
} elseif (isset($data['text']) && is_string($data['text'])) {
    $rawText = $data['text'];
} elseif (isset($data['message']) && is_array($data['message'])) {
    // WhatsApp Baileys / Multi-device format
    $rawText = $data['message']['conversation'] 
        ?? ($data['message']['extendedTextMessage']['text'] 
        ?? ($data['message']['imageMessage']['caption'] 
        ?? ($data['message']['videoMessage']['caption'] 
        ?? '')));
} elseif (isset($data['context']['body']) && is_string($data['context']['body'])) {
    $rawText = $data['context']['body'];
}
$messageText = trim((string)$rawText);

// 3. Cek Status Pesan (Bukan pesan keluar dari kita sendiri, bukan grup)
$isFromMe = !empty($data['is_from_me']) || !empty($data['from_me']) || !empty($data['key']['fromMe']);
$chatJid = (string)($data['chat'] ?? ($data['key']['remoteJid'] ?? ($data['from'] ?? '')));
$isGroup = !empty($data['is_group']) || !empty($data['group']) || strpos($chatJid, '@g.us') !== false;

logWebhook("PARSED DATA", [
    'sender_raw'   => $rawSender,
    'clean_phone'  => $cleanPhone,
    'message_text' => $messageText,
    'is_from_me'   => $isFromMe,
    'is_group'     => $isGroup
]);

if ($isFromMe || $isGroup || empty($cleanPhone) || empty($messageText)) {
    logWebhook("Request DIABAIKAN: isFromMe={$isFromMe}, isGroup={$isGroup}, cleanPhone='{$cleanPhone}', messageText='{$messageText}'");
    echo json_encode(['status' => 'ignored', 'message' => 'Not an incoming direct user message']);
    exit;
}

$pdo = getDb();

// Cari lead berdasarkan nomor WhatsApp
$lastDigits = substr($cleanPhone, -9);
$stmt = $pdo->prepare("SELECT * FROM leads WHERE phone_wa = :phone OR phone_wa LIKE :likephone LIMIT 1");
$stmt->execute([
    ':phone'     => $cleanPhone,
    ':likephone' => '%' . $lastDigits
]);
$lead = $stmt->fetch();

if (!$lead) {
    logWebhook("Lead TIDAK DITEMUKAN di database untuk nomor: {$cleanPhone} (last9: {$lastDigits})");
    echo json_encode(['status' => 'not_found', 'message' => 'Lead number not found in database', 'phone' => $cleanPhone]);
    exit;
}

logWebhook("Lead DITEMUKAN: ID={$lead['id']} ({$lead['name']}), Status={$lead['status']}, LastStep={$lead['last_step_sent']}");

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
    logWebhook("Warning insert log: " . $e->getMessage());
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
    logWebhook("Warning update last_incoming_message: " . $e->getMessage());
}

// 3. Evaluasi Otomasi Pengiriman Pesan ke-2
$autoReplyEnabled = getSetting('auto_reply_step2_enabled', '1') === '1';
$currentStep = (int)($lead['last_step_sent'] ?? 0);
$leadStatus = $lead['status'] ?? '';

// Syarat: Fitur aktif, Pesan 1 sudah terkirim (step == 1), dan status masih 'dihubungi'
if (!$autoReplyEnabled) {
    logWebhook("Auto-reply Pesan 2 NONAKTIF di pengaturan (auto_reply_step2_enabled = 0)");
} elseif ($leadStatus !== 'dihubungi') {
    logWebhook("Pesan 2 TIDAK dipicu: Status prospek bukan 'dihubungi' (Status saat ini: '{$leadStatus}')");
} elseif ($currentStep !== 1) {
    logWebhook("Pesan 2 TIDAK dipicu: last_step_sent bukan 1 (last_step_sent saat ini: {$currentStep})");
} else {
    // Memenuhi syarat status! Cek kecocokan kata kunci
    $rawKeywords = getSetting('auto_reply_step2_keywords', 'iya, ya, betul, benar, siap, siapa, dengan saya, ada apa, boleh, lanjut, waalaikumsalam, halo, silakan, minat, bisa');
    
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

    if ($matchedKeyword === null) {
        logWebhook("Kata kunci TIDAK COCOK. Pesan: '{$incomingLower}' tidak mengandung salah satu keywords: " . implode(', ', $keywordList));
    } else {
        logWebhook("Kata kunci COCOK! Trigger: '{$matchedKeyword}'. Mengirim Pesan 2 ke lead #{$lead['id']}...");

        try {
            $service = new OneSenderService($pdo);
            $templateRow = $service->getTemplateForGroup($lead['group_id'], 2);

            if (!$templateRow || empty(trim($templateRow['message_text']))) {
                logWebhook("Gagal: Template Pesan 2 untuk grup {$lead['group_id']} belum diatur atau kosong!");
                echo json_encode([
                    'status'     => 'partial_success',
                    'auto_reply' => false,
                    'lead_id'    => $lead['id'],
                    'error'      => 'Template Pesan 2 belum diatur di menu Pengaturan.'
                ]);
                exit;
            }

            $formattedMessage = OneSenderService::formatMessage($templateRow['message_text'], $lead);
            $sendResult = $service->sendMessageSingle($lead['phone_wa'], $formattedMessage);

            logWebhook("Hasil pengiriman OneSender Pesan 2:", $sendResult);

            if ($sendResult['success']) {
                // Catat pesan keluar step 2 ke log
                try {
                    $logOutStmt = $pdo->prepare("
                        INSERT INTO lead_message_logs (lead_id, step_number, direction, message_text, created_at)
                        VALUES (:lid, 2, 'outgoing', :msg, NOW())
                    ");
                    $logOutStmt->execute([
                        ':lid' => $lead['id'],
                        ':msg' => $formattedMessage
                    ]);
                } catch (Throwable $e) {}

                // Update lead: step 2 terkirim
                $updStep2 = $pdo->prepare("
                    UPDATE leads 
                    SET last_step_sent = 2,
                        step_2_sent_at = NOW(),
                        last_contacted_at = NOW()
                    WHERE id = :id
                ");
                $updStep2->execute([':id' => $lead['id']]);

                logWebhook("SUKSES: Pesan 2 berhasil dikirim otomatis ke lead #{$lead['id']}!");

                echo json_encode([
                    'status'          => 'success',
                    'auto_reply'      => true,
                    'lead_id'         => $lead['id'],
                    'matched_keyword' => $matchedKeyword,
                    'message'         => 'Pesan balasan cocok dengan trigger. Pesan 2 berhasil dikirim otomatis!'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            } else {
                logWebhook("GAGAL kirim Pesan 2 via OneSender: " . ($sendResult['error'] ?? 'Unknown error'));
                echo json_encode([
                    'status'     => 'partial_success',
                    'auto_reply' => false,
                    'lead_id'    => $lead['id'],
                    'error'      => 'Kata kunci cocok namun gagal mengirim via OneSender: ' . ($sendResult['error'] ?? 'Unknown error')
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } catch (Throwable $err) {
            logWebhook("Exception saat kirim Pesan 2: " . $err->getMessage());
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
    'message'    => 'Pesan balasan berhasil dicatat.'
], JSON_UNESCAPED_UNICODE);
