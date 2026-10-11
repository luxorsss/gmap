<?php
// index.php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/services/OneSenderService.php';

$db = getDb();

// Hitung Statistik (4 Status Utama Sesuai Request + Follow-up Ready)
$stats = [
    'total'           => (int)$db->query("SELECT COUNT(*) FROM leads")->fetchColumn(),
    'belum_dihubungi' => (int)$db->query("SELECT COUNT(*) FROM leads WHERE status = 'belum_dihubungi'")->fetchColumn(),
    'dihubungi'       => (int)$db->query("SELECT COUNT(*) FROM leads WHERE status = 'dihubungi'")->fetchColumn(),
    'deal'            => (int)$db->query("SELECT COUNT(*) FROM leads WHERE status = 'deal'")->fetchColumn(),
    'batal'           => (int)$db->query("SELECT COUNT(*) FROM leads WHERE status = 'batal'")->fetchColumn(),
    'fu_due'          => (int)$db->query("SELECT COUNT(*) FROM leads WHERE status = 'dihubungi' AND next_followup_at IS NOT NULL AND next_followup_at <= NOW() AND last_step_sent IN (3, 4)")->fetchColumn(),
];

// Filter & Pencarian
$statusFilter = trim($_GET['status'] ?? '');
$groupFilter = isset($_GET['group_id']) && $_GET['group_id'] !== '' ? trim($_GET['group_id']) : '';
$searchQuery = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;

// Ambil daftar grup dan hitung leads tanpa grup
$availableGroups = getAllGroups();
$noGroupCount = (int)$db->query("SELECT COUNT(*) FROM leads WHERE group_id IS NULL")->fetchColumn();

// Ambil data grup aktif jika sedang difilter ke grup tertentu
$activeGroupInfo = null;
$activeGroupUncontacted = 0;
if (is_numeric($groupFilter) && (int)$groupFilter > 0) {
    $stmtGrpInfo = $db->prepare("SELECT * FROM lead_groups WHERE id = :id");
    $stmtGrpInfo->execute([':id' => (int)$groupFilter]);
    $activeGroupInfo = $stmtGrpInfo->fetch();

    if ($activeGroupInfo) {
        $stmtGrpCount = $db->prepare("SELECT COUNT(*) FROM leads WHERE group_id = :id AND status = 'belum_dihubungi' AND last_step_sent = 0");
        $stmtGrpCount->execute([':id' => (int)$groupFilter]);
        $activeGroupUncontacted = (int)$stmtGrpCount->fetchColumn();
    }
}

$sql = "SELECT leads.*, g.name AS group_name, g.color AS group_color 
        FROM leads 
        LEFT JOIN lead_groups g ON leads.group_id = g.id 
        WHERE 1=1";
$countSql = "SELECT COUNT(*) 
             FROM leads 
             LEFT JOIN lead_groups g ON leads.group_id = g.id 
             WHERE 1=1";
$params = [];

if (!empty($statusFilter)) {
    $sql .= " AND leads.status = :status";
    $countSql .= " AND leads.status = :status";
    $params[':status'] = $statusFilter;
}

if ($groupFilter === 'none') {
    $sql .= " AND leads.group_id IS NULL";
    $countSql .= " AND leads.group_id IS NULL";
} elseif (is_numeric($groupFilter) && (int)$groupFilter > 0) {
    $sql .= " AND leads.group_id = :group_id";
    $countSql .= " AND leads.group_id = :group_id";
    $params[':group_id'] = (int)$groupFilter;
}

if (!empty($searchQuery)) {
    $sql .= " AND (leads.name LIKE :q OR leads.address LIKE :q OR leads.category LIKE :q OR leads.phone_wa LIKE :q OR leads.city LIKE :q OR g.name LIKE :q)";
    $countSql .= " AND (leads.name LIKE :q OR leads.address LIKE :q OR leads.category LIKE :q OR leads.phone_wa LIKE :q OR leads.city LIKE :q OR g.name LIKE :q)";
    $params[':q'] = "%$searchQuery%";
}

// Total records filtered
$stmtCount = $db->prepare($countSql);
$stmtCount->execute($params);
$totalFiltered = (int)$stmtCount->fetchColumn();
$totalPages = ceil($totalFiltered / $limit);

$sql .= " ORDER BY leads.id DESC LIMIT $limit OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$leads = $stmt->fetchAll();

$pageTitle = 'Dashboard Prospek & WhatsApp Outreach | MapLeads Pro';
$activeNav = 'dashboard';
require_once __DIR__ . '/views/header.php';
?>

<div class="space-y-5 sm:space-y-6">

    <!-- Top Stats Cards: Responsive Grid (Phone: 2 col, Tablet: 3 col, Desktop: 6 col) -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2.5 sm:gap-4">
        
        <!-- Total Leads -->
        <a href="index.php<?= !empty($groupFilter) ? '?group_id=' . urlencode($groupFilter) : '' ?>" class="bg-white p-3.5 sm:p-4 rounded-2xl border border-slate-200/80 hover:border-slate-300 shadow-surface interactive-card group flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between">
                    <span class="text-[11px] sm:text-xs text-slate-500 font-medium">Total Leads</span>
                    <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                </div>
                <span class="text-xl sm:text-2xl font-bold font-mono tracking-tight text-slate-900 mt-1.5 sm:mt-2 block num-tabular"><?= $stats['total'] ?></span>
            </div>
            <span class="text-[10px] sm:text-[11px] text-slate-500 mt-1.5 sm:mt-2 font-medium">Semua prospek</span>
        </a>

        <!-- Belum Dihubungi -->
        <a href="index.php?status=belum_dihubungi<?= !empty($groupFilter) ? '&group_id=' . urlencode($groupFilter) : '' ?>" class="bg-white p-3.5 sm:p-4 rounded-2xl border <?= $statusFilter === 'belum_dihubungi' ? 'border-amber-400 ring-2 ring-amber-100' : 'border-slate-200/80' ?> hover:border-amber-300 shadow-surface interactive-card flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between">
                    <span class="text-[11px] sm:text-xs text-amber-700 font-semibold truncate">Belum Kontak</span>
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500 flex-shrink-0"></span>
                </div>
                <span class="text-xl sm:text-2xl font-bold font-mono tracking-tight text-amber-600 mt-1.5 sm:mt-2 block num-tabular"><?= $stats['belum_dihubungi'] ?></span>
            </div>
            <span class="text-[10px] sm:text-[11px] text-slate-500 mt-1.5 sm:mt-2 font-medium">Pesan 1 Pending</span>
        </a>

        <!-- Dihubungi -->
        <a href="index.php?status=dihubungi<?= !empty($groupFilter) ? '&group_id=' . urlencode($groupFilter) : '' ?>" class="bg-white p-3.5 sm:p-4 rounded-2xl border <?= $statusFilter === 'dihubungi' ? 'border-sky-400 ring-2 ring-sky-100' : 'border-slate-200/80' ?> hover:border-sky-300 shadow-surface interactive-card flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between">
                    <span class="text-[11px] sm:text-xs text-sky-700 font-semibold">Dihubungi</span>
                    <span class="w-1.5 h-1.5 rounded-full bg-sky-500 flex-shrink-0"></span>
                </div>
                <span class="text-xl sm:text-2xl font-bold font-mono tracking-tight text-sky-600 mt-1.5 sm:mt-2 block num-tabular"><?= $stats['dihubungi'] ?></span>
            </div>
            <span class="text-[10px] sm:text-[11px] text-slate-500 mt-1.5 sm:mt-2 font-medium">Dalam interaksi</span>
        </a>

        <!-- Deal Closing -->
        <a href="index.php?status=deal<?= !empty($groupFilter) ? '&group_id=' . urlencode($groupFilter) : '' ?>" class="bg-white p-3.5 sm:p-4 rounded-2xl border <?= $statusFilter === 'deal' ? 'border-emerald-400 ring-2 ring-emerald-100' : 'border-slate-200/80' ?> hover:border-emerald-300 shadow-surface interactive-card flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between">
                    <span class="text-[11px] sm:text-xs text-emerald-700 font-semibold">Deal Closing</span>
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 flex-shrink-0"></span>
                </div>
                <span class="text-xl sm:text-2xl font-bold font-mono tracking-tight text-emerald-600 mt-1.5 sm:mt-2 block num-tabular"><?= $stats['deal'] ?></span>
            </div>
            <span class="text-[10px] sm:text-[11px] text-slate-500 mt-1.5 sm:mt-2 font-medium">Klien berhasil 🏆</span>
        </a>

        <!-- Batal -->
        <a href="index.php?status=batal<?= !empty($groupFilter) ? '&group_id=' . urlencode($groupFilter) : '' ?>" class="bg-white p-3.5 sm:p-4 rounded-2xl border <?= $statusFilter === 'batal' ? 'border-rose-400 ring-2 ring-rose-100' : 'border-slate-200/80' ?> hover:border-rose-300 shadow-surface interactive-card flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between">
                    <span class="text-[11px] sm:text-xs text-rose-700 font-semibold">Batal</span>
                    <span class="w-1.5 h-1.5 rounded-full bg-rose-500 flex-shrink-0"></span>
                </div>
                <span class="text-xl sm:text-2xl font-bold font-mono tracking-tight text-rose-600 mt-1.5 sm:mt-2 block num-tabular"><?= $stats['batal'] ?></span>
            </div>
            <span class="text-[10px] sm:text-[11px] text-slate-500 mt-1.5 sm:mt-2 font-medium">Menolak / Drop</span>
        </a>

        <!-- Follow-up Siap (Action Card) -->
        <div class="bg-gradient-to-br from-purple-50 via-white to-purple-50/40 p-3.5 sm:p-4 rounded-2xl border border-purple-200 shadow-surface flex flex-col justify-between col-span-2 sm:col-span-1">
            <div>
                <div class="flex items-center justify-between">
                    <span class="text-[11px] sm:text-xs text-purple-800 font-bold">Follow-up Siap</span>
                    <span class="relative flex h-2 w-2">
                        <?php if ($stats['fu_due'] > 0): ?>
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-purple-500 opacity-75"></span>
                        <?php endif; ?>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-purple-600"></span>
                    </span>
                </div>
                <span class="text-xl sm:text-2xl font-bold font-mono tracking-tight text-purple-700 mt-1.5 sm:mt-2 block num-tabular"><?= $stats['fu_due'] ?></span>
            </div>
            <button 
                type="button" 
                onclick="runFollowupQueue()" 
                id="btn-run-fu"
                class="mt-2 text-[11px] font-bold text-purple-700 bg-purple-100 hover:bg-purple-200 border border-purple-300 rounded-xl py-1.5 px-3 btn-press flex items-center justify-center gap-1.5 transition"
            >
                <span>⚡</span> Jalankan FU
            </button>
        </div>
    </div>

    <!-- Outreach Banner Khusus Grup (Jika sedang filter grup) -->
    <?php if ($activeGroupInfo): ?>
        <div class="bg-emerald-50/70 border border-emerald-200 rounded-2xl p-4 sm:p-5 shadow-sm flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
            <div class="space-y-1">
                <div class="flex items-center gap-2">
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-600 text-white uppercase tracking-wider">
                        Outreach Grup
                    </span>
                    <h2 class="text-base font-bold text-slate-900 flex items-center gap-1.5">
                        <span>📁</span> <?= htmlspecialchars($activeGroupInfo['name']) ?>
                    </h2>
                </div>
                <p class="text-xs text-slate-600">
                    Terdapat <strong class="text-emerald-700 font-mono font-bold"><?= $activeGroupUncontacted ?></strong> prospek dengan status <span class="text-slate-900 font-semibold">Belum Dihubungi</span> di grup ini.
                </p>
            </div>

            <div class="flex items-center gap-2 bg-white p-2 rounded-xl border border-emerald-200 w-full md:w-auto shadow-sm">
                <span class="text-xs text-slate-600 pl-1 font-medium whitespace-nowrap">Kirim:</span>
                <input 
                    type="number" 
                    id="batch-limit-input" 
                    min="1" 
                    max="<?= max(1, $activeGroupUncontacted) ?>" 
                    value="<?= min(10, max(1, $activeGroupUncontacted)) ?>" 
                    class="w-16 px-2 py-1.5 rounded-lg bg-slate-50 text-slate-900 text-xs font-mono font-bold text-center border border-slate-200 focus:border-emerald-600 focus:bg-white focus:outline-none"
                >
                <span class="text-xs text-slate-600 pr-1 font-medium whitespace-nowrap">kontak</span>
                <button 
                    type="button" 
                    onclick="sendBatchMessage1(<?= $activeGroupInfo['id'] ?>)" 
                    id="btn-batch-send"
                    <?= $activeGroupUncontacted === 0 ? 'disabled' : '' ?>
                    class="btn-press flex-1 md:flex-none inline-flex items-center justify-center gap-1.5 px-4 py-1.5 rounded-lg font-bold text-xs bg-emerald-600 hover:bg-emerald-700 text-white disabled:opacity-40 disabled:cursor-not-allowed shadow-sm transition"
                >
                    <span>🚀</span> Kirim Pesan 1 (Massal)
                </button>
            </div>
        </div>
    <?php endif; ?>

    <!-- Filter & Action Bar: Collapsible and Fluid on Mobile -->
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-surface p-3.5 sm:p-4 flex flex-col md:flex-row items-stretch md:items-center justify-between gap-3 sm:gap-4">
        
        <!-- Search & Filter Form -->
        <form method="GET" action="index.php" class="flex flex-col sm:flex-row sm:flex-wrap items-stretch sm:items-center gap-2 w-full md:w-auto flex-1">
            <div class="relative flex-1 w-full sm:w-auto sm:min-w-[200px]">
                <input 
                    type="text" 
                    name="q" 
                    value="<?= htmlspecialchars($searchQuery) ?>" 
                    placeholder="Cari nama usaha, kota, atau nomor WA..." 
                    class="w-full pl-9 pr-4 py-2 rounded-xl bg-slate-50 border border-slate-200 focus:bg-white focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 text-xs text-slate-900 placeholder-slate-400 transition outline-none"
                >
                <svg class="w-4 h-4 text-slate-400 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            </div>

            <!-- Filter Status & Grup: Side-by-side on phone for neat fit -->
            <div class="grid grid-cols-2 sm:flex sm:items-center gap-2 w-full sm:w-auto">
                <select 
                    name="status" 
                    class="w-full sm:w-auto px-2.5 sm:px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 focus:bg-white focus:border-emerald-600 text-xs text-slate-700 font-medium transition outline-none"
                >
                    <option value="">Semua Status</option>
                    <option value="belum_dihubungi" <?= $statusFilter === 'belum_dihubungi' ? 'selected' : '' ?>>🟡 Belum Kontak</option>
                    <option value="dihubungi" <?= $statusFilter === 'dihubungi' ? 'selected' : '' ?>>🔵 Dihubungi</option>
                    <option value="deal" <?= $statusFilter === 'deal' ? 'selected' : '' ?>>🏆 Deal</option>
                    <option value="batal" <?= $statusFilter === 'batal' ? 'selected' : '' ?>>❌ Batal</option>
                </select>

                <select 
                    name="group_id" 
                    class="w-full sm:w-auto px-2.5 sm:px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 focus:bg-white focus:border-emerald-600 text-xs text-slate-700 font-medium transition outline-none truncate"
                >
                    <option value="">Semua Grup</option>
                    <option value="none" <?= $groupFilter === 'none' ? 'selected' : '' ?>>-- Tanpa Grup (<?= $noGroupCount ?>) --</option>
                    <?php foreach ($availableGroups as $grp): ?>
                        <option value="<?= $grp['id'] ?>" <?= $groupFilter === (string)$grp['id'] ? 'selected' : '' ?>>
                            📁 <?= htmlspecialchars($grp['name']) ?> (<?= $grp['total_leads'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex items-center gap-2">
                <button type="submit" class="btn-press flex-1 sm:flex-none px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 border border-slate-200 text-slate-800 text-xs font-semibold transition">
                    Cari
                </button>
                <?php if (!empty($searchQuery) || !empty($statusFilter) || $groupFilter !== ''): ?>
                    <a href="index.php" class="px-3 py-2 text-xs font-semibold text-slate-500 hover:text-slate-900 transition">
                        Reset
                    </a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Right Action Buttons -->
        <div class="flex items-center gap-2 w-full md:w-auto justify-between sm:justify-end border-t md:border-t-0 pt-2.5 md:pt-0 border-slate-100">
            <button 
                type="button" 
                id="bulkDeleteBtn"
                onclick="executeBulkDelete()"
                class="hidden btn-press inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-rose-50 hover:bg-rose-100 border border-rose-200 text-rose-700 font-semibold text-xs transition"
            >
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                Hapus (<span id="selectedCount">0</span>)
            </button>
            <a 
                href="actions/export.php?status=<?= urlencode($statusFilter) ?>&group_id=<?= urlencode($groupFilter) ?>&q=<?= urlencode($searchQuery) ?>" 
                class="btn-press flex-1 sm:flex-none inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-semibold text-xs transition shadow-sm"
                title="Download data sebagai file CSV / Excel"
            >
                <svg class="w-3.5 h-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                Export CSV
            </a>
            <a 
                href="search.php" 
                class="btn-press flex-1 sm:flex-none inline-flex items-center justify-center gap-1.5 px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs transition shadow-sm"
            >
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Scrape Baru
            </a>
        </div>
    </div>

    <!-- Leads Table Section -->
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-surface overflow-hidden">
        <?php if (empty($leads)): ?>
            <div class="px-4 py-16 text-center text-slate-500">
                <div class="max-w-xs mx-auto space-y-2">
                    <div class="text-3xl opacity-80">📭</div>
                    <div class="font-bold text-slate-800 text-sm">Belum ada data prospek</div>
                    <div class="text-xs text-slate-500">Mulai scrape Google Maps sekarang untuk menemukan calon klien pembuatan website!</div>
                    <a href="search.php" class="btn-press inline-block mt-3 px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold shadow-sm">
                        + Mulai Cari di Google Maps
                    </a>
                </div>
            </div>
        <?php else: ?>
            <!-- Desktop & Tablet Table View (Hidden on mobile < 640px) -->
            <!-- Tablet optimization: hide non-essential columns (Rating, Alamat, Catatan) below lg to avoid forced scroll -->
            <div class="hidden sm:block overflow-x-auto">
                <table class="w-full text-left text-xs text-slate-700">
                    <thead class="bg-slate-50 text-[11px] uppercase tracking-wider font-semibold text-slate-500 border-b border-slate-200">
                        <tr>
                            <th class="px-3.5 py-3.5 w-10 text-center">
                                <input 
                                    type="checkbox" 
                                    id="selectAllCheckbox" 
                                    onchange="toggleSelectAll(this)"
                                    class="w-4 h-4 rounded bg-white text-emerald-600 focus:ring-0 border-slate-300 cursor-pointer" 
                                    title="Pilih Semua"
                                >
                            </th>
                            <th class="px-3.5 py-3.5">Nama Usaha &amp; Kategori</th>
                            <th class="px-3.5 py-3.5 hidden md:table-cell">Grup Prospek</th>
                            <th class="px-3.5 py-3.5 hidden lg:table-cell">Kota &amp; Alamat</th>
                            <th class="px-3.5 py-3.5">WhatsApp</th>
                            <th class="px-3.5 py-3.5 hidden xl:table-cell">Rating</th>
                            <th class="px-3.5 py-3.5">Status Prospek</th>
                            <th class="px-3.5 py-3.5 hidden lg:table-cell">Catatan</th>
                            <th class="px-3.5 py-3.5 text-right min-w-[170px]">WhatsApp Outreach</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($leads as $lead): ?>
                            <?php
                                $lastStep = (int)$lead['last_step_sent'];
                                $leadStatus = $lead['status'];
                                $directWaUrl = "https://api.whatsapp.com/send?phone=" . $lead['phone_wa'];
                            ?>
                            <tr class="interactive-row transition-colors" id="lead-row-<?= $lead['id'] ?>">
                                <!-- Checkbox Row -->
                                <td class="px-3.5 py-3.5 text-center">
                                    <input 
                                        type="checkbox" 
                                        class="lead-checkbox w-4 h-4 rounded bg-white text-emerald-600 focus:ring-0 border-slate-300 cursor-pointer" 
                                        value="<?= $lead['id'] ?>"
                                        onchange="handleLeadCheckboxChange(this)"
                                    >
                                </td>

                                <!-- Nama & Kategori -->
                                <td class="px-3.5 py-3.5">
                                    <div class="font-bold text-slate-900 text-xs leading-tight">
                                        <?= htmlspecialchars($lead['name']) ?>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-1.5 mt-1">
                                        <span class="inline-block px-2 py-0.5 rounded-md text-[10px] bg-slate-100 border border-slate-200 text-slate-600 font-medium">
                                            <?= htmlspecialchars($lead['category'] ?: 'Bisnis Lokal') ?>
                                        </span>
                                        <?php if (!empty($lead['city'])): ?>
                                            <span class="inline-block lg:hidden text-[10px] text-slate-500 font-medium">
                                                📍 <?= htmlspecialchars($lead['city']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>

                                <!-- Grup Prospek Dropdown (hidden on small tablet) -->
                                <td class="px-3.5 py-3.5 hidden md:table-cell">
                                    <select 
                                        class="px-2.5 py-1 text-[11px] font-medium rounded-lg border border-slate-200 bg-slate-50 text-slate-700 focus:bg-white focus:border-emerald-600 max-w-[150px] truncate outline-none"
                                        data-id="<?= $lead['id'] ?>"
                                        onchange="updateLeadGroup(<?= $lead['id'] ?>, this.value)"
                                    >
                                        <option value="">-- Tanpa Grup --</option>
                                        <?php foreach ($availableGroups as $grp): ?>
                                            <option value="<?= $grp['id'] ?>" <?= (int)$lead['group_id'] === (int)$grp['id'] ? 'selected' : '' ?>>
                                                📁 <?= htmlspecialchars($grp['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>

                                <!-- Kota & Alamat (hidden on tablet) -->
                                <td class="px-3.5 py-3.5 hidden lg:table-cell">
                                    <div class="font-semibold text-slate-800 text-[11px]"><?= htmlspecialchars($lead['city'] ?: 'Kota -') ?></div>
                                    <div class="text-[11px] text-slate-500 truncate max-w-[160px]" title="<?= htmlspecialchars($lead['address']) ?>">
                                        <?= htmlspecialchars($lead['address']) ?>
                                    </div>
                                </td>

                                <!-- WhatsApp -->
                                <td class="px-3.5 py-3.5 whitespace-nowrap">
                                    <a href="<?= $directWaUrl ?>" target="_blank" class="font-mono text-xs font-semibold text-emerald-600 hover:text-emerald-700 hover:underline inline-flex items-center gap-1">
                                        <span>+<?= htmlspecialchars($lead['phone_wa']) ?></span>
                                    </a>
                                </td>

                                <!-- Rating (hidden below xl) -->
                                <td class="px-3.5 py-3.5 whitespace-nowrap hidden xl:table-cell">
                                    <span class="inline-flex items-center gap-1 font-bold text-amber-500 text-xs">
                                        ★ <?= number_format($lead['rating'], 1) ?>
                                        <span class="text-[10px] text-slate-400 font-normal font-mono">(<?= $lead['user_ratings_total'] ?>)</span>
                                    </span>
                                </td>

                                <!-- Status Dropdown -->
                                <td class="px-3.5 py-3.5 whitespace-nowrap">
                                    <select 
                                        class="px-2.5 py-1 text-[11px] font-semibold rounded-lg border border-slate-200 bg-slate-50 text-slate-800 focus:bg-white focus:border-emerald-600 status-selector outline-none status-select-<?= $lead['id'] ?>"
                                        data-id="<?= $lead['id'] ?>"
                                        id="status-select-<?= $lead['id'] ?>"
                                        onchange="updateLeadStatus(<?= $lead['id'] ?>, this.value)"
                                    >
                                        <option value="belum_dihubungi" <?= $leadStatus === 'belum_dihubungi' ? 'selected' : '' ?>>🟡 Belum Kontak</option>
                                        <option value="dihubungi" <?= $leadStatus === 'dihubungi' ? 'selected' : '' ?>>🔵 Dihubungi</option>
                                        <option value="deal" <?= $leadStatus === 'deal' ? 'selected' : '' ?>>🏆 Deal</option>
                                        <option value="batal" <?= $leadStatus === 'batal' ? 'selected' : '' ?>>❌ Batal</option>
                                    </select>
                                </td>

                                <!-- Catatan (hidden below lg) -->
                                <td class="px-3.5 py-3.5 hidden lg:table-cell">
                                    <div class="flex items-center gap-1.5">
                                        <span id="note-display-<?= $lead['id'] ?>" class="note-display-<?= $lead['id'] ?> text-slate-700 max-w-[130px] truncate block text-[11px]" title="<?= htmlspecialchars($lead['notes'] ?? '') ?>">
                                            <?= !empty($lead['notes']) ? htmlspecialchars($lead['notes']) : '<span class="text-slate-400 italic">Kosong</span>' ?>
                                        </span>
                                        <button 
                                            type="button" 
                                            onclick="openEditNoteModal(<?= $lead['id'] ?>, '<?= htmlspecialchars(addslashes($lead['name'])) ?>', '<?= htmlspecialchars(addslashes($lead['notes'] ?? '')) ?>', this)" 
                                            class="btn-press min-w-[32px] min-h-[32px] inline-flex items-center justify-center text-slate-400 hover:text-slate-800 p-1 rounded-md"
                                            title="Edit Catatan"
                                            aria-label="Edit Catatan"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                        </button>
                                    </div>
                                </td>

                                <!-- Kolom WhatsApp Outreach (Multi-Step Drip) -->
                                <td class="px-3.5 py-3.5 text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1.5" id="outreach-actions-<?= $lead['id'] ?>">

                                        <?php if ($lastStep === 0): ?>
                                            <button 
                                                type="button" 
                                                onclick="sendStepMessage(<?= $lead['id'] ?>, 1)" 
                                                id="btn-step-<?= $lead['id'] ?>"
                                                class="btn-step-<?= $lead['id'] ?> btn-press inline-flex items-center gap-1 px-3 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-bold shadow-sm"
                                                title="Kirim Pesan 1 sekarang & otomatis ubah status ke Dihubungi"
                                            >
                                                <span>▶</span> Pesan 1
                                            </button>

                                        <?php elseif ($lastStep === 1): ?>
                                            <button 
                                                type="button" 
                                                onclick="sendStepMessage(<?= $lead['id'] ?>, 2)" 
                                                id="btn-step-<?= $lead['id'] ?>"
                                                class="btn-step-<?= $lead['id'] ?> btn-press inline-flex items-center gap-1 px-3 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-bold shadow-sm"
                                                title="Kirim Pesan 2 (Penawaran Solusi)"
                                            >
                                                <span>▶</span> Pesan 2
                                            </button>

                                        <?php elseif ($lastStep === 2): ?>
                                            <button 
                                                type="button" 
                                                onclick="sendStepMessage(<?= $lead['id'] ?>, 3)" 
                                                id="btn-step-<?= $lead['id'] ?>"
                                                class="btn-step-<?= $lead['id'] ?> btn-press inline-flex items-center gap-1 px-3 py-1 bg-sky-600 hover:bg-sky-700 text-white rounded-lg text-xs font-bold shadow-sm"
                                                title="Kirim Pesan 3 (Portofolio & CTA)"
                                            >
                                                <span>▶</span> Pesan 3
                                            </button>

                                        <?php elseif ($lastStep === 3): ?>
                                            <?php if ($leadStatus === 'dihubungi' && !empty($lead['next_followup_at'])): ?>
                                                <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-purple-50 border border-purple-200 text-purple-700 rounded-lg text-[11px] font-semibold" title="Jadwal otomatis Follow-up 1">
                                                    <span>⏳ FU 1:</span> <span class="font-mono"><?= date('d M H:i', strtotime($lead['next_followup_at'])) ?></span>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-[11px] font-medium text-slate-500">Pesan 3 Selesai</span>
                                            <?php endif; ?>

                                        <?php elseif ($lastStep === 4): ?>
                                            <?php if ($leadStatus === 'dihubungi' && !empty($lead['next_followup_at'])): ?>
                                                <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-indigo-50 border border-indigo-200 text-indigo-700 rounded-lg text-[11px] font-semibold" title="Jadwal otomatis Follow-up 2">
                                                    <span>⏳ FU 2:</span> <span class="font-mono"><?= date('d M H:i', strtotime($lead['next_followup_at'])) ?></span>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-[11px] font-medium text-slate-500">FU 1 Selesai</span>
                                            <?php endif; ?>

                                        <?php elseif ($lastStep >= 5): ?>
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-lg text-[11px] font-bold">
                                                ✓ Drip Selesai
                                            </span>
                                        <?php endif; ?>

                                        <!-- Direct WA Icon Button -->
                                        <a 
                                            href="<?= $directWaUrl ?>" 
                                            target="_blank" 
                                            class="btn-press min-w-[32px] min-h-[32px] inline-flex items-center justify-center text-slate-400 hover:text-emerald-600 rounded-lg hover:bg-emerald-50 transition"
                                            title="Buka Chat WhatsApp Web"
                                            aria-label="Buka Chat WhatsApp Web"
                                        >
                                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981z"/></svg>
                                        </a>

                                        <!-- Delete Button -->
                                        <button 
                                            type="button" 
                                            onclick="deleteLead(<?= $lead['id'] ?>)" 
                                            class="btn-press min-w-[32px] min-h-[32px] inline-flex items-center justify-center text-slate-400 hover:text-rose-600 rounded-lg hover:bg-rose-50 transition"
                                            title="Hapus Prospek"
                                            aria-label="Hapus Prospek"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>

                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Card View: Fully Fluid & Tailored for Phones (No Horizontal Scroll) -->
            <div class="sm:hidden divide-y divide-slate-100">
                <?php foreach ($leads as $lead): ?>
                    <?php
                        $lastStep = (int)$lead['last_step_sent'];
                        $leadStatus = $lead['status'];
                        $directWaUrl = "https://api.whatsapp.com/send?phone=" . $lead['phone_wa'];
                    ?>
                    <div class="p-3.5 space-y-3 bg-white" id="lead-card-<?= $lead['id'] ?>">
                        <!-- Card Header: Checkbox + Nama + Rating -->
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex items-start gap-2.5 min-w-0">
                                <input 
                                    type="checkbox" 
                                    class="lead-checkbox w-4 h-4 mt-0.5 rounded bg-white text-emerald-600 focus:ring-0 border-slate-300 cursor-pointer flex-shrink-0" 
                                    value="<?= $lead['id'] ?>"
                                    onchange="handleLeadCheckboxChange(this)"
                                >
                                <div class="min-w-0">
                                    <h3 class="font-bold text-slate-900 text-sm leading-snug break-words">
                                        <?= htmlspecialchars($lead['name']) ?>
                                    </h3>
                                    <div class="flex flex-wrap items-center gap-1.5 mt-1">
                                        <span class="inline-block px-2 py-0.5 rounded-md text-[10px] bg-slate-100 border border-slate-200 text-slate-600 font-medium">
                                            <?= htmlspecialchars($lead['category'] ?: 'Bisnis Lokal') ?>
                                        </span>
                                        <?php if (!empty($lead['group_name'])): ?>
                                            <span class="inline-block px-2 py-0.5 rounded-md text-[10px] bg-purple-50 border border-purple-200 text-purple-700 font-medium truncate max-w-[140px]">
                                                📁 <?= htmlspecialchars($lead['group_name']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Rating Badge -->
                            <span class="inline-flex items-center gap-1 font-bold text-amber-600 text-xs flex-shrink-0 bg-amber-50 px-2 py-1 rounded-lg border border-amber-200">
                                ★ <?= number_format($lead['rating'], 1) ?>
                                <span class="text-[10px] text-slate-500 font-normal font-mono">(<?= $lead['user_ratings_total'] ?>)</span>
                            </span>
                        </div>

                        <!-- Meta Info: Alamat & WhatsApp (Clean Stacked) -->
                        <div class="bg-slate-50 rounded-xl p-2.5 text-xs space-y-1.5 border border-slate-200/60">
                            <div class="flex items-center justify-between">
                                <span class="text-[11px] text-slate-500 font-medium">WhatsApp:</span>
                                <a href="<?= $directWaUrl ?>" target="_blank" class="font-mono font-semibold text-emerald-600 hover:text-emerald-700 inline-flex items-center gap-1">
                                    <span>+<?= htmlspecialchars($lead['phone_wa']) ?></span>
                                    <svg class="w-3.5 h-3.5 text-emerald-600" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981z"/></svg>
                                </a>
                            </div>
                            <div class="flex items-start justify-between text-[11px] pt-1 border-t border-slate-200/60">
                                <span class="text-slate-500 flex-shrink-0 mr-2 font-medium">Alamat:</span>
                                <span class="text-slate-700 truncate text-right flex-1" title="<?= htmlspecialchars($lead['address']) ?>">
                                    <?= htmlspecialchars($lead['city'] ? $lead['city'] . ' - ' : '') ?><?= htmlspecialchars($lead['address']) ?>
                                </span>
                            </div>
                        </div>

                        <!-- Status & Group Controls: Side by side on mobile -->
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-[10px] uppercase font-bold text-slate-500 mb-1">Status</label>
                                <select 
                                    class="status-select-<?= $lead['id'] ?> w-full px-2 py-1.5 text-xs font-semibold rounded-xl border border-slate-200 bg-slate-50 text-slate-800 focus:bg-white focus:border-emerald-600 outline-none"
                                    onchange="updateLeadStatus(<?= $lead['id'] ?>, this.value)"
                                >
                                    <option value="belum_dihubungi" <?= $leadStatus === 'belum_dihubungi' ? 'selected' : '' ?>>🟡 Belum Kontak</option>
                                    <option value="dihubungi" <?= $leadStatus === 'dihubungi' ? 'selected' : '' ?>>🔵 Dihubungi</option>
                                    <option value="deal" <?= $leadStatus === 'deal' ? 'selected' : '' ?>>🏆 Deal</option>
                                    <option value="batal" <?= $leadStatus === 'batal' ? 'selected' : '' ?>>❌ Batal</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] uppercase font-bold text-slate-500 mb-1">Grup</label>
                                <select 
                                    class="w-full px-2 py-1.5 text-xs font-medium rounded-xl border border-slate-200 bg-slate-50 text-slate-700 focus:bg-white focus:border-emerald-600 truncate outline-none"
                                    onchange="updateLeadGroup(<?= $lead['id'] ?>, this.value)"
                                >
                                    <option value="">-- Tanpa Grup --</option>
                                    <?php foreach ($availableGroups as $grp): ?>
                                        <option value="<?= $grp['id'] ?>" <?= (int)$lead['group_id'] === (int)$grp['id'] ? 'selected' : '' ?>>
                                            📁 <?= htmlspecialchars($grp['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Card Action Buttons -->
                        <div class="flex items-center justify-between gap-2 pt-2 border-t border-slate-100">
                            <button 
                                type="button" 
                                onclick="openEditNoteModal(<?= $lead['id'] ?>, '<?= htmlspecialchars(addslashes($lead['name'])) ?>', '<?= htmlspecialchars(addslashes($lead['notes'] ?? '')) ?>', this)" 
                                class="btn-press inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl bg-slate-50 border border-slate-200 text-slate-700 hover:text-slate-900 text-xs font-medium flex-1 max-w-[130px]"
                                title="Catatan"
                            >
                                <svg class="w-3.5 h-3.5 text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                <span class="note-display-<?= $lead['id'] ?> truncate text-[11px]"><?= !empty($lead['notes']) ? htmlspecialchars($lead['notes']) : 'Catatan' ?></span>
                            </button>

                            <div class="flex items-center gap-1.5">
                                <?php if ($lastStep === 0): ?>
                                    <button 
                                        type="button" 
                                        onclick="sendStepMessage(<?= $lead['id'] ?>, 1)" 
                                        class="btn-step-<?= $lead['id'] ?> btn-press inline-flex items-center gap-1 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold shadow-sm"
                                    >
                                        <span>▶</span> Pesan 1
                                    </button>
                                <?php elseif ($lastStep === 1): ?>
                                    <button 
                                        type="button" 
                                        onclick="sendStepMessage(<?= $lead['id'] ?>, 2)" 
                                        class="btn-step-<?= $lead['id'] ?> btn-press inline-flex items-center gap-1 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold shadow-sm"
                                    >
                                        <span>▶</span> Pesan 2
                                    </button>
                                <?php elseif ($lastStep === 2): ?>
                                    <button 
                                        type="button" 
                                        onclick="sendStepMessage(<?= $lead['id'] ?>, 3)" 
                                        class="btn-step-<?= $lead['id'] ?> btn-press inline-flex items-center gap-1 px-3 py-1.5 bg-sky-600 hover:bg-sky-700 text-white rounded-xl text-xs font-bold shadow-sm"
                                    >
                                        <span>▶</span> Pesan 3
                                    </button>
                                <?php elseif ($lastStep === 3): ?>
                                    <?php if ($leadStatus === 'dihubungi' && !empty($lead['next_followup_at'])): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-purple-50 border border-purple-200 text-purple-700 rounded-xl text-[11px] font-semibold">
                                            <span>⏳ FU 1:</span> <span class="font-mono"><?= date('d M H:i', strtotime($lead['next_followup_at'])) ?></span>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-[11px] font-medium text-slate-500 px-2">Pesan 3 OK</span>
                                    <?php endif; ?>
                                <?php elseif ($lastStep === 4): ?>
                                    <?php if ($leadStatus === 'dihubungi' && !empty($lead['next_followup_at'])): ?>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-indigo-50 border border-indigo-200 text-indigo-700 rounded-xl text-[11px] font-semibold">
                                            <span>⏳ FU 2:</span> <span class="font-mono"><?= date('d M H:i', strtotime($lead['next_followup_at'])) ?></span>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-[11px] font-medium text-slate-500 px-2">FU 1 OK</span>
                                    <?php endif; ?>
                                <?php elseif ($lastStep >= 5): ?>
                                    <span class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-xl text-[11px] font-bold">
                                        ✓ Drip Selesai
                                    </span>
                                <?php endif; ?>

                                <!-- Direct WA -->
                                <a 
                                    href="<?= $directWaUrl ?>" 
                                    target="_blank" 
                                    class="btn-press w-9 h-9 inline-flex items-center justify-center text-slate-400 hover:text-emerald-600 rounded-xl bg-slate-50 border border-slate-200 hover:bg-emerald-50 transition"
                                    title="Buka Chat WhatsApp"
                                    aria-label="Buka Chat WhatsApp"
                                >
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981z"/></svg>
                                </a>

                                <!-- Delete -->
                                <button 
                                    type="button" 
                                    onclick="deleteLead(<?= $lead['id'] ?>)" 
                                    class="btn-press w-9 h-9 inline-flex items-center justify-center text-slate-400 hover:text-rose-600 rounded-xl bg-slate-50 border border-slate-200 hover:bg-rose-50 transition"
                                    title="Hapus Prospek"
                                    aria-label="Hapus Prospek"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="p-3.5 sm:p-4 border-t border-slate-200 bg-slate-50/60 flex flex-col sm:flex-row items-center justify-between text-xs text-slate-500 gap-3">
                <span>Halaman <strong class="text-slate-800"><?= $page ?></strong> dari <strong class="text-slate-800"><?= $totalPages ?></strong> (Total <?= $totalFiltered ?> data)</span>
                <div class="flex flex-wrap gap-1.5 justify-center">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a 
                            href="index.php?page=<?= $i ?>&status=<?= urlencode($statusFilter) ?>&group_id=<?= urlencode($groupFilter) ?>&q=<?= urlencode($searchQuery) ?>" 
                            class="btn-press px-2.5 py-1 rounded-lg border text-xs font-mono <?= $i === $page ? 'bg-emerald-600 text-white border-emerald-600 font-bold shadow-sm' : 'border-slate-200 text-slate-700 bg-white hover:bg-slate-100' ?>"
                        >
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

</div>

<!-- Modal Edit Catatan (Emil Kowalski: Centered, scale(0.95) to 1.0, clean light card) -->
<div id="noteModal" onclick="if(event.target === this) closeNoteModal()" class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white border border-slate-200 rounded-2xl max-w-md w-full p-5 sm:p-6 shadow-2xl space-y-4 animate-modal-in mx-2">
        <div>
            <h3 class="text-base font-bold text-slate-900 tracking-tight" id="modalLeadName">Edit Catatan Prospek</h3>
            <p class="text-xs text-slate-500 mt-1">Tulis riwayat obrolan, respon prospek, atau status penawaran.</p>
        </div>
        <input type="hidden" id="modalLeadId">
        <textarea id="modalNoteText" rows="4" class="w-full px-3.5 py-2.5 rounded-xl bg-slate-50 border border-slate-200 focus:bg-white focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 text-xs text-slate-900 placeholder-slate-400 outline-none transition" placeholder="Tulis catatan di sini..."></textarea>
        <div class="flex justify-end gap-2 pt-2">
            <button type="button" onclick="closeNoteModal()" class="btn-press px-4 py-2 rounded-xl text-xs font-semibold text-slate-600 hover:text-slate-900 bg-slate-100 hover:bg-slate-200 border border-slate-200">Batal</button>
            <button type="button" onclick="saveNote()" class="btn-press px-4 py-2 rounded-xl text-xs font-bold bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm">Simpan Catatan</button>
        </div>
    </div>
</div>

<script>
// 1. Eksekusi Kirim Pesan Step-by-Step
function sendStepMessage(leadId, stepNumber) {
    const buttons = document.querySelectorAll('.btn-step-' + leadId);
    if (buttons.length === 0) return;

    buttons.forEach(b => {
        b.disabled = true;
        b.dataset.origHtml = b.innerHTML;
        b.innerHTML = '<span>⏳</span> Kirim...';
    });

    const formData = new URLSearchParams();
    formData.append('lead_id', leadId);
    formData.append('step_number', stepNumber);

    fetch('actions/send_step_message.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window.toast.success(`Pesan ${stepNumber} berhasil dikirim via WhatsApp!`);

            if (data.new_status) {
                document.querySelectorAll('.status-select-' + leadId).forEach(s => s.value = data.new_status);
            }

            buttons.forEach(btn => {
                if (data.next_step === 2) {
                    btn.disabled = false;
                    btn.className = `btn-step-${leadId} btn-press inline-flex items-center gap-1 px-3 py-1.5 sm:py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl sm:rounded-lg text-xs font-bold shadow-sm`;
                    btn.innerHTML = '<span>▶</span> Pesan 2';
                    btn.setAttribute('onclick', `sendStepMessage(${leadId}, 2)`);
                } else if (data.next_step === 3) {
                    btn.disabled = false;
                    btn.className = `btn-step-${leadId} btn-press inline-flex items-center gap-1 px-3 py-1.5 sm:py-1 bg-sky-600 hover:bg-sky-700 text-white rounded-xl sm:rounded-lg text-xs font-bold shadow-sm`;
                    btn.innerHTML = '<span>▶</span> Pesan 3';
                    btn.setAttribute('onclick', `sendStepMessage(${leadId}, 3)`);
                } else if (data.next_step >= 4) {
                    let fuLabel = 'Terjadwal';
                    if (data.next_followup_at) {
                        const d = new Date(data.next_followup_at.replace(/-/g, "/"));
                        fuLabel = `${d.getDate()} ${d.toLocaleString('id-ID', { month: 'short' })} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
                    }
                    btn.outerHTML = `<span class="inline-flex items-center gap-1 px-2.5 py-1.5 sm:py-1 bg-purple-50 border border-purple-200 text-purple-700 rounded-xl sm:rounded-lg text-[11px] font-semibold" title="Jadwal otomatis Follow-up 1"><span>⏳ FU 1:</span> <span class="font-mono">${fuLabel}</span></span>`;
                }
            });
        } else {
            buttons.forEach(b => {
                b.disabled = false;
                if (b.dataset.origHtml) b.innerHTML = b.dataset.origHtml;
            });
            window.toast.error('Gagal mengirim WhatsApp: ' + (data.message || 'Terjadi kesalahan'));
        }
    })
    .catch(err => {
        buttons.forEach(b => {
            b.disabled = false;
            if (b.dataset.origHtml) b.innerHTML = b.dataset.origHtml;
        });
        window.toast.error('Kesalahan jaringan: ' + err.message);
    });
}

// 2. Kirim Pesan 1 Massal untuk Grup
function sendBatchMessage1(groupId) {
    const limitInput = document.getElementById('batch-limit-input');
    const limit = limitInput ? parseInt(limitInput.value) : 10;
    const btn = document.getElementById('btn-batch-send');

    if (!confirm(`Kirim Pesan 1 ke ${limit} prospek di grup ini via OneSender sekarang?`)) {
        return;
    }

    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span>⏳</span> Mengirim...';
    }

    const formData = new URLSearchParams();
    formData.append('group_id', groupId);
    formData.append('limit', limit);

    fetch('actions/send_batch_message1.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window.toast.success(data.message || `Berhasil mengirim ke ${data.count} prospek!`);
            setTimeout(() => window.location.reload(), 1200);
        } else {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<span>🚀</span> Kirim Pesan 1 (Massal)';
            }
            window.toast.error('Gagal mengirim: ' + (data.message || 'Terjadi kesalahan'));
        }
    })
    .catch(err => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<span>🚀</span> Kirim Pesan 1 (Massal)';
        }
        window.toast.error('Kesalahan jaringan saat bulk send: ' + err.message);
    });
}

// 3. Jalankan Antrean Follow Up Sekarang
function runFollowupQueue() {
    const btn = document.getElementById('btn-run-fu');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span>⏳</span> Memproses...';
    }

    const formData = new URLSearchParams();
    formData.append('force', '1');

    fetch('actions/run_followup_queue.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<span>⚡</span> Jalankan FU';
        }
        window.toast.success(data.message || 'Proses follow-up selesai.');
        setTimeout(() => window.location.reload(), 1000);
    })
    .catch(err => {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<span>⚡</span> Jalankan FU';
        }
        window.toast.error('Kesalahan jaringan saat follow up: ' + err.message);
    });
}

// 4. Update Status Prospek (AJAX)
function updateLeadStatus(id, newStatus) {
    document.querySelectorAll('.status-select-' + id).forEach(s => s.value = newStatus);
    fetch('actions/update_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ id: id, status: newStatus })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window.toast.success('Status prospek diperbarui.');
        } else {
            window.toast.error('Gagal mengupdate status: ' + (data.error || 'Terjadi kesalahan'));
        }
    })
    .catch(err => {
        window.toast.error('Gagal berkomunikasi dengan server');
    });
}

// 5. Update Grup Prospek
function updateLeadGroup(id, newGroupId) {
    fetch('actions/update_group.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ id: id, group_id: newGroupId })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window.toast.success('Grup prospek diperbarui.');
        } else {
            window.toast.error('Gagal mengupdate grup: ' + (data.error || 'Terjadi kesalahan'));
        }
    })
    .catch(err => {
        window.toast.error('Gagal berkomunikasi dengan server');
    });
}

// 6. Hapus Prospek Tunggal
function deleteLead(id) {
    if (!confirm('Yakin ingin menghapus prospek ini dari database?')) return;
    fetch('actions/delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ id: id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const row = document.getElementById('lead-row-' + id);
            const card = document.getElementById('lead-card-' + id);
            [row, card].forEach(el => {
                if (el) {
                    el.style.transition = 'opacity 160ms var(--ease-out), transform 160ms var(--ease-out)';
                    el.style.opacity = '0';
                    el.style.transform = 'scale(0.98)';
                    setTimeout(() => el.remove(), 160);
                }
            });
            setTimeout(updateBulkUI, 180);
            window.toast.success('Prospek berhasil dihapus.');
        } else {
            window.toast.error('Gagal menghapus: ' + (data.error || 'Terjadi kesalahan'));
        }
    });
}

// 7. Modal Catatan (Keyboard Accessible with Escape key & focus return - R-32)
let modalTriggerElement = null;

function openEditNoteModal(id, name, currentNote, triggerBtn) {
    modalTriggerElement = triggerBtn || document.activeElement;
    document.getElementById('modalLeadId').value = id;
    document.getElementById('modalLeadName').textContent = 'Catatan: ' + name;
    document.getElementById('modalNoteText').value = currentNote;
    document.getElementById('noteModal').classList.remove('hidden');
    setTimeout(() => document.getElementById('modalNoteText').focus(), 50);
}

function closeNoteModal() {
    document.getElementById('noteModal').classList.add('hidden');
    if (modalTriggerElement && typeof modalTriggerElement.focus === 'function') {
        modalTriggerElement.focus();
    }
}

// Global Escape listener for accessible modal closing (R-32)
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('noteModal');
        if (modal && !modal.classList.contains('hidden')) {
            closeNoteModal();
        }
    }
});

function saveNote() {
    const id = document.getElementById('modalLeadId').value;
    const note = document.getElementById('modalNoteText').value;
    const select = document.getElementById('status-select-' + id);
    const currentStatus = select ? select.value : 'belum_dihubungi';

    fetch('actions/update_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ id: id, status: currentStatus, notes: note })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.querySelectorAll('.note-display-' + id).forEach(display => {
                display.textContent = note || 'Kosong';
                display.title = note;
            });
            closeNoteModal();
            window.toast.success('Catatan berhasil disimpan.');
        } else {
            window.toast.error('Gagal menyimpan catatan');
        }
    });
}

// 8. Bulk Selection & Synchronization (Desktop Table & Mobile Cards)
function handleLeadCheckboxChange(cb) {
    const leadId = cb.value;
    const isChecked = cb.checked;
    document.querySelectorAll(`.lead-checkbox[value="${leadId}"]`).forEach(other => {
        other.checked = isChecked;
    });
    updateBulkUI();
}

function toggleSelectAll(masterCheckbox) {
    const checkboxes = document.querySelectorAll('.lead-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = masterCheckbox.checked;
    });
    updateBulkUI();
}

function updateBulkUI() {
    const checkedIds = new Set();
    document.querySelectorAll('.lead-checkbox:checked').forEach(cb => checkedIds.add(cb.value));
    const count = checkedIds.size;
    const bulkBtn = document.getElementById('bulkDeleteBtn');
    const countSpan = document.getElementById('selectedCount');
    const master = document.getElementById('selectAllCheckbox');

    if (countSpan) countSpan.textContent = count;
    if (bulkBtn) {
        if (count > 0) {
            bulkBtn.classList.remove('hidden');
        } else {
            bulkBtn.classList.add('hidden');
        }
    }

    const allIds = new Set();
    document.querySelectorAll('.lead-checkbox').forEach(cb => allIds.add(cb.value));
    if (master && allIds.size > 0) {
        master.checked = count === allIds.size;
        master.indeterminate = count > 0 && count < allIds.size;
    }
}

function executeBulkDelete() {
    const checkedIds = Array.from(new Set(Array.from(document.querySelectorAll('.lead-checkbox:checked')).map(cb => cb.value)));
    if (checkedIds.length === 0) return;

    if (!confirm(`Yakin ingin menghapus ${checkedIds.length} prospek terpilih sekaligus dari database?`)) {
        return;
    }

    const bulkBtn = document.getElementById('bulkDeleteBtn');
    if (bulkBtn) bulkBtn.disabled = true;

    const formData = new URLSearchParams();
    checkedIds.forEach(id => formData.append('ids[]', id));

    fetch('actions/bulk_delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            checkedIds.forEach(id => {
                const row = document.getElementById('lead-row-' + id);
                const card = document.getElementById('lead-card-' + id);
                if (row) row.remove();
                if (card) card.remove();
            });
            updateBulkUI();
            window.toast.success(`Berhasil menghapus ${data.deleted_count || checkedIds.length} prospek.`);
            if (document.querySelectorAll('.lead-checkbox').length === 0) {
                setTimeout(() => window.location.reload(), 1000);
            }
        } else {
            window.toast.error('Gagal melakukan bulk delete: ' + (data.error || 'Terjadi kesalahan'));
            if (bulkBtn) bulkBtn.disabled = false;
        }
    })
    .catch(err => {
        window.toast.error('Terjadi kesalahan koneksi saat menghapus');
        if (bulkBtn) bulkBtn.disabled = false;
    });
}
</script>

<?php require_once __DIR__ . '/views/footer.php'; ?>
