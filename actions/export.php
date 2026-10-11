<?php
// actions/export.php
require_once __DIR__ . '/../config/database.php';

$db = getDb();

$status = trim($_GET['status'] ?? '');
$groupId = isset($_GET['group_id']) && $_GET['group_id'] !== '' ? trim($_GET['group_id']) : '';
$search = trim($_GET['q'] ?? '');

$sql = "SELECT leads.*, g.name AS group_name 
        FROM leads 
        LEFT JOIN lead_groups g ON leads.group_id = g.id 
        WHERE 1=1";
$params = [];

if (!empty($status)) {
    $sql .= " AND leads.status = :status";
    $params[':status'] = $status;
}

if ($groupId === 'none') {
    $sql .= " AND leads.group_id IS NULL";
} elseif (is_numeric($groupId) && (int)$groupId > 0) {
    $sql .= " AND leads.group_id = :group_id";
    $params[':group_id'] = (int)$groupId;
}

if (!empty($search)) {
    $sql .= " AND (leads.name LIKE :q OR leads.address LIKE :q OR leads.category LIKE :q OR leads.phone_wa LIKE :q OR leads.city LIKE :q OR g.name LIKE :q)";
    $params[':q'] = "%$search%";
}

$sql .= " ORDER BY leads.id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$leads = $stmt->fetchAll();

$filename = "leads_gmaps_" . date('Ymd_His') . ".csv";

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// UTF-8 BOM untuk Excel
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Header Kolom CSV
fputcsv($output, [
    'ID',
    'Nama Usaha',
    'Kategori',
    'Grup Prospek',
    'Kota',
    'Alamat Lengkap',
    'Nomor WhatsApp',
    'Rating',
    'Total Ulasan',
    'Status Prospek',
    'Catatan',
    'Terakhir Dihubungi',
    'Tanggal Disimpan'
]);

foreach ($leads as $row) {
    fputcsv($output, [
        $row['id'],
        $row['name'],
        $row['category'],
        $row['group_name'] ?? '-',
        $row['city'],
        $row['address'],
        $row['phone_wa'],
        $row['rating'],
        $row['user_ratings_total'],
        $row['status'],
        $row['notes'],
        $row['last_contacted_at'],
        $row['created_at']
    ]);
}

fclose($output);
exit;
