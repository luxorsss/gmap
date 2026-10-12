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

// Jika request adalah AJAX untuk Live Auto-Filter / Pagination
if (!empty($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    ob_start();
    require __DIR__ . '/views/leads_table_partial.php';
    $tableHtml = ob_get_clean();
    echo json_encode([
        'success'        => true,
        'html'           => $tableHtml,
        'total_filtered' => $totalFiltered,
        'page'           => $page,
        'total_pages'    => $totalPages,
        'export_url'     => "actions/export.php?status=" . urlencode($statusFilter) . "&group_id=" . urlencode($groupFilter) . "&q=" . urlencode($searchQuery)
    ]);
    exit;
}

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
        
        <!-- Search & Filter Form (Live AJAX Auto Filter) -->
        <form id="filterForm" onsubmit="event.preventDefault(); return false;" class="flex flex-col sm:flex-row sm:flex-wrap items-stretch sm:items-center gap-2 w-full md:w-auto flex-1">
            <div class="relative flex-1 w-full sm:w-auto sm:min-w-[220px]">
                <input 
                    type="text" 
                    id="searchInput"
                    name="q" 
                    value="<?= htmlspecialchars($searchQuery) ?>" 
                    placeholder="Cari nama usaha, kota, atau nomor WA..." 
                    class="w-full pl-9 pr-9 py-2 rounded-xl bg-slate-50 border border-slate-200 focus:bg-white focus:border-emerald-600 focus:ring-2 focus:ring-emerald-100 text-xs text-slate-900 placeholder-slate-400 transition outline-none"
                    autocomplete="off"
                >
                <!-- Normal Search Icon -->
                <svg id="searchIcon" class="w-4 h-4 text-slate-400 absolute left-3 top-2.5 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>

                <!-- Subtle Search Spinner (Loading) -->
                <svg id="searchSpinner" class="w-4 h-4 text-emerald-600 absolute left-3 top-2.5 animate-spin hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>

                <!-- Clear Search Button (X) -->
                <button 
                    type="button" 
                    id="clearSearchBtn" 
                    onclick="clearSearchInput()"
                    class="<?= empty($searchQuery) ? 'hidden' : '' ?> absolute right-2.5 top-2 p-0.5 rounded-md text-slate-400 hover:text-slate-600 hover:bg-slate-200/60 transition"
                    title="Hapus pencarian"
                >
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <!-- Filter Status & Grup -->
            <div class="grid grid-cols-2 sm:flex sm:items-center gap-2 w-full sm:w-auto">
                <select 
                    id="statusSelect"
                    name="status" 
                    class="w-full sm:w-auto px-2.5 sm:px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 focus:bg-white focus:border-emerald-600 text-xs text-slate-700 font-medium transition outline-none cursor-pointer"
                >
                    <option value="">Semua Status</option>
                    <option value="belum_dihubungi" <?= $statusFilter === 'belum_dihubungi' ? 'selected' : '' ?>>🟡 Belum Kontak</option>
                    <option value="dihubungi" <?= $statusFilter === 'dihubungi' ? 'selected' : '' ?>>🔵 Dihubungi</option>
                    <option value="deal" <?= $statusFilter === 'deal' ? 'selected' : '' ?>>🏆 Deal</option>
                    <option value="batal" <?= $statusFilter === 'batal' ? 'selected' : '' ?>>❌ Batal</option>
                </select>

                <select 
                    id="groupSelect"
                    name="group_id" 
                    class="w-full sm:w-auto px-2.5 sm:px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 focus:bg-white focus:border-emerald-600 text-xs text-slate-700 font-medium transition outline-none truncate cursor-pointer"
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

            <!-- Reset Filter Button -->
            <button 
                type="button" 
                id="resetFilterBtn" 
                onclick="resetAllFilters()"
                class="<?= (!empty($searchQuery) || !empty($statusFilter) || $groupFilter !== '') ? '' : 'hidden' ?> px-3 py-2 text-xs font-semibold text-slate-500 hover:text-slate-900 transition inline-flex items-center gap-1"
            >
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                Reset
            </button>
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
                id="exportCsvBtn"
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
    <div id="leadsTableContainer" class="transition-opacity duration-200">
        <?php require __DIR__ . '/views/leads_table_partial.php'; ?>
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

// 9. Live Auto-Filter & Pagination (AJAX without page reload)
let filterDebounceTimer = null;
let filterAbortController = null;

function fetchFilteredLeads(page = 1, updateUrl = true) {
    const searchInput = document.getElementById('searchInput');
    const statusSelect = document.getElementById('statusSelect');
    const groupSelect = document.getElementById('groupSelect');
    const leadsContainer = document.getElementById('leadsTableContainer');
    const searchIcon = document.getElementById('searchIcon');
    const searchSpinner = document.getElementById('searchSpinner');
    const clearBtn = document.getElementById('clearSearchBtn');
    const resetBtn = document.getElementById('resetFilterBtn');
    const exportBtn = document.getElementById('exportCsvBtn');

    const q = searchInput ? searchInput.value.trim() : '';
    const status = statusSelect ? statusSelect.value : '';
    const groupId = groupSelect ? groupSelect.value : '';

    // Toggle clear search button
    if (clearBtn) {
        if (q !== '') {
            clearBtn.classList.remove('hidden');
        } else {
            clearBtn.classList.add('hidden');
        }
    }

    // Toggle reset filter button
    if (resetBtn) {
        if (q !== '' || status !== '' || groupId !== '') {
            resetBtn.classList.remove('hidden');
        } else {
            resetBtn.classList.add('hidden');
        }
    }

    // Update Export CSV URL
    if (exportBtn) {
        const exportParams = new URLSearchParams();
        if (status) exportParams.set('status', status);
        if (groupId) exportParams.set('group_id', groupId);
        if (q) exportParams.set('q', q);
        exportBtn.href = 'actions/export.php?' + exportParams.toString();
    }

    // Sync browser URL
    if (updateUrl) {
        const urlParams = new URLSearchParams();
        if (page > 1) urlParams.set('page', page);
        if (status) urlParams.set('status', status);
        if (groupId) urlParams.set('group_id', groupId);
        if (q) urlParams.set('q', q);
        const newUrl = 'index.php' + (urlParams.toString() ? '?' + urlParams.toString() : '');
        window.history.replaceState({ page, q, status, group_id: groupId }, '', newUrl);
    }

    // Loading State
    if (searchIcon) searchIcon.classList.add('hidden');
    if (searchSpinner) searchSpinner.classList.remove('hidden');
    if (leadsContainer) {
        leadsContainer.classList.add('opacity-50', 'pointer-events-none', 'transition-opacity', 'duration-200');
    }

    // Cancel previous ongoing fetch to prevent race conditions
    if (filterAbortController) {
        filterAbortController.abort();
    }
    filterAbortController = new AbortController();

    const fetchParams = new URLSearchParams({
        ajax: '1',
        page: page,
        q: q,
        status: status,
        group_id: groupId
    });

    fetch('index.php?' + fetchParams.toString(), {
        signal: filterAbortController.signal
    })
    .then(res => {
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
    })
    .then(data => {
        if (data.success && leadsContainer) {
            leadsContainer.innerHTML = data.html;
            updateBulkUI();
        }
    })
    .catch(err => {
        if (err.name !== 'AbortError') {
            console.error('Filter fetch error:', err);
            if (window.toast && typeof window.toast.error === 'function') {
                window.toast.error('Gagal memuat data prospek');
            }
        }
    })
    .finally(() => {
        if (searchSpinner) searchSpinner.classList.add('hidden');
        if (searchIcon) searchIcon.classList.remove('hidden');
        if (leadsContainer) {
            leadsContainer.classList.remove('opacity-50', 'pointer-events-none');
        }
    });
}

function clearSearchInput() {
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.value = '';
        searchInput.focus();
    }
    fetchFilteredLeads(1);
}

function resetAllFilters() {
    const searchInput = document.getElementById('searchInput');
    const statusSelect = document.getElementById('statusSelect');
    const groupSelect = document.getElementById('groupSelect');

    if (searchInput) searchInput.value = '';
    if (statusSelect) statusSelect.value = '';
    if (groupSelect) groupSelect.value = '';

    fetchFilteredLeads(1);
}

// Event Listeners setup for Auto-Filter
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const statusSelect = document.getElementById('statusSelect');
    const groupSelect = document.getElementById('groupSelect');
    const leadsContainer = document.getElementById('leadsTableContainer');

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(filterDebounceTimer);
            filterDebounceTimer = setTimeout(() => {
                fetchFilteredLeads(1);
            }, 350);
        });
    }

    if (statusSelect) {
        statusSelect.addEventListener('change', function() {
            fetchFilteredLeads(1);
        });
    }

    if (groupSelect) {
        groupSelect.addEventListener('change', function() {
            fetchFilteredLeads(1);
        });
    }

    // Delegated click for Pagination links
    if (leadsContainer) {
        leadsContainer.addEventListener('click', function(e) {
            const paginationLink = e.target.closest('.pagination-link');
            if (paginationLink) {
                e.preventDefault();
                const targetPage = paginationLink.getAttribute('data-page') || 1;
                fetchFilteredLeads(targetPage);
                leadsContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    }

    // Handle browser Back / Forward buttons
    window.addEventListener('popstate', function(e) {
        const urlParams = new URLSearchParams(window.location.search);
        if (searchInput) searchInput.value = urlParams.get('q') || '';
        if (statusSelect) statusSelect.value = urlParams.get('status') || '';
        if (groupSelect) groupSelect.value = urlParams.get('group_id') || '';
        const page = parseInt(urlParams.get('page')) || 1;
        fetchFilteredLeads(page, false);
    });
});
</script>

<?php require_once __DIR__ . '/views/footer.php'; ?>
