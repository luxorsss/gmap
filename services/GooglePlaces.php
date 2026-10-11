<?php
// services/GooglePlaces.php

class GooglePlacesService {
    private string $apiKey;

    public function __construct(string $apiKey) {
        $this->apiKey = trim($apiKey);
    }

    /**
     * Cari bisnis di Google Maps menggunakan Places API (New) Text Search
     * Endpoint: https://places.googleapis.com/v1/places:searchText
     */
    public function searchPlaces(string $query, int $maxResults = 20, ?string $pageToken = null): array {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'error'   => 'Google API Key belum disetting. Silakan isi di menu Pengaturan.'
            ];
        }

        $url = 'https://places.googleapis.com/v1/places:searchText';

        $payload = [
            'textQuery' => $query,
            'pageSize'  => min(20, max(1, $maxResults)),
            'languageCode' => 'id'
        ];

        if ($pageToken) {
            $payload['pageToken'] = $pageToken;
        }

        $headers = [
            'Content-Type: application/json',
            'X-Goog-Api-Key: ' . $this->apiKey,
            'X-Goog-FieldMask: places.id,places.displayName,places.formattedAddress,places.nationalPhoneNumber,places.internationalPhoneNumber,places.websiteUri,places.rating,places.userRatingCount,places.primaryTypeDisplayName,nextPageToken'
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return [
                'success' => false,
                'error'   => 'cURL Error: ' . $curlError
            ];
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200) {
            $errorMsg = $data['error']['message'] ?? "Google API mengembalikan HTTP status $httpCode";
            return [
                'success' => false,
                'error'   => $errorMsg
            ];
        }

        return [
            'success'       => true,
            'places'        => $data['places'] ?? [],
            'nextPageToken' => $data['nextPageToken'] ?? null
        ];
    }

    /**
     * Normalisasi nomor telepon ke format WhatsApp Indonesia (628xxxxxxxx)
     * Mengembalikan null jika bukan nomor seluler Indonesia (misal: telepon kabel 021/031)
     */
    public static function normalizeWhatsAppNumber(?string $phone): ?string {
        if (!$phone) {
            return null;
        }

        // Hapus semua karakter non-angka kecuali tanda plus di awal
        $clean = preg_replace('/[^0-9+]/', '', trim($phone));

        // Format internasional +62...
        if (str_starts_with($clean, '+62')) {
            $clean = substr($clean, 1); // jadi 62...
        } elseif (str_starts_with($clean, '08')) {
            $clean = '628' . substr($clean, 2);
        } elseif (str_starts_with($clean, '628')) {
            // Sudah benar
        } elseif (str_starts_with($clean, '+')) {
            // Nomor luar negeri
            $clean = substr($clean, 1);
        } else {
            // Nomor tidak dikenal atau nomor kabel lokal tanpa kode area
            return null;
        }

        // Validasi nomor seluler Indonesia: berawalan 628 dan panjang 10-15 digit
        if (preg_match('/^628[0-9]{8,12}$/', $clean)) {
            return $clean;
        }

        return null;
    }

    /**
     * Filter data: hanya yang TIDAK PUNYA WEBSITE dan PUNYA NOMOR WHATSAPP
     */
    public static function filterAndFormatLeads(array $rawPlaces, bool $allowSocialLinksAsNoWebsite = true): array {
        $qualified = [];

        foreach ($rawPlaces as $place) {
            $website = trim($place['websiteUri'] ?? '');

            // Cek apakah punya website resmi
            if (!empty($website)) {
                if ($allowSocialLinksAsNoWebsite) {
                    // Cek jika website-nya cuma medsos / link bio, anggap belum punya website resmi
                    $host = parse_url($website, PHP_URL_HOST) ?? '';
                    $socialHosts = ['instagram.com', 'facebook.com', 'linktr.ee', 'wa.me', 'api.whatsapp.com', 'tiktok.com'];
                    $isSocial = false;
                    foreach ($socialHosts as $sh) {
                        if (str_contains(strtolower($host), $sh)) {
                            $isSocial = true;
                            break;
                        }
                    }

                    if (!$isSocial) {
                        // Memiliki website resmi -> Lewati
                        continue;
                    }
                } else {
                    // Punya website apa pun -> Lewati
                    continue;
                }
            }

            // Ambil nomor telepon (cek international lalu national)
            $phoneRaw = $place['internationalPhoneNumber'] ?? $place['nationalPhoneNumber'] ?? null;
            $phoneWa = self::normalizeWhatsAppNumber($phoneRaw);

            // Jika tidak ada nomor seluler / WA -> Lewati
            if (!$phoneWa) {
                continue;
            }

            $name = $place['displayName']['text'] ?? 'Tanpa Nama';
            $category = $place['primaryTypeDisplayName']['text'] ?? 'Bisnis Lokal';
            $address = $place['formattedAddress'] ?? '';

            // Ekstrak perkiraan kota dari alamat
            $city = '';
            if (preg_match('/Kota [a-zA-Z\s]+|Kabupaten [a-zA-Z\s]+|Jakarta [a-zA-Z\s]+/i', $address, $matches)) {
                $city = trim($matches[0]);
            }

            $qualified[] = [
                'place_id'           => $place['id'],
                'name'               => $name,
                'category'           => $category,
                'address'            => $address,
                'city'               => $city,
                'phone_raw'          => $phoneRaw,
                'phone_wa'           => $phoneWa,
                'rating'             => (float)($place['rating'] ?? 0.0),
                'user_ratings_total' => (int)($place['userRatingCount'] ?? 0),
            ];
        }

        return $qualified;
    }
}
