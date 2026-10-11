<?php
/**
 * GMaps Leads Pro - Sequential Followup Scheduler
 * Didesain khusus untuk dieksekusi oleh Cron Job Pihak Ketiga (cron-job.org, EasyCron, cPanel Cron, dll)
 * persis seperti pada project PixelCRM.
 */

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/OneSenderService.php';

// Logging function ke file dan output layar
function log_cron_message(string $message, bool $echoToOutput = true): void {
    static $log_file = null;
    if ($log_file === null) {
        $log_dir = __DIR__ . '/../logs';
        if (!is_dir($log_dir)) {
            mkdir($log_dir, 0755, true);
        }
        $log_file = $log_dir . '/followup_' . date('Y-m') . '.log';
    }

    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message" . PHP_EOL;

    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);

    if ($echoToOutput) {
        echo $log_entry;
        if (php_sapi_name() !== 'cli') {
            flush();
        }
    }
}

/**
 * Fungsi inti pemrosesan antrean follow up
 */
function runFollowupProcess(bool $force = false, bool $echoOutput = false): array {
    $pdo = getDb();
    $service = new OneSenderService($pdo);

    $startHour = (int)getSetting('drip_send_start_hour', '8');
    $endHour = (int)getSetting('drip_send_end_hour', '17');
    $currentHour = (int)date('G');

    log_cron_message("⏰ Waktu Sekarang: " . date('H:i:s') . " WIB | Jam Kerja: {$startHour}:00 - {$endHour}:00 WIB", $echoOutput);

    // Validasi jam kerja kecuali jika dipaksa (?force=1)
    if (!$force && ($currentHour < $startHour || $currentHour >= $endHour)) {
        $msg = "Di luar jam kerja kirim WhatsApp ({$startHour}:00 - {$endHour}:00 WIB). Antrean follow-up ditangguhkan.";
        log_cron_message("⏸️ " . $msg, $echoOutput);
        return [
            'success'   => true,
            'processed' => 0,
            'message'   => $msg
        ];
    }

    // 1. Bersihkan antrean untuk lead yang sudah Deal atau Batal
    $cleaned = $pdo->exec("UPDATE leads SET next_followup_at = NULL WHERE status IN ('deal', 'batal') AND next_followup_at IS NOT NULL");
    if ($cleaned > 0) {
        log_cron_message("✂️ Dihapus {$cleaned} antrean follow-up dari lead yang sudah Deal / Batal.", $echoOutput);
    }

    // 2. Ambil antrean follow up yang sudah jatuh tempo
    $stmtQueue = $pdo->prepare("
        SELECT * FROM leads 
        WHERE status = 'dihubungi' 
          AND next_followup_at IS NOT NULL 
          AND next_followup_at <= NOW() 
          AND last_step_sent IN (3, 4)
        ORDER BY next_followup_at ASC 
        LIMIT 20
    ");
    $stmtQueue->execute();
    $pending_messages = $stmtQueue->fetchAll();

    if (empty($pending_messages)) {
        log_cron_message("ℹ️ Tidak ada pesan follow-up yang jatuh tempo untuk dikirim saat ini.", $echoOutput);

        // Tampilkan upcoming follow-up berikutnya
        $stmtUpcoming = $pdo->query("
            SELECT COUNT(*) as count, MIN(next_followup_at) as next_at 
            FROM leads 
            WHERE status = 'dihubungi' AND next_followup_at > NOW() AND last_step_sent IN (3, 4)
        ");
        $upcoming = $stmtUpcoming->fetch();
        if ($upcoming && $upcoming['count'] > 0) {
            log_cron_message("📅 Antrean berikutnya: {$upcoming['count']} prospek (jadwal terdekat: {$upcoming['next_at']} WIB)", $echoOutput);
        }

        $totalDihubungi = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE status = 'dihubungi'")->fetchColumn();
        $totalSentToday = (int)$pdo->query("SELECT COUNT(*) FROM lead_message_logs WHERE direction = 'outgoing' AND DATE(created_at) = CURDATE()")->fetchColumn();
        log_cron_message("📊 Status Hari Ini: {$totalDihubungi} prospek sedang dihubungi, {$totalSentToday} pesan terkirim hari ini.", $echoOutput);

        return [
            'success'   => true,
            'processed' => 0,
            'message'   => 'Tidak ada antrean follow-up yang jatuh tempo saat ini.'
        ];
    }

    log_cron_message("📬 Ditemukan " . count($pending_messages) . " prospek siap dikirimi pesan follow-up:", $echoOutput);

    $sent_count = 0;
    $failed_count = 0;
    $errors = [];

    foreach ($pending_messages as $lead) {
        $leadId = $lead['id'];
        $lastStep = (int)$lead['last_step_sent'];
        $nextStep = ($lastStep === 3) ? 4 : 5; // Step 4 = Follow-up 1, Step 5 = Follow-up 2
        $stepLabel = ($nextStep === 4) ? "Follow-up 1" : "Follow-up 2";

        try {
            $tpl = $service->getTemplateForGroup($lead['group_id'], $nextStep);
            if (!$tpl || empty(trim($tpl['message_text']))) {
                log_cron_message("⚠️ Template {$stepLabel} kosong untuk lead #{$leadId} ({$lead['name']}). Dilewati.", $echoOutput);
                $failed_count++;
                continue;
            }

            $messageText = OneSenderService::formatMessage($tpl['message_text'], $lead);

            // Kirim pesan WhatsApp via OneSender
            $res = $service->sendMessageSingle($lead['phone_wa'], $messageText);

            if ($res['success']) {
                $stmtLog = $pdo->prepare("
                    INSERT INTO lead_message_logs (lead_id, step_number, direction, message_text, created_at)
                    VALUES (:lid, :step, 'outgoing', :msg, NOW())
                ");
                $stmtLog->execute([
                    ':lid'  => $leadId,
                    ':step' => $nextStep,
                    ':msg'  => $messageText
                ]);

                if ($nextStep === 4) {
                    $fu2Tpl = $service->getTemplateForGroup($lead['group_id'], 5);
                    $fu2Days = isset($fu2Tpl['delay_days']) ? (int)$fu2Tpl['delay_days'] : 2;
                    $fu2Hours = isset($fu2Tpl['delay_hours']) ? (int)$fu2Tpl['delay_hours'] : 0;

                    $updateStmt = $pdo->prepare("
                        UPDATE leads 
                        SET last_step_sent = 4,
                            fu_1_sent_at = NOW(),
                            last_contacted_at = NOW(),
                            next_followup_at = DATE_ADD(DATE_ADD(NOW(), INTERVAL :days DAY), INTERVAL :hours HOUR)
                        WHERE id = :id
                    ");
                    $updateStmt->bindValue(':days', $fu2Days, PDO::PARAM_INT);
                    $updateStmt->bindValue(':hours', $fu2Hours, PDO::PARAM_INT);
                    $updateStmt->bindValue(':id', $leadId, PDO::PARAM_INT);
                    $updateStmt->execute();

                    log_cron_message("✅ [FU 1 SENT] #{$leadId} - {$lead['name']} (+{$lead['phone_wa']}) -> FU 2 dijadwalkan dalam +{$fu2Days}h {$fu2Hours}j", $echoOutput);
                } else {
                    $updateStmt = $pdo->prepare("
                        UPDATE leads 
                        SET last_step_sent = 5,
                            fu_2_sent_at = NOW(),
                            last_contacted_at = NOW(),
                            next_followup_at = NULL
                        WHERE id = :id
                    ");
                    $updateStmt->execute([':id' => $leadId]);

                    log_cron_message("🎉 [FU 2 COMPLETE] #{$leadId} - {$lead['name']} (+{$lead['phone_wa']}) -> Drip 5 langkah tuntas!", $echoOutput);
                }

                $sent_count++;

                // Jeda antar pesan
                $delaySec = (int)getSetting('batch_delay_seconds', '3');
                if ($delaySec > 0) {
                    sleep($delaySec);
                }

            } else {
                $err = $res['error'] ?? 'Unknown error';
                log_cron_message("❌ [FAILED] #{$leadId} - {$lead['name']}: " . $err, $echoOutput);
                $failed_count++;
                $errors[] = "#{$leadId}: " . $err;
            }

        } catch (Exception $ex) {
            log_cron_message("❌ [EXCEPTION] Lead #{$leadId}: " . $ex->getMessage(), $echoOutput);
            $failed_count++;
            $errors[] = $ex->getMessage();
        }
    }

    log_cron_message("🏁 Scheduler selesai. Berhasil: {$sent_count}, Gagal: {$failed_count}", $echoOutput);

    return [
        'success'   => true,
        'processed' => $sent_count,
        'failed'    => $failed_count,
        'errors'    => $errors,
        'message'   => "Selesai memproses antrean follow-up: {$sent_count} pesan terkirim." . ($failed_count > 0 ? " ({$failed_count} gagal)" : "")
    ];
}

// Jika diakses langsung oleh Cron Job Web Pihak Ketiga atau CLI
$isDirectAccess = !defined('GMAPS_FOLLOWUP_CALLED_INTERNALLY');

if ($isDirectAccess) {
    $is_cli = (php_sapi_name() === 'cli');

    if (!$is_cli) {
        header('Content-Type: text/html; charset=utf-8');
        echo "<pre style='font-family: monospace; background: #1e293b; color: #f8fafc; padding: 20px; border-radius: 8px; line-height: 1.5;'>";
        echo "🚀 GMaps Leads Pro - WhatsApp Followup Scheduler\n";
        echo str_repeat("=", 60) . "\n\n";
    }

    $force = isset($_GET['force']) && $_GET['force'] == '1';
    log_cron_message("✅ Scheduler triggered via " . ($is_cli ? "CLI" : "Web HTTP Request"), true);

    runFollowupProcess($force, true);

    if (!$is_cli) {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "🏁 Eksekusi selesai pada " . date('Y-m-d H:i:s') . " WIB\n";
        echo "</pre>";
    }
}
