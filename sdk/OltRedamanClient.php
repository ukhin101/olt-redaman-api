<?php

namespace OltRedaman\Sdk;

use OltRedaman\OltRedamanManager;

/**
 * ============================================================================
 * OltRedamanClient - Modul SDK PHP Siap Pakai untuk Monitoring Map FTTH
 * ============================================================================
 * Memudahkan integrasi pengambilan data redaman OLT HSGQ & HIOSO ke dalam
 * proyek PHP lain (misal: monitoring-map) tanpa harus menulis ulang driver OLT.
 *
 * Cara Penggunaan (Mode Langsung / Direct Include di Server yang Sama):
 * ```php
 * require_once 'C:/xampp/htdocs/olt-redaman-api/sdk/OltRedamanClient.php';
 * $oltClient = new \OltRedaman\Sdk\OltRedamanClient();
 *
 * // Ambil semua data redaman (HSGQ + HIOSO)
 * $all = $oltClient->getAll();
 *
 * // Cari redaman 1 pelanggan berdasarkan SN/MAC atau Nama
 * $onu = $oltClient->findOnu('00:11:41:17:D6:48');
 * ```
 */
class OltRedamanClient
{
    private ?string $remoteApiBaseUrl;
    private ?string $apiKey;
    private ?OltRedamanManager $localManager = null;

    /**
     * @param string|null $remoteApiBaseUrl Isi URL (misal "http://localhost/olt-redaman-api/api")
     *                                      jika ingin memanggil via HTTP REST API.
     *                                      Biarkan null untuk memanggil langsung class internal (lebih cepat).
     * @param string|null $apiKey           API Key opsional jika diatur pada .env
     */
    public function __construct(?string $remoteApiBaseUrl = null, ?string $apiKey = null)
    {
        $this->remoteApiBaseUrl = $remoteApiBaseUrl !== null ? rtrim($remoteApiBaseUrl, '/') : null;
        $this->apiKey           = $apiKey;

        if ($this->remoteApiBaseUrl === null) {
            require_once dirname(__DIR__) . '/autoload.php';
            $this->localManager = new OltRedamanManager();
        }
    }

    /**
     * Mengambil data redaman dari OLT HSGQ.
     */
    public function getHsgq(array $filters = [], bool $forceRefresh = false): array
    {
        if ($this->localManager !== null) {
            return $this->localManager->getHsgqData($filters, $forceRefresh);
        }
        if ($forceRefresh) {
            $filters['refresh'] = 1;
        }
        return $this->httpGet('hsgq.php', $filters);
    }

    /**
     * Mengambil data redaman dari OLT HIOSO.
     */
    public function getHioso(array $filters = [], bool $forceRefresh = false): array
    {
        if ($this->localManager !== null) {
            return $this->localManager->getHiosoData($filters, $forceRefresh);
        }
        if ($forceRefresh) {
            $filters['refresh'] = 1;
        }
        return $this->httpGet('hioso.php', $filters);
    }

    /**
     * Mengambil data redaman gabungan dari kedua OLT (HSGQ + HIOSO).
     */
    public function getAll(array $filters = [], bool $forceRefresh = false): array
    {
        if ($this->localManager !== null) {
            return $this->localManager->getCombinedData($filters, $forceRefresh);
        }
        if ($forceRefresh) {
            $filters['refresh'] = 1;
        }
        return $this->httpGet('all.php', $filters);
    }

    /**
     * Mencari data redaman 1 ONU berdasarkan SN, MAC Address, atau Nama Pelanggan.
     */
    public function findOnu(string $identifier, string $brand = 'all', bool $forceRefresh = false): ?array
    {
        if ($this->localManager !== null) {
            return $this->localManager->findOnu($identifier, $brand, $forceRefresh);
        }

        $endpoint = 'all.php';
        if (strtolower($brand) === 'hsgq') {
            $endpoint = 'hsgq.php';
        } elseif (strtolower($brand) === 'hioso') {
            $endpoint = 'hioso.php';
        }

        $res = $this->httpGet($endpoint, [
            'onu'     => $identifier,
            'refresh' => $forceRefresh ? 1 : 0,
        ]);

        return (!empty($res['success']) && isset($res['data'])) ? $res['data'] : null;
    }

    /**
     * Menguji koneksi ke OLT HSGQ, HIOSO, atau keduanya.
     */
    public function testConnection(string $brand = 'all', bool $validateReading = true): array
    {
        if ($this->localManager !== null) {
            return $this->localManager->testAndValidate($brand, $validateReading);
        }
        return $this->httpGet('test.php', [
            'brand'    => $brand,
            'validate' => $validateReading ? 1 : 0,
        ]);
    }

    private function httpGet(string $endpoint, array $query = []): array
    {
        $url = $this->remoteApiBaseUrl . '/' . ltrim($endpoint, '/');
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if (!empty($this->apiKey)) {
            $headers[] = 'X-API-Key: ' . $this->apiKey;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $body = curl_exec($ch);
        if (curl_errno($ch)) {
            $err = curl_error($ch);
            curl_close($ch);
            return [
                'success' => false,
                'error'   => 'Gagal menghubungi OLT Redaman API: ' . $err,
                'data'    => [],
            ];
        }
        curl_close($ch);

        $decoded = json_decode((string)$body, true);
        return is_array($decoded) ? $decoded : [
            'success' => false,
            'error'   => 'Respons JSON dari OLT Redaman API tidak valid.',
            'data'    => [],
        ];
    }
}

