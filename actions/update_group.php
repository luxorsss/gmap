<?php
// actions/update_group.php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$groupId = isset($_POST['group_id']) && $_POST['group_id'] !== '' ? (int)$_POST['group_id'] : null;
$newGroupName = trim($_POST['new_group_name'] ?? '');

if ($id <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID prospek tidak valid']);
    exit;
}

$db = getDb();

if (!empty($newGroupName)) {
    $groupId = getOrCreateGroup($newGroupName);
} elseif ($groupId !== null && $groupId <= 0) {
    $groupId = null;
}

try {
    $stmt = $db->prepare("UPDATE leads SET group_id = :group_id WHERE id = :id");
    $stmt->execute([':group_id' => $groupId, ':id' => $id]);

    $groupInfo = null;
    if ($groupId) {
        $gStmt = $db->prepare("SELECT id, name, color FROM lead_groups WHERE id = ?");
        $gStmt->execute([$groupId]);
        $groupInfo = $gStmt->fetch();
    }

    echo json_encode([
        'success'    => true,
        'group_id'   => $groupId,
        'group_info' => $groupInfo
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
