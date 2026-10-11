<?php
// actions/bulk_delete.php
if (function_exists('opcache_reset')) {
    @opcache_reset();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Metode request tidak valid']);
    exit;
}

$rawIds = $_POST['ids'] ?? [];
if (!is_array($rawIds) && !empty($rawIds)) {
    $rawIds = explode(',', (string)$rawIds);
}

$ids = array_filter(array_map('intval', (array)$rawIds), function($id) {
    return $id > 0;
});

if (empty($ids)) {
    echo json_encode(['success' => false, 'error' => 'Tidak ada prospek yang dipilih untuk dihapus']);
    exit;
}

try {
    $db = getDb();
    $inQuery = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("DELETE FROM leads WHERE id IN ($inQuery)");
    $stmt->execute(array_values($ids));
    $deletedCount = $stmt->rowCount();

    echo json_encode([
        'success' => true,
        'deleted_count' => $deletedCount,
        'message' => "Berhasil menghapus {$deletedCount} prospek"
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
