<?php
// settings.php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/services/OneSenderService.php';

$pdo = getDb();
$message = '';
$messageType = '';

// Ambil daftar grup untuk dropdown template per grup
$stmtGroups = $pdo->query("SELECT id, name, color FROM lead_groups ORDER BY name ASC");
$groups = $stmtGroups->fetchAll();

// Grup yang sedang dipilih di tab pengaturan template
$selectedGroupId = isset($_GET['group_id']) && $_GET['group_id'] !== '' ? (int)$_GET['group_id'] : null;

// Handle Simpan Form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_all';

    if ($action === 'save_all') {
        // 1. Simpan General & OneSender Settings
        $apiKey = trim($_POST['google_api_key'] ?? '');
        $scraperUrl = trim($_POST['scraper_worker_url'] ?? 'http://127.0.0.1:3123');
        $scraperToken = trim($_POST['scraper_secret_token'] ?? '');
        $oneSenderUrl = trim($_POST['onesender_api_url'] ?? 'http://localhost:3001/api/v1');
        $oneSenderToken = trim($_POST['onesender_token'] ?? '');
        $startHour = (int)($_POST['drip_send_start_hour'] ?? 8);
        $endHour = (int)($_POST['drip_send_end_hour'] ?? 17);
        $batchDelay = (int)($_POST['batch_delay_seconds'] ?? 3);

        saveSetting('google_api_key', $apiKey);
        saveSetting('scraper_worker_url', $scraperUrl);
        saveSetting('scraper_secret_token', $scraperToken);
        saveSetting('onesender_api_url', $oneSenderUrl);
        saveSetting('onesender_token', $oneSenderToken);
        saveSetting('drip_send_start_hour', (string)$startHour);
        saveSetting('drip_send_end_hour', (string)$endHour);
        saveSetting('batch_delay_seconds', (string)$batchDelay);

        // 2. Simpan Templates untuk group yang dipilih
        $targetGroupId = !empty($_POST['target_group_id']) ? (int)$_POST['target_group_id'] : null;

        $stepNames = [
            1 => 'Pesan 1 (Perkenalan)',
            2 => 'Pesan 2 (Penawaran Solusi)',
            3 => 'Pesan 3 (Portofolio & Detail)',
            4 => 'Follow-up 1 (Reminder)',
            5 => 'Follow-up 2 (Penawaran Terakhir)',
        ];

        for ($step = 1; $step <= 5; $step++) {
            $msgText = trim($_POST["template_step_{$step}"] ?? '');
            $delayDays = isset($_POST["delay_days_{$step}"]) ? (int)$_POST["delay_days_{$step}"] : 0;
            $delayHours = isset($_POST["delay_hours_{$step}"]) ? (int)$_POST["delay_hours_{$step}"] : 0;
            $stepTitle = $stepNames[$step] ?? "Pesan {$step}";

            if ($targetGroupId === null) {
                $stmt = $pdo->prepare("
                    INSERT INTO group_message_templates (group_id, step_number, step_name, delay_days, delay_hours, message_text)
                    VALUES (NULL, :step, :name, :days, :hours, :text)
                    ON DUPLICATE KEY UPDATE 
                        step_name = VALUES(step_name),
                        delay_days = VALUES(delay_days),
                        delay_hours = VALUES(delay_hours),
                        message_text = VALUES(message_text)
                ");
                $stmt->execute([
                    ':step'  => $step,
                    ':name'  => $stepTitle,
                    ':days'  => $delayDays,
                    ':hours' => $delayHours,
                    ':text'  => $msgText
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO group_message_templates (group_id, step_number, step_name, delay_days, delay_hours, message_text)
                    VALUES (:gid, :step, :name, :days, :hours, :text)
                    ON DUPLICATE KEY UPDATE 
                        step_name = VALUES(step_name),
                        delay_days = VALUES(delay_days),
                        delay_hours = VALUES(delay_hours),
                        message_text = VALUES(message_text)
                ");
                $stmt->execute([
                    ':gid'   => $targetGroupId,
                    ':step'  => $step,
                    ':name'  => $stepTitle,
                    ':days'  => $delayDays,
                    ':hours' => $delayHours,
                    ':text'  => $msgText
                ]);
            }
        }

        $message = 'Semua konfigurasi dan template pesan berhasil diperbarui!';
        $messageType = 'success';
        $selectedGroupId = $targetGroupId;
    }
}

// Ambil nilai setting saat ini
$apiKey = getSetting('google_api_key', '');
$scraperWorkerUrl = getSetting('scraper_worker_url', 'http://127.0.0.1:3123');
$scraperSecretToken = getSetting('scraper_secret_token', '');
$oneSenderUrl = getSetting('onesender_api_url', 'http://localhost:3001/api/v1');
$oneSenderToken = getSetting('onesender_token', '');
$dripStartHour = (int)getSetting('drip_send_start_hour', '8');
$dripEndHour = (int)getSetting('drip_send_end_hour', '17');
$batchDelaySeconds = (int)getSetting('batch_delay_seconds', '3');

// Ambil template untuk grup yang dipilih (atau default jika NULL)
$oneSenderService = new OneSenderService($pdo);
$currentTemplates = [];
for ($i = 1; $i <= 5; $i++) {
    $currentTemplates[$i] = $oneSenderService->getTemplateForGroup($selectedGroupId, $i);
}

// Cek apakah grup ini menggunakan template kustom atau masih inheritance dari default
$isInherited = false;
if ($selectedGroupId !== null) {
    $checkCustom = $pdo->prepare("SELECT COUNT(*) FROM group_message_templates WHERE group_id = :gid");
    $checkCustom->execute([':gid' => $selectedGroupId]);
    if ($checkCustom->fetchColumn() == 0) {
        $isInherited = true;
    }
}

$pageTitle = 'Pengaturan Gateway & Template Drip | MapLeads Pro';
$activeNav = 'settings';
require_once __DIR__ . '/views/header.php';
?>

<div class="max-w-4xl mx-auto space-y-6">

    <!-- Header Section -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-slate-900 tracking-tight">
                Pengaturan Gateway &amp; Template Outreach
            </h1>
            <p class="text-xs text-slate-500 mt-1">
                Konfigurasi API OneSender WhatsApp Gateway dan susun skenario pesan Drip 5-Langkah.
            </p>
        </div>
        <div class="flex items-center gap-2 self-start sm:self-auto">
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                Drip 5 Steps
            </span>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="p-4 rounded-xl flex items-center space-x-3 <?= $messageType === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200 shadow-sm' : 'bg-rose-50 text-rose-800 border border-rose-200' ?>">
            <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
            </svg>
            <span class="text-xs font-semibold"><?= htmlspecialchars($message) ?></span>
        </div>
    <?php endif; ?>

    <form method="POST" action="settings.php<?= $selectedGroupId ? '?group_id=' . $selectedGroupId : '' ?>" class="space-y-6">
        <input type="hidden" name="action" value="save_all">
        <input type="hidden" name="target_group_id" value="<?= $selectedGroupId ?? '' ?>">

        <!-- OneSender WhatsApp Gateway Section -->
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-surface p-6 space-y-5">
            <div class="flex items-start justify-between">
                <div>
                    <h2 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                        <span class="p-1.5 bg-emerald-50 border border-emerald-200 text-emerald-600 rounded-lg text-base">📱</span>
                        OneSender WhatsApp Gateway
                    </h2>
                    <p class="text-xs text-slate-500 mt-1">Hubungkan server OneSender lokal / remote untuk mengirim pesan massal dan follow-up otomatis.</p>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="testConnection()" id="btn-test-conn" class="btn-press inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 border border-slate-200 text-slate-800 text-xs font-semibold transition">
                        <span>⚡</span> Test Koneksi OneSender
                    </button>
                </div>
            </div>

            <div id="test-result-box" class="hidden p-3 rounded-xl text-xs font-medium"></div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="onesender_api_url" class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-2">
                        API URL (Base Endpoint)
                    </label>
                    <input 
                        type="text" 
                        id="onesender_api_url" 
                        name="onesender_api_url" 
                        value="<?= htmlspecialchars($oneSenderUrl) ?>" 
                        placeholder="http://localhost:3001/api/v1" 
                        class="w-full px-4 py-2.5 rounded-xl bg-slate-50 border border-slate-200 focus:bg-white focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 text-xs font-mono text-slate-900 placeholder-slate-400 transition outline-none"
                    >
                    <span class="text-[11px] text-slate-500 mt-1 block font-medium">Default: <code class="text-slate-700 bg-slate-100 px-1 py-0.5 rounded">http://localhost:3001/api/v1</code></span>
                </div>

                <div>
                    <label for="onesender_token" class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-2">
                        Bearer Token / API Key
                    </label>
                    <input 
                        type="password" 
                        id="onesender_token" 
                        name="onesender_token" 
                        value="<?= htmlspecialchars($oneSenderToken) ?>" 
                        placeholder="__kode_token__" 
                        class="w-full px-4 py-2.5 rounded-xl bg-slate-50 border border-slate-200 focus:bg-white focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 text-xs font-mono text-slate-900 placeholder-slate-400 transition outline-none"
                    >
                    <span class="text-[11px] text-slate-500 mt-1 block font-medium">Token otentikasi dari OneSender</span>
                </div>
            </div>

            <!-- Pengaturan Jadwal Jam Kerja & Jeda -->
            <div class="pt-4 border-t border-slate-100 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-2">Jam Mulai Kirim (WIB)</label>
                    <div class="flex items-center gap-2">
                        <input type="number" min="0" max="23" name="drip_send_start_hour" value="<?= $dripStartHour ?>" class="w-20 px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs font-mono font-bold text-center text-slate-900 focus:bg-white focus:border-emerald-600 outline-none">
                        <span class="text-xs text-slate-500 font-medium">:00 WIB (Pagi)</span>
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-2">Jam Selesai Kirim (WIB)</label>
                    <div class="flex items-center gap-2">
                        <input type="number" min="0" max="23" name="drip_send_end_hour" value="<?= $dripEndHour ?>" class="w-20 px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs font-mono font-bold text-center text-slate-900 focus:bg-white focus:border-emerald-600 outline-none">
                        <span class="text-xs text-slate-500 font-medium">:00 WIB (Sore/Malam)</span>
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-2">Jeda Antar Pesan (Detik)</label>
                    <div class="flex items-center gap-2">
                        <input type="number" min="1" max="60" name="batch_delay_seconds" value="<?= $batchDelaySeconds ?>" class="w-20 px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs font-mono font-bold text-center text-slate-900 focus:bg-white focus:border-emerald-600 outline-none">
                        <span class="text-xs text-slate-500 font-medium">detik (anti-banned)</span>
                    </div>
                </div>
            </div>

            <!-- URL Cron Job Pihak Ketiga -->
            <?php
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https://" : "http://";
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $cronUrl = $protocol . $host . rtrim(dirname($_SERVER['REQUEST_URI'] ?? ''), '/') . '/cron/followup_scheduler.php';
            ?>
            <div class="pt-4 border-t border-slate-100">
                <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-800 flex items-center gap-1.5">
                            <span>🌐</span> URL Cron Job Pihak Ketiga (cron-job.org / cPanel)
                        </span>
                        <a href="cron/followup_scheduler.php" target="_blank" class="btn-press text-xs text-emerald-600 hover:text-emerald-700 font-semibold underline flex items-center gap-1">
                            Buka / Tes Sekarang ↗
                        </a>
                    </div>
                    <p class="text-xs text-slate-500">
                        Pasang URL ini pada layanan cron job pihak ketiga dengan interval setiap <strong>5 atau 10 menit</strong>. Script ini otomatis memproses follow-up 1 dan follow-up 2.
                    </p>
                    <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 pt-1">
                        <input 
                            type="text" 
                            id="cron_url_input" 
                            readonly 
                            value="<?= htmlspecialchars($cronUrl) ?>" 
                            class="flex-1 min-w-0 px-3.5 py-2 rounded-xl bg-white border border-slate-200 font-mono text-xs text-slate-700 select-all outline-none truncate"
                        >
                        <button 
                            type="button" 
                            onclick="copyCronUrl()" 
                            id="btn-copy-cron"
                            class="btn-press px-4 py-2 rounded-xl bg-white hover:bg-slate-100 border border-slate-200 text-slate-800 text-xs font-bold transition shadow-sm whitespace-nowrap"
                        >
                            Salin URL
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Scraper Worker Node.js (Puppeteer) Section -->
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-surface p-6 space-y-5">
            <div class="flex items-start justify-between">
                <div>
                    <h2 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                        <span class="p-1.5 bg-indigo-50 border border-indigo-200 text-indigo-600 rounded-lg text-base">🤖</span>
                        Scraper Worker Engine (Puppeteer di Laptop / Local)
                    </h2>
                    <p class="text-xs text-slate-500 mt-1">
                        Hubungkan ke worker Node.js di laptop Anda (via Cloudflare Tunnel / Ngrok) agar scraping gratis Google Maps berjalan tanpa membebani server cPanel.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="testScraperConnection()" id="btn-test-scraper" class="btn-press inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 border border-slate-200 text-slate-800 text-xs font-semibold transition">
                        <span>⚡</span> Test Koneksi Scraper
                    </button>
                </div>
            </div>

            <div id="test-scraper-box" class="hidden p-3 rounded-xl text-xs font-medium"></div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="scraper_worker_url" class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-2">
                        Worker URL (Endpoint Laptop / Server)
                    </label>
                    <input 
                        type="text" 
                        id="scraper_worker_url" 
                        name="scraper_worker_url" 
                        value="<?= htmlspecialchars($scraperWorkerUrl) ?>" 
                        placeholder="http://127.0.0.1:3123 atau https://xxxx.trycloudflare.com" 
                        class="w-full px-4 py-2.5 rounded-xl bg-slate-50 border border-slate-200 focus:bg-white focus:border-indigo-600 focus:ring-2 focus:ring-indigo-100 text-xs font-mono text-slate-900 placeholder-slate-400 transition outline-none"
                    >
                    <span class="text-[11px] text-slate-500 mt-1 block font-medium">
                        Lokal: <code class="text-slate-700 bg-slate-100 px-1 py-0.5 rounded">http://127.0.0.1:3123</code> | cPanel: masukkan URL <strong>Cloudflare Tunnel / Ngrok</strong> laptop Anda.
                    </span>
                </div>

                <div>
                    <label for="scraper_secret_token" class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-2">
                        API Secret Token (Opsional)
                    </label>
                    <input 
                        type="password" 
                        id="scraper_secret_token" 
                        name="scraper_secret_token" 
                        value="<?= htmlspecialchars($scraperSecretToken) ?>" 
                        placeholder="Kunci rahasia (opsional)" 
                        class="w-full px-4 py-2.5 rounded-xl bg-slate-50 border border-slate-200 focus:bg-white focus:border-indigo-600 focus:ring-2 focus:ring-indigo-100 text-xs font-mono text-slate-900 placeholder-slate-400 transition outline-none"
                    >
                    <span class="text-[11px] text-slate-500 mt-1 block font-medium">
                        Keamanan ekstra jika worker di laptop dibuka ke publik via Tunnel.
                    </span>
                </div>
            </div>
        </div>

        <!-- Google Places API Section (Opsional) -->
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-surface p-6 space-y-4">
            <div>
                <h2 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                    <span class="p-1.5 bg-sky-50 border border-sky-200 text-sky-600 rounded-lg text-base">🗺️</span>
                    Google Places API Key (Opsional)
                </h2>
                <p class="text-xs text-slate-500 mt-1">Hanya diperlukan bila Anda ingin menggunakan metode pencarian resmi berbayar Google Cloud Console.</p>
            </div>

            <div>
                <label for="google_api_key" class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-2">
                    API Key
                </label>
                <input 
                    type="password" 
                    id="google_api_key" 
                    name="google_api_key" 
                    value="<?= htmlspecialchars($apiKey) ?>" 
                    placeholder="AIzaSy..." 
                    class="w-full px-4 py-2.5 rounded-xl bg-slate-50 border border-slate-200 focus:bg-white focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 text-xs font-mono text-slate-900 placeholder-slate-400 transition outline-none"
                >
            </div>
        </div>

        <!-- Drip Message Templates Section (5 Steps Per Group) -->
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-surface p-6 space-y-6">
            
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 pb-4">
                <div>
                    <h2 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                        <span class="p-1.5 bg-purple-50 border border-purple-200 text-purple-600 rounded-lg text-base">💬</span>
                        Template Drip 5-Langkah WhatsApp
                    </h2>
                    <p class="text-xs text-slate-500 mt-1">Sesuaikan isi pesan penawaran pembuatan website dan jadwal otomatisnya.</p>
                </div>

                <!-- Group Selector Dropdown -->
                <div class="flex items-center gap-2">
                    <label class="text-xs font-bold text-slate-600 whitespace-nowrap">Target Grup:</label>
                    <select 
                        id="target_group_selector" 
                        onchange="switchGroup(this.value)" 
                        class="px-3 py-1.5 rounded-xl bg-slate-50 border border-slate-200 text-xs font-semibold text-slate-800 focus:bg-white focus:border-emerald-600 outline-none"
                    >
                        <option value="" <?= $selectedGroupId === null ? 'selected' : '' ?>>⚙️ Template Default (Semua Grup)</option>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= $g['id'] ?>" <?= $selectedGroupId === (int)$g['id'] ? 'selected' : '' ?>>
                                📁 <?= htmlspecialchars($g['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php if ($isInherited): ?>
                <div class="p-3 bg-sky-50 border border-sky-200 text-sky-800 rounded-xl text-xs flex items-center gap-2">
                    <span>ℹ️</span> Grup ini saat ini masih menggunakan warisan dari <strong>Template Default</strong>. Edit dan simpan di bawah untuk menjadikannya template kustom khusus grup ini.
                </div>
            <?php endif; ?>

            <!-- Tab Langkah Pesan (Segmented Control adhering to Emil Kowalski motion) -->
            <div class="flex flex-wrap p-1 rounded-xl bg-slate-100 border border-slate-200/80 gap-1">
                <button type="button" onclick="showStepTab(1)" id="tab-btn-1" class="step-tab-btn btn-press px-3.5 py-2 text-xs font-bold rounded-lg transition bg-white text-slate-900 shadow-sm border border-slate-200">
                    1️⃣ Pesan 1 (Kirim Grup)
                </button>
                <button type="button" onclick="showStepTab(2)" id="tab-btn-2" class="step-tab-btn btn-press px-3.5 py-2 text-xs font-medium rounded-lg transition text-slate-600 hover:text-slate-900 hover:bg-slate-200/60">
                    2️⃣ Pesan 2 (Solusi)
                </button>
                <button type="button" onclick="showStepTab(3)" id="tab-btn-3" class="step-tab-btn btn-press px-3.5 py-2 text-xs font-medium rounded-lg transition text-slate-600 hover:text-slate-900 hover:bg-slate-200/60">
                    3️⃣ Pesan 3 (CTA Portofolio)
                </button>
                <button type="button" onclick="showStepTab(4)" id="tab-btn-4" class="step-tab-btn btn-press px-3.5 py-2 text-xs font-medium rounded-lg transition text-slate-600 hover:text-slate-900 hover:bg-slate-200/60">
                    4️⃣ Follow-up 1 (Otomatis)
                </button>
                <button type="button" onclick="showStepTab(5)" id="tab-btn-5" class="step-tab-btn btn-press px-3.5 py-2 text-xs font-medium rounded-lg transition text-slate-600 hover:text-slate-900 hover:bg-slate-200/60">
                    5️⃣ Follow-up 2 (Otomatis)
                </button>
            </div>

            <!-- Dynamic Tag Buttons -->
            <div class="flex flex-wrap items-center gap-1.5 p-3 bg-slate-50 rounded-xl border border-slate-200">
                <span class="text-[11px] font-bold text-slate-500 mr-2 uppercase tracking-wider">📌 Sisipkan Tag:</span>
                <button type="button" onclick="insertTag('[waktu]')" class="btn-press px-2.5 py-1 bg-white hover:bg-emerald-50 hover:text-emerald-700 border border-slate-200 hover:border-emerald-300 rounded-lg text-xs font-mono font-bold text-emerald-600 transition shadow-sm" title="pagi / siang / sore / malam otomatis">
                    [waktu] <span class="text-[10px] text-slate-400 font-normal">(pagi/siang/sore/malam)</span>
                </button>
                <button type="button" onclick="insertTag('[nama_usaha]')" class="btn-press px-2.5 py-1 bg-white hover:bg-slate-100 hover:text-slate-900 border border-slate-200 rounded-lg text-xs font-mono font-medium text-slate-700 transition shadow-sm">
                    [nama_usaha]
                </button>
                <button type="button" onclick="insertTag('[kategori]')" class="btn-press px-2.5 py-1 bg-white hover:bg-slate-100 hover:text-slate-900 border border-slate-200 rounded-lg text-xs font-mono font-medium text-slate-700 transition shadow-sm">
                    [kategori]
                </button>
                <button type="button" onclick="insertTag('[kota]')" class="btn-press px-2.5 py-1 bg-white hover:bg-slate-100 hover:text-slate-900 border border-slate-200 rounded-lg text-xs font-mono font-medium text-slate-700 transition shadow-sm">
                    [kota]
                </button>
                <button type="button" onclick="insertTag('[alamat]')" class="btn-press px-2.5 py-1 bg-white hover:bg-slate-100 hover:text-slate-900 border border-slate-200 rounded-lg text-xs font-mono font-medium text-slate-700 transition shadow-sm">
                    [alamat]
                </button>
                <button type="button" onclick="insertTag('[rating]')" class="btn-press px-2.5 py-1 bg-white hover:bg-slate-100 hover:text-slate-900 border border-slate-200 rounded-lg text-xs font-mono font-medium text-slate-700 transition shadow-sm">
                    [rating]
                </button>
            </div>

            <!-- Tab Content for Steps 1 - 5 -->
            <?php for ($step = 1; $step <= 5; $step++): 
                $tpl = $currentTemplates[$step] ?? null;
                $textVal = $tpl['message_text'] ?? '';
                $daysVal = $tpl['delay_days'] ?? ($step === 4 ? 1 : ($step === 5 ? 2 : 0));
                $hoursVal = $tpl['delay_hours'] ?? 0;
            ?>
                <div id="step-pane-<?= $step ?>" class="step-pane <?= $step === 1 ? '' : 'hidden' ?> space-y-4">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                        <div>
                            <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider">
                                <?php if ($step === 1): ?>
                                    Langkah 1: Pesan Pertama (Outreach Pembuka)
                                <?php elseif ($step === 2): ?>
                                    Langkah 2: Pesan Lanjutan (Penawaran Solusi)
                                <?php elseif ($step === 3): ?>
                                    Langkah 3: Pesan Detail &amp; Call-to-Action Portofolio
                                <?php elseif ($step === 4): ?>
                                    Langkah 4: Follow-up Pertama (Pengingat Otomatis)
                                <?php elseif ($step === 5): ?>
                                    Langkah 5: Follow-up Kedua (Penawaran Terakhir Otomatis)
                                <?php endif; ?>
                            </h3>
                            <p class="text-xs text-slate-500 mt-0.5">
                                <?php if ($step === 1): ?>
                                    Dikirim massal saat Anda mengklik tombol "Kirim Pesan 1" pada grup prospek. Mengubah status menjadi <strong>Dihubungi</strong>.
                                <?php elseif ($step === 2): ?>
                                    Muncul sebagai tombol di baris tabel setelah Pesan 1 terkirim. Tombol akan hilang setelah diklik agar tidak terkirim ganda.
                                <?php elseif ($step === 3): ?>
                                    Muncul di tabel setelah Pesan 2 terkirim. Begitu terkirim, tombol hilang &amp; jadwal antrean Follow-up otomatis diaktifkan.
                                <?php elseif ($step === 4): ?>
                                    Dijalankan otomatis oleh sistem setelah Pesan 3 terkirim, <strong>hanya jika</strong> status prospek masih <em>Dihubungi</em>.
                                <?php elseif ($step === 5): ?>
                                    Dijalankan otomatis setelah Follow-up 1 terkirim, jika status prospek tetap <em>Dihubungi</em>.
                                <?php endif; ?>
                            </p>
                        </div>

                        <?php if ($step >= 4): ?>
                            <!-- Setting Jeda Delay untuk Follow Up -->
                            <div class="bg-purple-50 border border-purple-200 rounded-xl px-3 py-2 flex items-center gap-2 self-start">
                                <span class="text-xs font-bold text-purple-700">⏰ Jeda:</span>
                                <input type="number" min="0" max="30" name="delay_days_<?= $step ?>" value="<?= $daysVal ?>" class="w-12 px-1.5 py-1 bg-white border border-purple-300 rounded text-xs font-mono font-bold text-slate-900 text-center outline-none">
                                <span class="text-xs text-purple-700 font-medium">Hari</span>
                                <input type="number" min="0" max="23" name="delay_hours_<?= $step ?>" value="<?= $hoursVal ?>" class="w-12 px-1.5 py-1 bg-white border border-purple-300 rounded text-xs font-mono font-bold text-slate-900 text-center outline-none">
                                <span class="text-xs text-purple-700 font-medium">Jam</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <textarea 
                            id="template_step_<?= $step ?>" 
                            name="template_step_<?= $step ?>" 
                            rows="6" 
                            class="w-full px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 focus:bg-white focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 text-xs text-slate-900 placeholder-slate-400 leading-relaxed transition outline-none"
                            oninput="updatePreview(<?= $step ?>)"
                        ><?= htmlspecialchars($textVal) ?></textarea>
                    </div>

                    <!-- Live WhatsApp Simulator Preview (WhatsApp Light Style) -->
                    <div class="pt-3 border-t border-slate-100">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-bold text-slate-700 flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                Simulasi Tampilan WhatsApp Prospek (Langkah <?= $step ?>):
                            </span>
                            <span class="text-[11px] text-slate-500 font-mono">Jam sekarang: <?= date('H:i') ?> WIB &rarr; [waktu] menjadi: <strong class="text-emerald-700"><?= OneSenderService::getTimeGreeting() ?></strong></span>
                        </div>
                        <div class="bg-[#efeae2] border border-slate-200 rounded-2xl p-4 max-w-lg shadow-sm">
                            <div class="bg-white rounded-2xl rounded-tl-sm p-3.5 shadow-sm text-xs text-slate-800 whitespace-pre-wrap leading-relaxed border-l-4 border-emerald-500 relative" id="wa-preview-<?= $step ?>">
                                <!-- Diisi via JS -->
                            </div>
                            <div class="flex justify-end text-[10px] text-slate-400 font-mono mt-1.5 pr-1 font-semibold">
                                <?= date('H:i') ?> &bull; Dibaca ✓✓
                            </div>
                        </div>
                    </div>
                </div>
            <?php endfor; ?>

        </div>

        <div class="flex justify-end pt-2">
            <button type="submit" class="btn-press inline-flex items-center gap-2 px-6 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs shadow-sm transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                Simpan Semua Pengaturan &amp; Template
            </button>
        </div>

    </form>
</div>

<script>
let currentActiveStep = 1;

function switchGroup(groupId) {
    if (groupId) {
        window.location.href = 'settings.php?group_id=' + encodeURIComponent(groupId);
    } else {
        window.location.href = 'settings.php';
    }
}

function showStepTab(step) {
    currentActiveStep = step;
    for (let i = 1; i <= 5; i++) {
        const pane = document.getElementById('step-pane-' + i);
        const tabBtn = document.getElementById('tab-btn-' + i);
        if (i === step) {
            pane.classList.remove('hidden');
            tabBtn.className = 'step-tab-btn btn-press px-3.5 py-2 text-xs font-bold rounded-lg transition bg-white text-slate-900 shadow-sm border border-slate-200';
        } else {
            pane.classList.add('hidden');
            tabBtn.className = 'step-tab-btn btn-press px-3.5 py-2 text-xs font-medium rounded-lg transition text-slate-600 hover:text-slate-900 hover:bg-slate-200/60';
        }
    }
    updatePreview(step);
}

function insertTag(tag) {
    const textarea = document.getElementById('template_step_' + currentActiveStep);
    if (!textarea) return;
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const text = textarea.value;
    textarea.value = text.substring(0, start) + tag + text.substring(end);
    textarea.focus();
    textarea.selectionStart = textarea.selectionEnd = start + tag.length;
    updatePreview(currentActiveStep);
    window.toast.info('Tag ' + tag + ' disisipkan');
}

function getGreetingClient() {
    const hour = new Date().getHours();
    if (hour >= 5 && hour < 11) return 'pagi';
    if (hour >= 11 && hour < 15) return 'siang';
    if (hour >= 15 && hour < 18) return 'sore';
    return 'malam';
}

function updatePreview(step) {
    const textarea = document.getElementById('template_step_' + step);
    const previewEl = document.getElementById('wa-preview-' + step);
    if (!textarea || !previewEl) return;

    const raw = textarea.value;
    const dummy = {
        '[waktu]': getGreetingClient(),
        '[nama_usaha]': 'Klinik Gigi Senyum Bahagia',
        '[kategori]': 'Klinik Dokter Gigi',
        '[alamat]': 'Jl. Diponegoro No. 12, Bandung',
        '[kota]': 'Bandung',
        '[rating]': '4.9'
    };

    let preview = raw;
    for (const [key, val] of Object.entries(dummy)) {
        preview = preview.replaceAll(key, val);
    }
    previewEl.textContent = preview;
}

document.addEventListener('DOMContentLoaded', () => {
    for (let i = 1; i <= 5; i++) {
        updatePreview(i);
    }
});

function testConnection() {
    const btn = document.getElementById('btn-test-conn');
    const resultBox = document.getElementById('test-result-box');
    const apiUrl = document.getElementById('onesender_api_url').value;
    const token = document.getElementById('onesender_token').value;

    btn.disabled = true;
    btn.innerHTML = '<span>⏳</span> Menguji...';
    resultBox.classList.remove('hidden', 'bg-emerald-50', 'text-emerald-800', 'border-emerald-200', 'bg-rose-50', 'text-rose-800', 'border-rose-200', 'bg-amber-50', 'text-amber-800', 'border-amber-200');
    resultBox.classList.add('bg-slate-50', 'text-slate-700', 'border', 'border-slate-200');
    resultBox.textContent = 'Menghubungi endpoint ' + apiUrl + '...';

    const formData = new FormData();
    formData.append('api_url', apiUrl);
    formData.append('token', token);

    fetch('actions/test_onesender.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<span>⚡</span> Test Koneksi OneSender';
        resultBox.classList.remove('bg-slate-50', 'text-slate-700');
        if (data.success) {
            resultBox.classList.add('bg-emerald-50', 'text-emerald-800', 'border', 'border-emerald-200');
            resultBox.innerHTML = '<strong>✅ Terhubung!</strong> ' + (data.message || 'Server OneSender aktif.');
            window.toast.success('Koneksi OneSender berhasil terhubung!');
        } else {
            resultBox.classList.add('bg-amber-50', 'text-amber-800', 'border', 'border-amber-200');
            resultBox.innerHTML = '<strong>⚠️ Perhatian:</strong> ' + (data.message || 'Gagal menghubungi server OneSender.');
            window.toast.error('Gagal terhubung ke OneSender');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<span>⚡</span> Test Koneksi OneSender';
        resultBox.classList.remove('bg-slate-50', 'text-slate-700');
        resultBox.classList.add('bg-rose-50', 'text-rose-800', 'border', 'border-rose-200');
        resultBox.innerHTML = '<strong>❌ Error:</strong> ' + err.message;
        window.toast.error('Kesalahan koneksi: ' + err.message);
    });
}

function testScraperConnection() {
    const btn = document.getElementById('btn-test-scraper');
    const resultBox = document.getElementById('test-scraper-box');
    const workerUrl = document.getElementById('scraper_worker_url').value;
    const token = document.getElementById('scraper_secret_token').value;

    btn.disabled = true;
    btn.innerHTML = '<span>⏳</span> Menguji...';
    resultBox.classList.remove('hidden', 'bg-emerald-50', 'text-emerald-800', 'border-emerald-200', 'bg-rose-50', 'text-rose-800', 'border-rose-200', 'bg-amber-50', 'text-amber-800', 'border-amber-200');
    resultBox.classList.add('bg-slate-50', 'text-slate-700', 'border', 'border-slate-200');
    resultBox.textContent = 'Menghubungi endpoint ' + workerUrl + '...';

    const formData = new FormData();
    formData.append('worker_url', workerUrl);
    formData.append('token', token);

    fetch('actions/test_scraper.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<span>⚡</span> Test Koneksi Scraper';
        resultBox.classList.remove('bg-slate-50', 'text-slate-700');
        if (data.success) {
            resultBox.classList.add('bg-emerald-50', 'text-emerald-800', 'border', 'border-emerald-200');
            resultBox.innerHTML = '<strong>✅ Terhubung!</strong> ' + (data.message || 'Scraper worker laptop aktif.');
            window.toast.success('Koneksi Scraper worker berhasil terhubung!');
        } else {
            resultBox.classList.add('bg-amber-50', 'text-amber-800', 'border', 'border-amber-200');
            resultBox.innerHTML = '<strong>⚠️ Perhatian:</strong> ' + (data.message || 'Gagal menghubungi Scraper worker.');
            window.toast.error('Gagal terhubung ke Scraper worker');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<span>⚡</span> Test Koneksi Scraper';
        resultBox.classList.remove('bg-slate-50', 'text-slate-700');
        resultBox.classList.add('bg-rose-50', 'text-rose-800', 'border', 'border-rose-200');
        resultBox.innerHTML = '<strong>❌ Error:</strong> ' + err.message;
        window.toast.error('Kesalahan koneksi: ' + err.message);
    });
}

function copyCronUrl() {
    const input = document.getElementById('cron_url_input');
    const btn = document.getElementById('btn-copy-cron');
    input.select();
    input.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(input.value).then(() => {
        window.toast.success('URL Cron Job berhasil disalin!');
        btn.textContent = 'Tersalin! ✓';
        setTimeout(() => { btn.textContent = 'Salin URL'; }, 2000);
    }).catch(() => {
        document.execCommand('copy');
        window.toast.success('URL Cron Job berhasil disalin!');
        btn.textContent = 'Tersalin! ✓';
        setTimeout(() => { btn.textContent = 'Salin URL'; }, 2000);
    });
}
</script>

<?php require_once __DIR__ . '/views/footer.php'; ?>
