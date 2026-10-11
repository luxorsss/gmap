<?php
// search.php
if (function_exists('opcache_reset')) {
    @opcache_reset();
}
@set_time_limit(180);
@ini_set('max_execution_time', '180');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/services/GooglePlaces.php';
require_once __DIR__ . '/services/FreeGmapsScraper.php';

$apiKey = getSetting('google_api_key', '');
$waTemplate = getSetting('wa_template', '');

$results = null;
$error = null;
$stats = null;

$keyword = trim($_GET['keyword'] ?? '');
$location = trim($_GET['location'] ?? '');
$method = trim($_GET['method'] ?? 'free'); // 'free' (Puppeteer) atau 'api' (Google Places API)
$limit = min(50, max(5, (int)($_GET['limit'] ?? 20)));
$allowSocial = isset($_GET['allow_social']) ? true : (empty($_GET) ? true : false);

$availableGroups = getAllGroups();
$groupId = isset($_GET['group_id']) && $_GET['group_id'] !== '' && $_GET['group_id'] !== '__new__' ? (int)$_GET['group_id'] : null;
$newGroupName = trim($_GET['new_group_name'] ?? '');

if (!empty($newGroupName)) {
    $groupId = getOrCreateGroup($newGroupName);
    $availableGroups = getAllGroups();
}

$groupName = null;
if ($groupId) {
    foreach ($availableGroups as $g) {
        if ((int)$g['id'] === $groupId) {
            $groupName = $g['name'];
            break;
        }
    }
}

// Jika menggunakan API Resmi (hanya jika method=api)
if (!empty($keyword) && $method === 'api') {
    $fullQuery = !empty($location) ? "$keyword di $location" : $keyword;
    if (empty($apiKey)) {
        $error = 'Google Places API Key belum diisi. Silakan atur di <a href="settings.php" class="underline font-bold text-emerald-600">Pengaturan</a>, atau pilih metode <strong>Scraper Gratis (Puppeteer)</strong> di bawah.';
    } else {
        $service = new GooglePlacesService($apiKey);
        $apiRes = $service->searchPlaces($fullQuery, $limit);

        if (!$apiRes['success']) {
            $error = $apiRes['error'];
        } else {
            $rawPlaces = $apiRes['places'];
            $qualified = GooglePlacesService::filterAndFormatLeads($rawPlaces, $allowSocial);
            processAndSaveLeads($qualified, count($rawPlaces), $location, $fullQuery, $groupId, $stats, $results);
        }
    }
}

function processAndSaveLeads(array $qualified, int $rawCount, string $location, string $keywordQuery, ?int $groupId, &$stats, &$results) {
    $db = getDb();
    $stmt = $db->prepare("
        INSERT INTO leads (place_id, name, category, group_id, search_keyword, address, city, phone_raw, phone_wa, rating, user_ratings_total, status)
        VALUES (:place_id, :name, :category, :group_id, :search_keyword, :address, :city, :phone_raw, :phone_wa, :rating, :user_ratings_total, 'belum_dihubungi')
        ON DUPLICATE KEY UPDATE 
            name = VALUES(name),
            category = VALUES(category),
            group_id = COALESCE(VALUES(group_id), group_id),
            search_keyword = COALESCE(VALUES(search_keyword), search_keyword),
            address = VALUES(address),
            rating = VALUES(rating),
            user_ratings_total = VALUES(user_ratings_total),
            updated_at = NOW()
    ");

    $newlyInserted = 0;
    $updatedExisting = 0;

    foreach ($qualified as $lead) {
        $checkStmt = $db->prepare("SELECT id FROM leads WHERE phone_wa = ? LIMIT 1");
        $checkStmt->execute([$lead['phone_wa']]);
        $exists = $checkStmt->fetch();

        $stmt->execute([
            ':place_id'           => $lead['place_id'],
            ':name'               => $lead['name'],
            ':category'           => $lead['category'],
            ':group_id'           => $groupId,
            ':search_keyword'     => $keywordQuery,
            ':address'            => $lead['address'],
            ':city'               => !empty($lead['city']) ? $lead['city'] : $location,
            ':phone_raw'          => $lead['phone_raw'],
            ':phone_wa'           => $lead['phone_wa'],
            ':rating'             => $lead['rating'],
            ':user_ratings_total' => $lead['user_ratings_total'],
        ]);

        if ($exists) {
            $updatedExisting++;
        } else {
            $newlyInserted++;
        }
    }

    $stats = [
        'total_raw'        => $rawCount,
        'total_qualified'  => count($qualified),
        'newly_saved'      => $newlyInserted,
        'already_existing' => $updatedExisting,
    ];

    $results = $qualified;
}

$pageTitle = 'Scrape Google Maps | MapLeads Pro';
$activeNav = 'search';
require_once __DIR__ . '/views/header.php';
?>

<div class="space-y-5 sm:space-y-6">

    <!-- Header Section -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2.5">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-slate-900 tracking-tight">
                Pencarian Target Google Maps
            </h1>
            <p class="text-xs text-slate-500 mt-0.5">
                Filter otomatis tempat yang <strong class="text-slate-800">tidak memiliki website resmi</strong> namun memiliki kontak WhatsApp.
            </p>
        </div>
        <div class="flex items-center gap-2 self-start sm:self-auto">
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                Filter Anti-Website Aktif
            </span>
        </div>
    </div>

    <!-- Info Scraper Banner -->
    <div class="bg-indigo-50 border border-indigo-200/80 rounded-2xl p-4 sm:p-5 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div class="flex items-start gap-3">
            <span class="p-2 bg-indigo-100 text-indigo-700 rounded-xl text-lg flex-shrink-0">🚀</span>
            <div>
                <h3 class="text-xs font-bold text-indigo-950 uppercase tracking-wider">
                    Direkomendasikan: Gunakan MapLeads Scraper (Lokal)
                </h3>
                <p class="text-xs text-indigo-800 mt-0.5 leading-relaxed">
                    Untuk shared hosting cPanel, gunakan project <strong>MapLeads Scraper</strong> di laptop/lokal agar proses scraping Google Maps super cepat, anti-timeout, dan hasil otomatis tersinkronisasi ke sini via Inbound API.
                </p>
            </div>
        </div>
        <a href="settings.php" class="btn-press px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold transition shadow-sm whitespace-nowrap self-stretch sm:self-auto text-center">
            Lihat API Key ↗
        </a>
    </div>

    <!-- Search Form Card -->
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-surface p-4 sm:p-6">
        <form method="GET" action="search.php" class="space-y-4 sm:space-y-5" id="searchForm">
            
            <!-- Mode Pilihan Scraper (Segmented Control) -->
            <div class="p-1.5 rounded-xl bg-slate-100 border border-slate-200/80 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-2">
                <span class="text-[11px] font-bold text-slate-500 uppercase tracking-wider px-2 sm:px-3 flex items-center gap-1.5">
                    <span>⚡</span> Metode Scraping
                </span>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                    <label class="btn-press flex items-center gap-2 px-3 py-2 rounded-lg cursor-pointer text-xs font-semibold transition <?= $method === 'free' ? 'bg-white shadow-sm border border-slate-200 text-slate-900 font-bold' : 'bg-transparent text-slate-500 hover:text-slate-900' ?>">
                        <input type="radio" name="method" value="free" <?= $method === 'free' ? 'checked' : '' ?> class="sr-only">
                        <span class="w-2 h-2 rounded-full <?= $method === 'free' ? 'bg-emerald-600 ring-2 ring-emerald-200' : 'bg-slate-300' ?>"></span>
                        <span>Scraper Otomatis (100% Gratis &amp; Live)</span>
                    </label>
                    <label class="btn-press flex items-center gap-2 px-3 py-2 rounded-lg cursor-pointer text-xs font-semibold transition <?= $method === 'api' ? 'bg-white shadow-sm border border-slate-200 text-slate-900 font-bold' : 'bg-transparent text-slate-500 hover:text-slate-900' ?>">
                        <input type="radio" name="method" value="api" <?= $method === 'api' ? 'checked' : '' ?> class="sr-only">
                        <span class="w-2 h-2 rounded-full <?= $method === 'api' ? 'bg-emerald-600 ring-2 ring-emerald-200' : 'bg-slate-300' ?>"></span>
                        <span>Google Places API (Perlu API Key)</span>
                    </label>
                </div>
            </div>

            <!-- Input Fields Grid -->
            <div class="grid grid-cols-1 md:grid-cols-12 gap-3.5 sm:gap-4">
                <div class="md:col-span-5">
                    <label for="keyword" class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">
                        Kategori / Bidang Usaha
                    </label>
                    <input 
                        type="text" 
                        id="keyword" 
                        name="keyword" 
                        value="<?= htmlspecialchars($keyword) ?>" 
                        placeholder="Contoh: Bengkel Mobil, Klinik Gigi, Toko Mebel" 
                        required
                        class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200 focus:bg-white focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 text-xs text-slate-900 placeholder-slate-400 transition outline-none"
                    >
                </div>

                <div class="md:col-span-4">
                    <label for="location" class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">
                        Lokasi / Kota Target
                    </label>
                    <input 
                        type="text" 
                        id="location" 
                        name="location" 
                        value="<?= htmlspecialchars($location) ?>" 
                        placeholder="Contoh: Bandung, Surabaya (opsional)" 
                        class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200 focus:bg-white focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 text-xs text-slate-900 placeholder-slate-400 transition outline-none"
                    >
                </div>

                <div class="md:col-span-3">
                    <label for="limit" class="block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1.5">
                        Target Ambil Data
                    </label>
                    <select 
                        id="limit" 
                        name="limit" 
                        class="w-full px-3 py-2.5 rounded-xl bg-slate-50 border border-slate-200 focus:bg-white focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 text-xs text-slate-700 transition font-semibold outline-none"
                    >
                        <option value="10" <?= $limit === 10 ? 'selected' : '' ?>>10 Tempat (Cepat)</option>
                        <option value="20" <?= $limit === 20 ? 'selected' : '' ?>>20 Tempat (Standar)</option>
                        <option value="30" <?= $limit === 30 ? 'selected' : '' ?>>30 Tempat</option>
                        <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50 Tempat (Banyak)</option>
                    </select>
                </div>
            </div>

            <!-- Fitur Pilihan Grup Hasil Scrape -->
            <div class="bg-slate-50 border border-slate-200 rounded-xl p-3.5 sm:p-4 space-y-3">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1">
                    <div>
                        <label class="block text-xs font-bold text-slate-800 uppercase tracking-wider flex items-center gap-1.5">
                            <span>🏷️</span> Masukkan Hasil Scrape ke Grup
                        </label>
                        <p class="text-[11px] text-slate-500 mt-0.5">Pilih grup tujuan untuk hasil scrape ini, atau buat grup baru secara otomatis.</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-center">
                    <div class="sm:col-span-6">
                        <select 
                            id="group_id" 
                            name="group_id" 
                            onchange="handleGroupSelectChange(this)"
                            class="w-full px-3 py-2.5 rounded-xl bg-white border border-slate-200 focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 text-xs text-slate-700 font-semibold transition outline-none shadow-sm"
                        >
                            <option value="">-- Tanpa Grup (Simpan Biasa) --</option>
                            <?php foreach ($availableGroups as $g): ?>
                                <option value="<?= $g['id'] ?>" <?= $groupId === (int)$g['id'] ? 'selected' : '' ?>>
                                    📁 <?= htmlspecialchars($g['name']) ?> (<?= $g['total_leads'] ?> leads)
                                </option>
                            <?php endforeach; ?>
                            <option value="__new__" <?= !empty($newGroupName) ? 'selected' : '' ?>>
                                ➕ Buat Grup Baru...
                            </option>
                        </select>
                    </div>

                    <div id="newGroupContainer" class="<?= !empty($newGroupName) ? '' : 'hidden' ?> sm:col-span-6">
                        <div class="relative">
                            <input 
                                type="text" 
                                id="new_group_name" 
                                name="new_group_name" 
                                value="<?= htmlspecialchars($newGroupName) ?>"
                                placeholder="Ketik nama grup baru..." 
                                class="w-full pl-9 pr-4 py-2.5 rounded-xl bg-white border border-emerald-400 ring-2 ring-emerald-100 text-xs text-slate-900 placeholder-slate-400 transition outline-none shadow-sm"
                            >
                            <span class="absolute left-3 top-2.5 text-emerald-600 font-bold text-xs">➕</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filter Checkbox & Submit Action -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-1">
                <label class="flex items-center gap-2 cursor-pointer select-none">
                    <input 
                        type="checkbox" 
                        id="allow_social" 
                        name="allow_social" 
                        value="1" 
                        <?= $allowSocial ? 'checked' : '' ?>
                        class="w-4 h-4 rounded text-emerald-600 border-slate-300 focus:ring-emerald-500 cursor-pointer flex-shrink-0"
                    >
                    <span class="text-xs text-slate-600 leading-snug">
                        Sertakan tautan media sosial (Instagram/Facebook)
                    </span>
                </label>

                <div class="flex items-center gap-2">
                    <button 
                        type="button" 
                        id="stopBtn"
                        onclick="stopStreaming()"
                        class="hidden btn-press flex-1 sm:flex-none px-4 py-2.5 rounded-xl bg-rose-50 hover:bg-rose-100 border border-rose-200 text-rose-700 font-bold text-xs transition"
                    >
                        Hentikan
                    </button>
                    <button 
                        type="submit" 
                        id="submitBtn"
                        class="btn-press flex-1 sm:flex-none inline-flex items-center justify-center gap-2 px-6 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs shadow-sm transition"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        Mulai Scrape &amp; Filter
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Live Progress Card (Active during streaming) -->
    <div id="liveProgressCard" class="hidden bg-white border border-emerald-200 rounded-2xl p-4 sm:p-5 shadow-surface space-y-3.5">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1.5 border-b border-slate-100 pb-3">
            <div class="flex items-center gap-2.5 min-w-0">
                <span class="relative flex h-2.5 w-2.5 flex-shrink-0">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-600"></span>
                </span>
                <span class="font-bold text-slate-900 text-xs truncate" id="liveStatusText">Memulai Scraper...</span>
            </div>
            <span class="text-[11px] text-slate-500 font-mono" id="liveTimeIndicator">Live Stream Anti-Timeout</span>
        </div>

        <!-- Metric Badges -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5 sm:gap-3">
            <div class="bg-slate-50 p-2.5 sm:p-3 rounded-xl border border-slate-200">
                <span class="text-[10px] sm:text-[11px] text-slate-500 font-medium block">Total Diperiksa</span>
                <span class="text-lg sm:text-xl font-bold font-mono text-slate-900 num-tabular mt-1 block" id="liveScanned">0</span>
            </div>
            <div class="bg-emerald-50 p-2.5 sm:p-3 rounded-xl border border-emerald-200">
                <span class="text-[10px] sm:text-[11px] text-emerald-800 font-semibold block">Lolos Kriteria</span>
                <span class="text-lg sm:text-xl font-bold font-mono text-emerald-700 num-tabular mt-1 block" id="liveQualified">0</span>
            </div>
            <div class="bg-sky-50 p-2.5 sm:p-3 rounded-xl border border-sky-200">
                <span class="text-[10px] sm:text-[11px] text-sky-800 font-semibold block">Prospek Baru</span>
                <span class="text-lg sm:text-xl font-bold font-mono text-sky-700 num-tabular mt-1 block" id="liveNew">0</span>
            </div>
            <div class="bg-slate-50 p-2.5 sm:p-3 rounded-xl border border-slate-200">
                <span class="text-[10px] sm:text-[11px] text-slate-500 font-medium block">Sudah Ada</span>
                <span class="text-lg sm:text-xl font-bold font-mono text-slate-700 num-tabular mt-1 block" id="liveExisting">0</span>
            </div>
        </div>
    </div>

    <!-- Error Alert -->
    <div id="errorAlert" class="hidden p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 flex items-center space-x-3 text-xs">
        <svg class="w-5 h-5 flex-shrink-0 text-rose-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
        <div class="font-medium" id="errorMessage"><?= $error ?? '' ?></div>
    </div>

    <?php if ($error): ?>
        <script>
            document.getElementById('errorAlert').classList.remove('hidden');
        </script>
    <?php endif; ?>

    <!-- Results Section: Desktop Table & Mobile Cards -->
    <div id="resultsCard" class="<?= (empty($results) && !isset($_POST['keyword'])) ? 'hidden' : '' ?> bg-white rounded-2xl border border-slate-200/80 shadow-surface overflow-hidden">
        <div class="p-3.5 sm:p-4 border-b border-slate-200 flex items-center justify-between">
            <h2 class="text-xs font-bold text-slate-800 uppercase tracking-wider" id="tableTitle">Hasil Prospek Lolos Filter (Siap Ditawari)</h2>
            <a href="index.php" class="btn-press text-xs font-semibold text-emerald-600 hover:text-emerald-700 inline-flex items-center gap-1">
                Lihat di Data Prospek &rarr;
            </a>
        </div>

        <!-- Desktop Table View (Hidden on mobile < 640px) -->
        <div class="hidden sm:block overflow-x-auto">
            <table class="w-full text-left text-xs text-slate-700">
                <thead class="bg-slate-50 text-[11px] uppercase tracking-wider font-semibold text-slate-500 border-b border-slate-200">
                    <tr>
                        <th class="px-4 py-3">Nama Usaha</th>
                        <th class="px-4 py-3">Kategori</th>
                        <th class="px-4 py-3">Nomor WhatsApp</th>
                        <th class="px-4 py-3 hidden lg:table-cell">Rating</th>
                        <th class="px-4 py-3 hidden md:table-cell">Alamat</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100" id="leadsTableBody">
                    <?php if (!empty($results)): ?>
                        <?php foreach ($results as $item): ?>
                            <?php
                                $waMsg = $waTemplate;
                                $waMsg = str_replace('[nama_usaha]', $item['name'], $waMsg);
                                $waMsg = str_replace('[kategori]', $item['category'], $waMsg);
                                $waMsg = str_replace('[alamat]', $item['address'], $waMsg);
                                $waMsg = str_replace('[kota]', $item['city'] ?: $location, $waMsg);
                                $waMsg = str_replace('[rating]', (string)$item['rating'], $waMsg);
                                $waUrl = "https://api.whatsapp.com/send?phone=" . $item['phone_wa'] . "&text=" . rawurlencode($waMsg);
                            ?>
                            <tr class="interactive-row transition-colors">
                                <td class="px-4 py-3 font-semibold text-slate-900">
                                    <?= htmlspecialchars($item['name']) ?>
                                    <?php if (!empty($groupName)): ?>
                                        <span class="ml-1 inline-block text-[10px] px-1.5 py-0.5 rounded bg-purple-50 text-purple-700 border border-purple-200 font-semibold">
                                            📁 <?= htmlspecialchars($groupName) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-block px-2 py-0.5 rounded text-[11px] bg-slate-100 border border-slate-200 text-slate-600 font-medium">
                                        <?= htmlspecialchars($item['category']) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 font-mono font-semibold text-emerald-600">
                                    +<?= htmlspecialchars($item['phone_wa']) ?>
                                </td>
                                <td class="px-4 py-3 hidden lg:table-cell">
                                    <span class="inline-flex items-center gap-1 font-bold text-amber-500">
                                        ★ <?= number_format($item['rating'], 1) ?>
                                        <span class="text-[11px] text-slate-400 font-mono font-normal">(<?= $item['user_ratings_total'] ?>)</span>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-slate-500 max-w-xs truncate text-[11px] hidden md:table-cell" title="<?= htmlspecialchars($item['address']) ?>">
                                    <?= htmlspecialchars($item['address']) ?>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <a 
                                        href="<?= $waUrl ?>" 
                                        target="_blank" 
                                        class="btn-press inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-bold shadow-sm"
                                    >
                                        Chat WA
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile Cards Container (Visible on < 640px) -->
        <div class="sm:hidden divide-y divide-slate-100" id="leadsCardsBody">
            <?php if (!empty($results)): ?>
                <?php foreach ($results as $item): ?>
                    <?php
                        $waMsg = $waTemplate;
                        $waMsg = str_replace('[nama_usaha]', $item['name'], $waMsg);
                        $waMsg = str_replace('[kategori]', $item['category'], $waMsg);
                        $waMsg = str_replace('[alamat]', $item['address'], $waMsg);
                        $waMsg = str_replace('[kota]', $item['city'] ?: $location, $waMsg);
                        $waMsg = str_replace('[rating]', (string)$item['rating'], $waMsg);
                        $waUrl = "https://api.whatsapp.com/send?phone=" . $item['phone_wa'] . "&text=" . rawurlencode($waMsg);
                    ?>
                    <div class="p-3.5 space-y-2.5 bg-white">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <h3 class="font-bold text-slate-900 text-sm leading-snug break-words">
                                    <?= htmlspecialchars($item['name']) ?>
                                </h3>
                                <div class="flex flex-wrap items-center gap-1.5 mt-1">
                                    <span class="inline-block px-2 py-0.5 rounded-md text-[10px] bg-slate-100 border border-slate-200 text-slate-600 font-medium">
                                        <?= htmlspecialchars($item['category']) ?>
                                    </span>
                                    <?php if (!empty($groupName)): ?>
                                        <span class="inline-block px-2 py-0.5 rounded-md text-[10px] bg-purple-50 text-purple-700 border border-purple-200 font-semibold">
                                            📁 <?= htmlspecialchars($groupName) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span class="inline-flex items-center gap-1 font-bold text-amber-600 text-xs flex-shrink-0 bg-amber-50 px-2 py-1 rounded-lg border border-amber-200">
                                ★ <?= number_format($item['rating'], 1) ?>
                            </span>
                        </div>

                        <div class="bg-slate-50 rounded-xl p-2.5 text-xs space-y-1.5 border border-slate-200/60">
                            <div class="flex items-center justify-between">
                                <span class="text-[11px] text-slate-500 font-medium">WhatsApp:</span>
                                <a href="<?= $waUrl ?>" target="_blank" class="font-mono font-semibold text-emerald-600 hover:text-emerald-700">
                                    +<?= htmlspecialchars($item['phone_wa']) ?>
                                </a>
                            </div>
                            <div class="flex items-start justify-between text-[11px] pt-1 border-t border-slate-200/60">
                                <span class="text-slate-500 flex-shrink-0 mr-2 font-medium">Alamat:</span>
                                <span class="text-slate-700 truncate text-right flex-1" title="<?= htmlspecialchars($item['address']) ?>">
                                    <?= htmlspecialchars($item['address']) ?>
                                </span>
                            </div>
                        </div>

                        <div class="flex justify-end pt-1">
                            <a 
                                href="<?= $waUrl ?>" 
                                target="_blank" 
                                class="btn-press inline-flex items-center gap-1.5 px-4 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold shadow-sm"
                            >
                                <span>📱</span> Chat WhatsApp
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Empty state container for both views -->
        <div id="emptyResultsState" class="<?= (!empty($results) || !isset($_POST['keyword'])) ? 'hidden' : '' ?> px-6 py-12 text-center">
            <div class="max-w-sm mx-auto space-y-3">
                <div class="w-10 h-10 mx-auto rounded-xl bg-slate-100 border border-slate-200 flex items-center justify-center text-slate-400">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <p class="text-sm font-semibold text-slate-800">Tidak ada prospek yang memenuhi kriteria</p>
                <p class="text-xs text-slate-500 leading-relaxed">
                    Semua bisnis yang ditemukan sudah memiliki website resmi atau tidak menyertakan kontak WhatsApp. Coba variasikan kata kunci atau aktifkan opsi sertakan media sosial.
                </p>
            </div>
        </div>

    </div>

</div>

<script>
let eventSource = null;

function handleGroupSelectChange(select) {
    const container = document.getElementById('newGroupContainer');
    const input = document.getElementById('new_group_name');
    if (select.value === '__new__') {
        container.classList.remove('hidden');
        if (input) input.focus();
    } else {
        container.classList.add('hidden');
    }
}

function stopStreaming() {
    if (eventSource) {
        eventSource.close();
        eventSource = null;
    }
    document.getElementById('liveStatusText').textContent = 'Scraping dihentikan oleh pengguna.';
    document.getElementById('stopBtn').classList.add('hidden');
    document.getElementById('submitBtn').disabled = false;
    document.getElementById('submitBtn').classList.remove('opacity-50', 'cursor-not-allowed');
    window.toast.info('Scraping dihentikan.');
}

document.getElementById('searchForm').addEventListener('submit', function(e) {
    const methodInput = document.querySelector('input[name="method"]:checked');
    const method = methodInput ? methodInput.value : 'free';
    if (method !== 'free') {
        return true;
    }

    e.preventDefault();

    const keyword = document.getElementById('keyword').value.trim();
    const location = document.getElementById('location').value.trim();
    const limit = document.getElementById('limit').value;
    const allowSocial = document.getElementById('allow_social').checked ? '1' : '0';
    const groupSelect = document.getElementById('group_id');
    const groupId = groupSelect ? groupSelect.value : '';
    const newGroupName = document.getElementById('new_group_name') ? document.getElementById('new_group_name').value.trim() : '';

    if (!keyword) return;

    // Reset UI
    document.getElementById('errorAlert').classList.add('hidden');
    document.getElementById('liveProgressCard').classList.remove('hidden');
    document.getElementById('resultsCard').classList.remove('hidden');
    document.getElementById('leadsTableBody').innerHTML = '';
    document.getElementById('leadsCardsBody').innerHTML = '';
    document.getElementById('emptyResultsState').classList.add('hidden');
    document.getElementById('stopBtn').classList.remove('hidden');
    document.getElementById('submitBtn').disabled = true;
    document.getElementById('submitBtn').classList.add('opacity-50', 'cursor-not-allowed');

    document.getElementById('liveScanned').textContent = '0';
    document.getElementById('liveQualified').textContent = '0';
    document.getElementById('liveNew').textContent = '0';
    document.getElementById('liveExisting').textContent = '0';
    document.getElementById('liveStatusText').textContent = 'Membuka Google Maps...';

    window.toast.info('Memulai live scraping Google Maps...');

    const streamUrl = 'actions/stream_scrape.php?' + new URLSearchParams({
        keyword: keyword,
        location: location,
        limit: limit,
        allow_social: allowSocial,
        group_id: groupId,
        new_group_name: newGroupName
    });

    if (eventSource) {
        eventSource.close();
    }

    eventSource = new EventSource(streamUrl);

    eventSource.onmessage = function(e) {
        try {
            const data = JSON.parse(e.data);

            if (data.type === 'progress' || data.type === 'status') {
                document.getElementById('liveStatusText').textContent = data.status || 'Sedang memproses...';
                if (data.scanned !== undefined) document.getElementById('liveScanned').textContent = data.scanned;
                if (data.found !== undefined) document.getElementById('liveQualified').textContent = data.found;
            }

            if (data.type === 'lead_found') {
                const lead = data.lead;
                const stats = data.stats;

                document.getElementById('liveScanned').textContent = stats.scanned;
                document.getElementById('liveQualified').textContent = stats.qualified;
                document.getElementById('liveNew').textContent = stats.new;
                document.getElementById('liveExisting').textContent = stats.existing;
                document.getElementById('liveStatusText').textContent = 'Ditemukan: ' + lead.name + ' (Siap ditawari)';

                // 1. Tambah baris ke Tabel Desktop
                const tr = document.createElement('tr');
                tr.className = 'interactive-row transition-colors';
                tr.style.opacity = '0';
                tr.style.transform = 'translateY(-4px)';
                tr.style.transition = 'opacity 200ms var(--ease-out), transform 200ms var(--ease-out)';

                tr.innerHTML = `
                    <td class="px-4 py-3 font-semibold text-slate-900">
                        ${escapeHtml(lead.name)}
                        ${lead.is_new ? '<span class="ml-1 text-[10px] px-1.5 py-0.5 rounded bg-sky-50 text-sky-700 border border-sky-200 font-bold">Baru</span>' : ''}
                        ${lead.group_name ? `<span class="ml-1 text-[10px] px-1.5 py-0.5 rounded bg-purple-50 text-purple-700 border border-purple-200 font-semibold">📁 ${escapeHtml(lead.group_name)}</span>` : ''}
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-block px-2 py-0.5 rounded text-[11px] bg-slate-100 border border-slate-200 text-slate-600 font-medium">
                            ${escapeHtml(lead.category)}
                        </span>
                    </td>
                    <td class="px-4 py-3 font-mono font-semibold text-emerald-600">
                        +${escapeHtml(lead.phone_wa)}
                    </td>
                    <td class="px-4 py-3 hidden lg:table-cell">
                        <span class="inline-flex items-center gap-1 font-bold text-amber-500">
                            ★ ${(lead.rating || 0).toFixed(1)}
                            <span class="text-[11px] text-slate-400 font-mono font-normal">(${lead.user_ratings_total || 0})</span>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-slate-500 max-w-xs truncate text-[11px] hidden md:table-cell" title="${escapeHtml(lead.address)}">
                        ${escapeHtml(lead.address)}
                    </td>
                    <td class="px-4 py-3 text-right">
                        <a 
                            href="${lead.wa_url}" 
                            target="_blank" 
                            class="btn-press inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-bold shadow-sm"
                        >
                            Chat WA
                        </a>
                    </td>
                `;
                document.getElementById('leadsTableBody').prepend(tr);

                // 2. Tambah kartu ke Mobile View (< 640px)
                const card = document.createElement('div');
                card.className = 'p-3.5 space-y-2.5 bg-white';
                card.style.opacity = '0';
                card.style.transform = 'translateY(-4px)';
                card.style.transition = 'opacity 200ms var(--ease-out), transform 200ms var(--ease-out)';

                card.innerHTML = `
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <h3 class="font-bold text-slate-900 text-sm leading-snug break-words">
                                ${escapeHtml(lead.name)}
                                ${lead.is_new ? '<span class="ml-1 text-[10px] px-1.5 py-0.5 rounded bg-sky-50 text-sky-700 border border-sky-200 font-bold">Baru</span>' : ''}
                            </h3>
                            <div class="flex flex-wrap items-center gap-1.5 mt-1">
                                <span class="inline-block px-2 py-0.5 rounded-md text-[10px] bg-slate-100 border border-slate-200 text-slate-600 font-medium">
                                    ${escapeHtml(lead.category)}
                                </span>
                                ${lead.group_name ? `<span class="inline-block px-2 py-0.5 rounded-md text-[10px] bg-purple-50 text-purple-700 border border-purple-200 font-semibold truncate max-w-[140px]">📁 ${escapeHtml(lead.group_name)}</span>` : ''}
                            </div>
                        </div>
                        <span class="inline-flex items-center gap-1 font-bold text-amber-600 text-xs flex-shrink-0 bg-amber-50 px-2 py-1 rounded-lg border border-amber-200">
                            ★ ${(lead.rating || 0).toFixed(1)}
                        </span>
                    </div>

                    <div class="bg-slate-50 rounded-xl p-2.5 text-xs space-y-1.5 border border-slate-200/60">
                        <div class="flex items-center justify-between">
                            <span class="text-[11px] text-slate-500 font-medium">WhatsApp:</span>
                            <a href="${lead.wa_url}" target="_blank" class="font-mono font-semibold text-emerald-600 hover:text-emerald-700">
                                +${escapeHtml(lead.phone_wa)}
                            </a>
                        </div>
                        <div class="flex items-start justify-between text-[11px] pt-1 border-t border-slate-200/60">
                            <span class="text-slate-500 flex-shrink-0 mr-2 font-medium">Alamat:</span>
                            <span class="text-slate-700 truncate text-right flex-1" title="${escapeHtml(lead.address)}">
                                ${escapeHtml(lead.address)}
                            </span>
                        </div>
                    </div>

                    <div class="flex justify-end pt-1">
                        <a 
                            href="${lead.wa_url}" 
                            target="_blank" 
                            class="btn-press inline-flex items-center gap-1.5 px-4 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold shadow-sm"
                        >
                            <span>📱</span> Chat WhatsApp
                        </a>
                    </div>
                `;
                document.getElementById('leadsCardsBody').prepend(card);

                // Trigger animations
                requestAnimationFrame(() => {
                    tr.style.opacity = '1';
                    tr.style.transform = 'translateY(0)';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                });
            }

            if (data.type === 'done') {
                eventSource.close();
                eventSource = null;
                document.getElementById('liveStatusText').textContent = '✓ Scraping Selesai! Semua prospek tersimpan.';
                document.getElementById('stopBtn').classList.add('hidden');
                document.getElementById('submitBtn').disabled = false;
                document.getElementById('submitBtn').classList.remove('opacity-50', 'cursor-not-allowed');

                const tableBody = document.getElementById('leadsTableBody');
                if (tableBody.children.length === 0) {
                    document.getElementById('emptyResultsState').classList.remove('hidden');
                    window.toast.info('Scraping selesai, namun tidak ada bisnis tanpa website yang ditemukan.');
                } else {
                    window.toast.success('Live Scraping Selesai! Prospek berhasil disimpan.');
                }
            }

            if (data.type === 'error') {
                eventSource.close();
                eventSource = null;
                document.getElementById('errorMessage').textContent = data.message || 'Terjadi kesalahan.';
                document.getElementById('errorAlert').classList.remove('hidden');
                document.getElementById('stopBtn').classList.add('hidden');
                document.getElementById('submitBtn').disabled = false;
                document.getElementById('submitBtn').classList.remove('opacity-50', 'cursor-not-allowed');
                window.toast.error('Gagal saat scraping: ' + (data.message || 'Terjadi kesalahan'));
            }
        } catch (err) {
            console.error('Parse SSE Error:', err, e.data);
        }
    };

    eventSource.onerror = function() {
        if (eventSource) {
            eventSource.close();
            eventSource = null;
        }
        document.getElementById('errorMessage').textContent = 'Koneksi streaming terputus atau server time out.';
        document.getElementById('errorAlert').classList.remove('hidden');
        document.getElementById('stopBtn').classList.add('hidden');
        document.getElementById('submitBtn').disabled = false;
        document.getElementById('submitBtn').classList.remove('opacity-50', 'cursor-not-allowed');
        window.toast.error('Koneksi streaming terputus.');
    };
});

function escapeHtml(text) {
    if (!text) return '';
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.toString().replace(/[&<>"']/g, function(m) { return map[m]; });
}
</script>

<?php require_once __DIR__ . '/views/footer.php'; ?>
