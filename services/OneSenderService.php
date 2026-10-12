<?php
/**
 * OneSender WhatsApp Gateway Service
 * Handles communication with OneSender API (POST /api/v1/messages)
 */

require_once __DIR__ . '/../config/database.php';

class OneSenderService {
    private PDO $pdo;
    private string $apiUrl;
    private string $token;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo ?? getDb();
        $this->loadSettings();
    }

    private function loadSettings(): void {
        $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('onesender_api_url', 'onesender_token')");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $rawUrl = trim($settings['onesender_api_url'] ?? 'http://localhost:3001/api/v1');
        // Pastikan tidak ada trailing slash
        $this->apiUrl = rtrim($rawUrl, '/');
        $this->token = trim($settings['onesender_token'] ?? '');
    }

    /**
     * Normalisasi nomor telepon ke format WhatsApp Indonesia (628xxx)
     */
    public static function normalizePhone(string $phone): string {
        $clean = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($clean, '0')) {
            $clean = '62' . substr($clean, 1);
        } elseif (str_starts_with($clean, '8')) {
            $clean = '62' . $clean;
        }
        return $clean;
    }

    /**
     * Dapatkan sapaan waktu otomatis (WIB): pagi, siang, sore, malam
     */
    public static function getTimeGreeting(): string {
        date_default_timezone_set('Asia/Jakarta');
        $hour = (int)date('G'); // 0 - 23

        if ($hour >= 5 && $hour < 11) {
            return 'pagi';
        } elseif ($hour >= 11 && $hour < 15) {
            return 'siang';
        } elseif ($hour >= 15 && $hour < 18) {
            return 'sore';
        } else {
            return 'malam';
        }
    }

    /**
     * Format template pesan dengan mengganti placeholder dinamis
     */
    public static function formatMessage(string $template, array $lead): string {
        $waktu = self::getTimeGreeting();
        $placeholders = [
            '[waktu]'       => $waktu,
            '[nama_usaha]'  => $lead['name'] ?? '',
            '[kategori]'    => $lead['category'] ?? '',
            '[alamat]'      => $lead['address'] ?? '',
            '[kota]'        => $lead['city'] ?? '',
            '[rating]'      => isset($lead['rating']) ? (string)$lead['rating'] : '',
        ];

        return str_replace(array_keys($placeholders), array_values($placeholders), $template);
    }

    /**
     * Ambil template pesan berdasarkan grup dan nomor langkah (step 1 - 5).
     * Fallback ke default (group_id = NULL) jika template khusus grup belum ada.
     */
    public function getTemplateForGroup(?int $groupId, int $stepNumber): ?array {
        if ($groupId) {
            $stmt = $this->pdo->prepare("SELECT * FROM group_message_templates WHERE group_id = :gid AND step_number = :step LIMIT 1");
            $stmt->execute([':gid' => $groupId, ':step' => $stepNumber]);
            $tpl = $stmt->fetch();
            if ($tpl) {
                return $tpl;
            }
        }

        // Fallback default
        $stmt = $this->pdo->prepare("SELECT * FROM group_message_templates WHERE group_id IS NULL AND step_number = :step LIMIT 1");
        $stmt->execute([':step' => $stepNumber]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Kirim kumpulan pesan bulk ke OneSender (POST /api/v1/messages)
     * 
     * @param array $messages Array of items:
     *   [
     *     [
     *       "recipient_type" => "individual",
     *       "to" => "628xxx",
     *       "type" => "text",
     *       "text" => ["body" => "..."]
     *     ]
     *   ]
     * @return array ['success' => bool, 'status_code' => int, 'response' => mixed, 'error' => ?string]
     */
    /**
     * Dapatkan endpoint pesan OneSender yang valid dan ternormalisasi
     */
    public function getMessagesEndpoint(): string {
        $raw = rtrim($this->apiUrl, '/');
        // Jika sudah diakhiri dengan /messages
        if (preg_match('#/messages$#i', $raw)) {
            return $raw;
        }
        // Jika diakhiri dengan /api/v1
        if (preg_match('#/api/v1$#i', $raw)) {
            return $raw . '/messages';
        }
        // Jika mengandung /api/v1 di tengah
        if (str_contains($raw, '/api/v1')) {
            return $raw . '/messages';
        }
        // Jika hanya base domain misal http://localhost:3001
        return $raw . '/api/v1/messages';
    }

    /**
     * Eksekusi request POST ke OneSender dengan cURL aman (mendukung HTTPS redirect tanpa mengubah method)
     */
    private function executeRequest(string $endpoint, $payload): array {
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        if (!empty($this->token)) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POSTREDIR      => defined('CURL_REDIR_POST_ALL') ? CURL_REDIR_POST_ALL : 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $rawResponse = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($curlError) {
            return [
                'success'     => false,
                'status_code' => $httpCode,
                'error'       => 'Gagal terhubung ke OneSender (' . $endpoint . '): ' . $curlError,
                'response'    => null
            ];
        }

        $decoded = json_decode($rawResponse, true);

        // OneSender returns 200/201/202 on successful queue/send
        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success'     => true,
                'status_code' => $httpCode,
                'response'    => $decoded ?? $rawResponse,
                'error'       => null
            ];
        }

        $serverMsg = '';
        if (is_array($decoded)) {
            $serverMsg = $decoded['message'] ?? ($decoded['error'] ?? ($decoded['detail'] ?? json_encode($decoded)));
        } else {
            $serverMsg = strip_tags(substr($rawResponse, 0, 250));
        }

        $errMsg = 'HTTP ' . $httpCode . ' pada endpoint [' . ($effectiveUrl ?: $endpoint) . ']: ' . ($serverMsg ?: 'Method Not Allowed / Error Server');
        return [
            'success'     => false,
            'status_code' => $httpCode,
            'response'    => $decoded,
            'error'       => $errMsg
        ];
    }

    /**
     * Kirim kumpulan pesan bulk ke OneSender (POST /api/v1/messages)
     */
    public function sendMessages(array $messages): array {
        if (empty($messages)) {
            return ['success' => false, 'error' => 'Pesan kosong'];
        }

        $endpoint = $this->getMessagesEndpoint();
        return $this->executeRequest($endpoint, $messages);
    }

    /**
     * Kirim pesan tunggal ke 1 nomor (JSON Object sesuai standar OneSender API)
     */
    public function sendMessageSingle(string $phoneWa, string $message): array {
        $cleanPhone = self::normalizePhone($phoneWa);
        if (empty($cleanPhone)) {
            return ['success' => false, 'error' => 'Nomor WhatsApp tidak valid'];
        }

        $payload = [
            'recipient_type' => 'individual',
            'to'             => $cleanPhone,
            'type'           => 'text',
            'text'           => [
                'body' => $message
            ]
        ];

        $endpoint = $this->getMessagesEndpoint();
        return $this->executeRequest($endpoint, $payload);
    }

    /**
     * Uji koneksi ke OneSender API
     */
    public function testConnection(?string $customUrl = null, ?string $customToken = null): array {
        $url = rtrim($customUrl ?: $this->apiUrl, '/');
        $token = $customToken !== null ? $customToken : $this->token;

        $endpoint = $url;
        if (!preg_match('#/messages$#i', $endpoint)) {
            if (!str_contains($endpoint, '/api/v1')) {
                $endpoint .= '/api/v1/messages';
            } else {
                $endpoint .= '/messages';
            }
        }

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        if (!empty($token)) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['test' => true]),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POSTREDIR      => defined('CURL_REDIR_POST_ALL') ? CURL_REDIR_POST_ALL : 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($err) {
            return [
                'connected' => false,
                'message'   => 'Tidak dapat terhubung ke ' . $endpoint . ' (' . $err . ')'
            ];
        }

        if ($httpCode >= 200 && $httpCode < 500) {
            return [
                'connected' => true,
                'http_code' => $httpCode,
                'message'   => 'Server OneSender merespons di ' . ($effectiveUrl ?: $endpoint) . ' (HTTP ' . $httpCode . ')'
            ];
        }

        return [
            'connected' => false,
            'http_code' => $httpCode,
            'message'   => 'Server OneSender merespons error HTTP ' . $httpCode . ' di ' . ($effectiveUrl ?: $endpoint)
        ];
    }
}
