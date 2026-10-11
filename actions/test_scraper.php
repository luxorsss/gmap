<?php
// actions/test_scraper.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';

try {
    $workerUrl = trim($_POST['worker_url'] ?? getSetting('scraper_worker_url', 'http://127.0.0.1:3123'));
    $workerToken = trim($_POST['token'] ?? getSetting('scraper_secret_token', ''));

    $workerUrl = rtrim($workerUrl, '/');
    if (empty($workerUrl)) {
        echo json_encode([
            'success' => false,
            'message' => 'URL Scraper Worker belum diisi.'
        ]);
        exit;
    }

    $targetUrl = $workerUrl . '/status';
    if (!empty($workerToken)) {
        $targetUrl .= '?token=' . urlencode($workerToken);
    }

    $ch = curl_init($targetUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    if (!empty($workerToken)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-Scraper-Token: ' . $workerToken
        ]);
    }
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200 && $resp) {
        $data = json_decode($resp, true);
        $authNote = !empty($data['auth_required']) ? ' (Token aktif & valid)' : ' (Tanpa token)';
        echo json_encode([
            'success'   => true,
            'message'   => 'Scraper Worker di laptop/server terhubung dengan sukses!' . $authNote,
            'http_code' => 200,
            'data'      => $data
        ]);
    } elseif ($httpCode === 401) {
        echo json_encode([
            'success'   => false,
            'message'   => 'Terhubung ke server tetapi Token Scraper ditolak (401 Unauthorized). Silakan periksa token Anda.',
            'http_code' => 401
        ]);
    } else {
        echo json_encode([
            'success'   => false,
            'message'   => 'Gagal terhubung ke worker (' . ($curlErr ?: "HTTP $httpCode") . '). Pastikan scraper worker dan tunnel di laptop sudah aktif.',
            'http_code' => $httpCode
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
