<?php
// test_webhook.php
// Alat simulasi pengujian webhook OneSender tanpa perlu kirim WhatsApp asli
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/services/OneSenderService.php';

$db = getDb();
$messageResult = null;

// Ambil beberapa leads terakhir untuk pilihan simulasi
$leads = $db->query("SELECT id, name, phone_wa, status, last_step_sent, last_incoming_message FROM leads ORDER BY id DESC LIMIT 20")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $leadId = (int)($_POST['lead_id'] ?? 0);
    $customPhone = trim($_POST['phone'] ?? '');
    $simulatedMsg = trim($_POST['message'] ?? '');

    // Cari nomor telepon
    $phone = $customPhone;
    if ($leadId > 0) {
        $stmt = $db->prepare("SELECT phone_wa FROM leads WHERE id = :id");
        $stmt->execute([':id' => $leadId]);
        $row = $stmt->fetch();
        if ($row && !empty($row['phone_wa'])) {
            $phone = $row['phone_wa'];
        }
    }

    if (empty($phone) || empty($simulatedMsg)) {
        $messageResult = ['status' => 'error', 'message' => 'Nomor HP dan Pesan tidak boleh kosong.'];
    } else {
        // Buat payload seperti yang dikirim oleh OneSender
        $payload = json_encode([
            'event'        => 'message.inbound',
            'phone'        => $phone,
            'sender_phone' => $phone,
            'message'      => $simulatedMsg,
            'message_text' => $simulatedMsg,
            'is_from_me'   => false,
            'is_group'     => false
        ]);

        // Kirim request ke webhook internal
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $webhookUrl = $protocol . $host . rtrim(dirname($_SERVER['REQUEST_URI'] ?? ''), '/') . '/actions/webhook_onesender.php';

        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $parsed = json_decode($response, true);
        $messageResult = [
            'http_code' => $httpCode,
            'raw'       => $response,
            'parsed'    => $parsed ?: ['status' => 'raw_output', 'body' => $response]
        ];
    }
}

$pageTitle = 'Simulator Webhook OneSender | MapLeads Pro';
$activeNav = 'settings';
require_once __DIR__ . '/views/header.php';
?>

<div class="max-w-3xl mx-auto space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-slate-900 flex items-center gap-2">
                <span>🧪</span> Simulator Webhook OneSender
            </h1>
            <p class="text-xs text-slate-500 mt-1">Uji coba alur masuk balasan pesan klien & pemicu otomatis Pesan ke-2 tanpa perlu pulsa / WA asli.</p>
        </div>
        <a href="index.php" class="btn-press px-3.5 py-2 rounded-xl bg-white hover:bg-slate-50 border border-slate-200 text-xs font-semibold text-slate-700 shadow-sm">
            ← Kembali ke Dashboard
        </a>
    </div>

    <?php if ($messageResult): ?>
        <div class="p-4 rounded-2xl border <?= ($messageResult['http_code'] ?? 200) == 200 ? 'bg-slate-900 border-slate-800 text-white' : 'bg-rose-50 border-rose-200 text-rose-800' ?> space-y-2">
            <div class="flex items-center justify-between text-xs font-bold font-mono">
                <span>STATUS HTTP: <?= $messageResult['http_code'] ?? 'CLI' ?></span>
                <span class="text-emerald-400">Hasil Respon Webhook</span>
            </div>
            <pre class="text-xs font-mono bg-black/40 p-3 rounded-xl overflow-x-auto text-emerald-300"><?= htmlspecialchars(json_encode($messageResult['parsed'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
            <div class="pt-2 flex items-center justify-between text-xs">
                <span class="text-slate-400">Silakan buka dashboard untuk melihat gelembung balasan:</span>
                <a href="index.php" target="_blank" class="text-emerald-400 hover:text-emerald-300 underline font-semibold">Buka Dashboard di Tab Baru ↗</a>
            </div>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-surface p-5 sm:p-6 space-y-5">
        <form method="POST" action="test_webhook.php" class="space-y-4">
            <div>
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                    1. Pilih Prospek Target Simulasi
                </label>
                <select name="lead_id" id="lead_select" onchange="updatePhoneField(this)" class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-800 outline-none focus:bg-white focus:border-emerald-600 transition font-medium">
                    <option value="">-- Pilih dari data prospek --</option>
                    <?php foreach ($leads as $l): ?>
                        <option value="<?= $l['id'] ?>" data-phone="<?= htmlspecialchars($l['phone_wa'] ?? '') ?>" data-status="<?= htmlspecialchars($l['status']) ?>" data-step="<?= $l['last_step_sent'] ?>">
                            #<?= $l['id'] ?> - <?= htmlspecialchars($l['name']) ?> (WA: <?= $l['phone_wa'] ?>) [Status: <?= $l['status'] ?> | Step: <?= $l['last_step_sent'] ?>]
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-[11px] text-slate-500 mt-1">
                    Tip: Untuk menguji pemicu Pesan 2 otomatis, pastikan prospek berstatus <strong>dihubungi</strong> dan sudah kirim <strong>Pesan 1 (Step 1)</strong>.
                </p>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                    Nomor WhatsApp Pengirim
                </label>
                <input type="text" name="phone" id="phone_input" placeholder="628123456789" class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200 text-xs font-mono text-slate-800 outline-none focus:bg-white focus:border-emerald-600 transition" required>
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                    2. Teks Balasan Klien (Simulasi WhatsApp Masuk)
                </label>
                <textarea name="message" id="message_input" rows="3" placeholder="Ketik balasan di sini, misal: 'Iya betul dengan saya, ada apa ya?'" class="w-full px-3.5 py-2.5 rounded-xl bg-white border border-slate-200 text-xs text-slate-800 outline-none focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 transition" required>Iya betul dengan saya, ada apa ya?</textarea>
                
                <!-- Quick Suggestion Buttons -->
                <div class="flex flex-wrap gap-1.5 mt-2">
                    <span class="text-[11px] text-slate-400 py-1">Coba contoh:</span>
                    <button type="button" onclick="setPresetMsg('Iya betul dengan saya, ada apa ya?')" class="px-2.5 py-1 rounded-lg bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 text-emerald-700 text-[11px] font-medium transition">
                        ✓ Cocok Trigger ("iya betul")
                    </button>
                    <button type="button" onclick="setPresetMsg('Halo minat infonya dong kak')" class="px-2.5 py-1 rounded-lg bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 text-emerald-700 text-[11px] font-medium transition">
                        ✓ Cocok Trigger ("minat")
                    </button>
                    <button type="button" onclick="setPresetMsg('Maaf salah sambung')" class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 border border-slate-200 text-slate-700 text-[11px] font-medium transition">
                        ✗ Tidak Cocok (Bisa Ditambah Jadi Trigger)
                    </button>
                </div>
            </div>

            <div class="pt-3 border-t border-slate-100 flex justify-end">
                <button type="submit" class="btn-press px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-sm flex items-center gap-2">
                    <span>🚀</span> Kirim Simulasi Webhook
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function updatePhoneField(select) {
    const opt = select.options[select.selectedIndex];
    if (opt && opt.dataset.phone) {
        document.getElementById('phone_input').value = opt.dataset.phone;
    }
}
function setPresetMsg(text) {
    document.getElementById('message_input').value = text;
}
</script>

<?php require_once __DIR__ . '/views/footer.php'; ?>
