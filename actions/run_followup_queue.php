<?php
// actions/run_followup_queue.php
header('Content-Type: application/json');
define('GMAPS_FOLLOWUP_CALLED_INTERNALLY', true);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../cron/followup_scheduler.php';

try {
    $force = isset($_POST['force']) && $_POST['force'] == '1';
    $result = runFollowupProcess($force, false);

    echo json_encode($result);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
