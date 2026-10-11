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
    public function sendMessages(array $messages): array {
        if (empty($messages)) {
            return ['success' => false, 'error' => 'Pesan kosong'];
        }

        $endpoint = $this->apiUrl . '/messages';
        $jsonPayload = json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $rawResponse = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError) {
            return [
                'success'     => false,
                'status_code' => $httpCode,
                'error'       => 'Gagal terhubung ke OneSender: ' . $curlError,
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

        $errMsg = 'HTTP ' . $httpCode . ': ' . ($decoded['message'] ?? $rawResponse);
        return [
            'success'     => false,
            'status_code' => $httpCode,
            'response'    => $decoded,
            'error'       => $errMsg
        ];
    }

    /**
     * Kirim pesan tunggal ke 1 nomor
     */
    public function sendMessageSingle(string $phoneWa, string $message): array {
        $cleanPhone = self::normalizePhone($phoneWa);
        if (empty($cleanPhone)) {
            return ['success' => false, 'error' => 'Nomor WhatsApp tidak valid'];
        }

        $payload = [
            [
                'recipient_type' => 'individual',
                'to'             => $cleanPhone,
                'type'           => 'text',
                'text'           => [
                    'body' => $message
                ]
            ]
        ];

        return $this->sendMessages($payload);
    }

    /**
     * Uji koneksi ke OneSender API
     */
    public function testConnection(?string $customUrl = null, ?string $customToken = null): array {
        $url = rtrim($customUrl ?: $this->apiUrl, '/');
        $token = $customToken !== null ? $customToken : $this->token;

        // Kita coba ping endpoint URL base atau messages
        $endpoint = $url . '/messages';

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        if (!empty($token)) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        // Test request dengan array kosong atau dummy
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'OPTIONS', // atau GET jika didukung
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            return [
                'connected' => false,
                'message'   => 'Tidak dapat terhubung ke ' . $url . ' (' . $err . ')'
            ];
        }

        return [
            'connected' => true,
            'http_code' => $httpCode,
            'message'   => 'Server OneSender merespons (HTTP ' . $httpCode . ')'
        ];
    }
}
