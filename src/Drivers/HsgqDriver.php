<?php

namespace OltRedaman\Drivers;

use Exception;
use OltRedaman\Config\EnvLoader;
use OltRedaman\Contracts\OltDriverInterface;
use OltRedaman\Support\Logger;
use OltRedaman\Support\OnuDataNormalizer;

/**
 * ============================================================================
 * HsgqDriver - Modul Backend Khusus OLT HSGQ (EPON & GPON)
 * ============================================================================
 * Mengambil data redaman optik (Rx Power, Tx Power, Status, SN/MAC, Port PON)
 * dari perangkat OLT HSGQ secara Read-Only tanpa mengubah konfigurasi OLT.
 *
 * Metode Komunikasi yang Didukung:
 * 1. HTTP Web JSON API (Utama):
 *    - Autentikasi: POST /userlogin?form=login (MD5 + Base64 + X-Token),
 *      Fallback GET /login.cgi, dan Fallback POST /api/login
 *    - Endpoint Data:
 *      * /onu_list?port_id={port}
 *      * /onu_allow_list?port_id={port}
 *      * /onu_optical_info?port_id={port}
 *      * /onu_info?port_id={port}
 *      * Fallback GPON: /gponont_mgmt?form=auth&port_id={port} & form=optical
 * 2. Telnet CLI Read-Only (Alternatif/Fallback):
 *    - Perintah: show onu-info all / show onu optical-info {port}
 */
class HsgqDriver implements OltDriverInterface
{
    private array $config;
    private ?string $token = null;
    private string $cookieFile;
    private string $authVariantUsed = 'none';

    public function __construct(array $configOverrides = [])
    {
        $this->config = EnvLoader::getHsgqConfig($configOverrides);

        $cacheDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }

        $hostHash = md5($this->config['host'] . ':' . $this->config['port'] . ':' . $this->config['username']);
        $this->cookieFile = $cacheDir . DIRECTORY_SEPARATOR . "hsgq_session_{$hostHash}.cookie";
    }

    public function getBrand(): string
    {
        return 'HSGQ';
    }

    public function getName(): string
    {
        return (string)$this->config['name'];
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Menguji koneksi ke OLT HSGQ, memeriksa port jaringan, autentikasi login,
     * serta memvalidasi ketersediaan pembacaan redaman optik.
     */
    public function testConnection(): array
    {
        $checkedAt = date('c');
        $host      = $this->config['host'];
        $method    = $this->config['method'];
        $port      = ($method === 'telnet') ? (int)$this->config['telnet_port'] : (int)$this->config['port'];

        $diagnostics = [
            'configured_enabled' => (bool)$this->config['enabled'],
            'host'               => $host,
            'port'               => $port,
            'method_configured'  => $method,
            'olt_type'           => $this->config['type'],
            'pon_ports'          => $this->config['pon_ports'],
            'tcp_reachable'      => false,
            'authenticated'      => false,
            'auth_variant'       => null,
            'sample_port_read'   => false,
            'hints'              => [],
        ];

        if (empty($host)) {
            return [
                'success'     => false,
                'brand'       => 'HSGQ',
                'olt_name'    => $this->getName(),
                'host'        => '(kosong)',
                'method_used' => $method,
                'latency_ms'  => null,
                'message'     => 'Alamat IP / Host OLT HSGQ belum diatur pada file .env (HSGQ_HOST).',
                'diagnostics' => $diagnostics,
                'checked_at'  => $checkedAt,
            ];
        }

        // Langkah 1: Uji keterjangkauan soket TCP
        $t0 = microtime(true);
        $fp = @fsockopen($host, $port, $errno, $errstr, min(5, max(2, (int)$this->config['timeout'])));
        $latencyMs = round((microtime(true) - $t0) * 1000, 2);

        if (!$fp) {
            $diagnostics['hints'] = [
                "Pastikan perangkat OLT HSGQ ({$host}) menyala dan terhubung ke jaringan/VPN yang sama dengan komputer XAMPP ini.",
                "Periksa apakah port akses HSGQ ({$port}) sudah benar di file .env (standar HTTP: 80, atau port forwarding seperti 9154).",
                "Detail error soket: [{$errno}] {$errstr}",
            ];

            Logger::warning('HSGQ', "Tes koneksi gagal (TCP unreachable) ke {$host}:{$port}", ['errno' => $errno, 'errstr' => $errstr]);

            return [
                'success'     => false,
                'brand'       => 'HSGQ',
                'olt_name'    => $this->getName(),
                'host'        => "{$host}:{$port}",
                'method_used' => $method,
                'latency_ms'  => null,
                'message'     => "OLT HSGQ ({$host}:{$port}) tidak dapat dijangkau. Port tertutup atau jaringan/VPN terputus.",
                'diagnostics' => $diagnostics,
                'checked_at'  => $checkedAt,
            ];
        }
        fclose($fp);
        $diagnostics['tcp_reachable'] = true;

        // Langkah 2: Uji autentikasi & pembacaan sampel port 1
        try {
            if ($method === 'telnet') {
                $output = $this->executeTelnetCommands(['show onu-info all']);
                $diagnostics['authenticated']    = true;
                $diagnostics['auth_variant']     = 'telnet_cli';
                $diagnostics['sample_port_read'] = strlen($output) > 10;

                return [
                    'success'     => true,
                    'brand'       => 'HSGQ',
                    'olt_name'    => $this->getName(),
                    'host'        => "{$host}:{$port}",
                    'method_used' => 'telnet',
                    'latency_ms'  => $latencyMs,
                    'message'     => "Terhubung ke OLT HSGQ ({$host}:{$port}) via Telnet CLI ({$latencyMs} ms).",
                    'diagnostics' => $diagnostics,
                    'checked_at'  => $checkedAt,
                ];
            }

            // Default HTTP Web JSON API
            $this->loginHttp();
            $diagnostics['authenticated'] = true;
            $diagnostics['auth_variant']  = $this->authVariantUsed;

            $ts = round(microtime(true) * 1000);
            $sampleList = $this->httpRequest("onu_list?port_id=1&_={$ts}");
            if (!isset($sampleList['data'])) {
                $sampleList = $this->httpRequest("onu_allow_list?port_id=1&_={$ts}");
            }
            $sampleOpt = $this->httpRequest("onu_optical_info?port_id=1&_={$ts}");

            $hasDataArray = (isset($sampleList['data']) && is_array($sampleList['data']))
                         || (isset($sampleOpt['data']) && is_array($sampleOpt['data']));
            $diagnostics['sample_port_read'] = $hasDataArray;
            $diagnostics['port_1_onu_count'] = isset($sampleList['data']) && is_array($sampleList['data']) ? count($sampleList['data']) : 0;

            return [
                'success'     => true,
                'brand'       => 'HSGQ',
                'olt_name'    => $this->getName(),
                'host'        => "{$host}:{$port}",
                'method_used' => "http ({$this->authVariantUsed})",
                'latency_ms'  => $latencyMs,
                'message'     => "Berhasil terhubung dan login ke OLT HSGQ ({$host}:{$port}) dalam {$latencyMs} ms. Endpoint optik siap digunakan.",
                'diagnostics' => $diagnostics,
                'checked_at'  => $checkedAt,
            ];
        } catch (Exception $e) {
            $diagnostics['hints'] = [
                'Port TCP terbuka, namun proses login atau pembacaan API gagal.',
                'Periksa kembali HSGQ_USERNAME dan HSGQ_PASSWORD pada file .env.',
                'Pastikan akun tersebut dapat login ke halaman Web Management OLT HSGQ.',
            ];

            Logger::error('HSGQ', 'Tes autentikasi gagal: ' . $e->getMessage(), ['host' => $host, 'port' => $port]);

            return [
                'success'     => false,
                'brand'       => 'HSGQ',
                'olt_name'    => $this->getName(),
                'host'        => "{$host}:{$port}",
                'method_used' => $method,
                'latency_ms'  => $latencyMs,
                'message'     => 'Koneksi jaringan ke OLT HSGQ terbuka, tetapi autentikasi gagal: ' . $e->getMessage(),
                'diagnostics' => $diagnostics,
                'checked_at'  => $checkedAt,
            ];
        }
    }

    /**
     * Mengambil data redaman optik seluruh ONU dari OLT HSGQ.
     */
    public function fetchOpticalData(array $filters = []): array
    {
        $startTime = microtime(true);
        $fetchedAt = date('c');
        $host      = $this->config['host'];
        $port      = (int)$this->config['port'];
        $method    = $this->config['method'];

        if (!$this->config['enabled']) {
            return [
                'success'           => false,
                'brand'             => 'HSGQ',
                'olt_name'          => $this->getName(),
                'olt_host'          => "{$host}:{$port}",
                'method_used'       => 'disabled',
                'fetched_at'        => $fetchedAt,
                'execution_time_ms' => 0.0,
                'error'             => 'Modul OLT HSGQ dinonaktifkan di konfigurasi .env (HSGQ_ENABLED=false).',
                'summary'           => OnuDataNormalizer::buildSummaryAndValidation([])['summary'],
                'validation'        => OnuDataNormalizer::buildSummaryAndValidation([])['validation'],
                'data'              => [],
            ];
        }

        try {
            $rawRecords = [];
            $methodUsed = $method;

            if ($method === 'telnet') {
                $rawRecords = $this->fetchViaTelnet($fetchedAt);
                $methodUsed = 'telnet_cli';
            } elseif ($method === 'auto') {
                try {
                    $rawRecords = $this->fetchViaHttpApi($fetchedAt);
                    $methodUsed = "http ({$this->authVariantUsed})";
                } catch (Exception $httpEx) {
                    Logger::warning('HSGQ', 'HTTP API gagal pada mode auto, mencoba fallback Telnet: ' . $httpEx->getMessage());
                    $rawRecords = $this->fetchViaTelnet($fetchedAt);
                    $methodUsed = 'telnet_cli (fallback)';
                }
            } else {
                $rawRecords = $this->fetchViaHttpApi($fetchedAt);
                $methodUsed = "http ({$this->authVariantUsed})";
            }

            // Terapkan filter jika diminta pada request
            $filteredRecords = OnuDataNormalizer::applyFilters($rawRecords, $filters);
            $stats           = OnuDataNormalizer::buildSummaryAndValidation($filteredRecords);
            $elapsedMs       = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info('HSGQ', "Berhasil mengambil " . count($filteredRecords) . " data ONU dari {$host}:{$port} ({$elapsedMs} ms)");

            return [
                'success'           => true,
                'brand'             => 'HSGQ',
                'olt_name'          => $this->getName(),
                'olt_host'          => "{$host}:{$port}",
                'olt_type'          => $this->config['type'],
                'pon_ports_scanned' => (int)$this->config['pon_ports'],
                'method_used'       => $methodUsed,
                'from_cache'        => false,
                'fetched_at'        => $fetchedAt,
                'execution_time_ms' => $elapsedMs,
                'summary'           => $stats['summary'],
                'validation'        => $stats['validation'],
                'data'              => $filteredRecords,
            ];
        } catch (Exception $e) {
            $elapsedMs = round((microtime(true) - $startTime) * 1000, 2);
            Logger::error('HSGQ', 'Gagal mengambil data redaman: ' . $e->getMessage(), ['host' => $host, 'port' => $port]);

            $emptyStats = OnuDataNormalizer::buildSummaryAndValidation([]);

            return [
                'success'           => false,
                'brand'             => 'HSGQ',
                'olt_name'          => $this->getName(),
                'olt_host'          => "{$host}:{$port}",
                'olt_type'          => $this->config['type'],
                'method_used'       => $method,
                'fetched_at'        => $fetchedAt,
                'execution_time_ms' => $elapsedMs,
                'error'             => $e->getMessage(),
                'troubleshooting'   => [
                    'Pastikan IP OLT HSGQ (' . $host . ') dan Port (' . $port . ') dapat diakses dari server XAMPP ini.',
                    'Pastikan HSGQ_USERNAME dan HSGQ_PASSWORD di file .env sudah sesuai dengan login Web OLT.',
                    'Gunakan endpoint /api/test.php?brand=hsgq untuk melakukan diagnosa koneksi secara detail.',
                ],
                'summary'           => $emptyStats['summary'],
                'validation'        => $emptyStats['validation'],
                'data'              => [],
            ];
        }
    }

    /**
     * ========================================================================
     * METODE 1: HTTP WEB JSON API BAWAAN OLT HSGQ
     * ========================================================================
     */
    private function getBaseUrl(): string
    {
        $scheme = $this->config['scheme'] === 'https' ? 'https' : 'http';
        $host   = $this->config['host'];
        $port   = (int)$this->config['port'];

        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            return "{$scheme}://{$host}/";
        }
        return "{$scheme}://{$host}:{$port}/";
    }

    /**
     * Melakukan HTTP request ke endpoint internal OLT HSGQ.
     */
    private function httpRequest(string $endpoint, ?array $postPayload = null): ?array
    {
        $baseUrl = $this->getBaseUrl();
        $url     = $baseUrl . ltrim($endpoint, '/');

        $ch = curl_init($url);
        $headers = [
            'Accept: application/json, text/javascript, */*; q=0.01',
            'X-Requested-With: XMLHttpRequest',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Origin: ' . rtrim($baseUrl, '/'),
            'Referer: ' . $baseUrl . 'index.html',
        ];

        if (!empty($this->token)) {
            $headers[] = "X-Token: {$this->token}";
        }

        if ($postPayload !== null) {
            $headers[] = 'Content-Type: application/json;charset=UTF-8';
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postPayload));
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => max(5, (int)$this->config['timeout']),
            CURLOPT_CONNECTTIMEOUT => min(8, max(3, (int)$this->config['timeout'])),
            CURLOPT_COOKIEJAR      => $this->cookieFile,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception("Gagal menghubungi HTTP API OLT HSGQ ({$this->config['host']}:{$this->config['port']}): {$err}");
        }

        $headerSize     = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headerResponse = substr((string)$response, 0, $headerSize);
        $body           = substr((string)$response, $headerSize);
        curl_close($ch);

        // Tangkap X-Token dinamis dari response header firmware HSGQ
        if (preg_match('/X-Token:\s*([^;\s\r\n]+)/i', $headerResponse, $matches)) {
            $this->token = trim($matches[1]);
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Melakukan login ke Web API OLT HSGQ dengan dukungan multi-firmware.
     */
    private function loginHttp(): bool
    {
        if (is_file($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
        $this->token = null;

        $user = $this->config['username'];
        $pass = $this->config['password'];

        // Varian 1: Standar Firmware HSGQ Web JSON API (userlogin?form=login)
        $payload = [
            'method' => 'set',
            'param'  => [
                'name'      => $user,
                'key'       => md5($user . ':' . $pass),
                'value'     => base64_encode($pass),
                'captcha_v' => '',
                'captcha_f' => '',
            ],
        ];

        $res = $this->httpRequest('userlogin?form=login', $payload);
        if (isset($res['code']) && in_array($res['code'], [1, 200], true)) {
            $this->authVariantUsed = 'userlogin_json';
            return true;
        }

        // Varian 2: Firmware HSGQ CGI Lama (login.cgi)
        $resCgi = $this->httpRequest(
            'login.cgi?username=' . rawurlencode($user) . '&password=' . rawurlencode($pass) . '&method=login'
        );
        if (isset($resCgi['code']) && in_array($resCgi['code'], [1, 200], true)) {
            $this->authVariantUsed = 'login_cgi';
            return true;
        }

        // Varian 3: Firmware HSGQ REST v3 (api/login)
        $resV3 = $this->httpRequest('api/login', ['username' => $user, 'password' => $pass]);
        if (!empty($resV3['token'])) {
            $this->token           = (string)$resV3['token'];
            $this->authVariantUsed = 'api_v3_token';
            return true;
        }

        $oltMessage = $res['message'] ?? $res['msg'] ?? 'Username atau Password ditolak oleh OLT HSGQ.';
        throw new Exception("Login ke OLT HSGQ gagal ({$oltMessage})");
    }

    /**
     * Mengambil data ONU & redaman optik per port PON melalui HTTP API HSGQ.
     */
    private function fetchViaHttpApi(string $fetchedAt): array
    {
        $this->loginHttp();

        $portCount = max(1, (int)$this->config['pon_ports']);
        $ts        = round(microtime(true) * 1000);
        $records   = [];

        // Jika filter pon_port spesifik diberikan, kita hanya perlu memindai port tersebut
        for ($port = 1; $port <= $portCount; $port++) {
            $listData  = $this->httpRequest("onu_list?port_id={$port}&_={$ts}");
            $allowData = null;
            if (!isset($listData['data']) || empty($listData['data'])) {
                $allowData = $this->httpRequest("onu_allow_list?port_id={$port}&_={$ts}");
            }

            $optData  = $this->httpRequest("onu_optical_info?port_id={$port}&_={$ts}");
            $infoData = $this->httpRequest("onu_info?port_id={$port}&_={$ts}");

            // Dukungan tambahan untuk firmware HSGQ GPON (gponont_mgmt)
            $gponAuth = null;
            $gponOpt  = null;
            if (
                (!isset($listData['data']) || empty($listData['data'])) &&
                (!isset($allowData['data']) || empty($allowData['data'])) &&
                (!isset($optData['data']) || empty($optData['data']))
            ) {
                $gponAuth = $this->httpRequest("gponont_mgmt?form=auth&port_id={$port}&_={$ts}");
                $gponOpt  = $this->httpRequest("gponont_mgmt?form=optical&port_id={$port}&_={$ts}");
            }

            // Gabungkan seluruh data berdasarkan onu_id pada port ini
            $mergedByOnuId = [];
            $sources       = [$listData, $allowData, $infoData, $optData, $gponAuth, $gponOpt];

            foreach ($sources as $src) {
                if (!isset($src['data']) || !is_array($src['data'])) {
                    continue;
                }
                foreach ($src['data'] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $oid = $row['onu_id'] ?? $row['ont_id'] ?? $row['id'] ?? null;
                    if ($oid === null || $oid === '') {
                        continue;
                    }
                    $oidKey = (string)$oid;
                    if (!isset($mergedByOnuId[$oidKey])) {
                        $mergedByOnuId[$oidKey] = [];
                    }
                    // Merge di mana field yang tidak kosong diutamakan
                    foreach ($row as $k => $v) {
                        if ($v !== '' && $v !== null) {
                            $mergedByOnuId[$oidKey][$k] = $v;
                        } elseif (!array_key_exists($k, $mergedByOnuId[$oidKey])) {
                            $mergedByOnuId[$oidKey][$k] = $v;
                        }
                    }
                }
            }

            foreach ($mergedByOnuId as $oidKey => $onu) {
                $rxRaw = $onu['rx_pwr']
                      ?? $onu['receive_power']
                      ?? $onu['rxPower']
                      ?? $onu['rx_power']
                      ?? $onu['ont_rx_pwr']
                      ?? null;

                $txRaw = $onu['tx_pwr']
                      ?? $onu['transmit_power']
                      ?? $onu['txPower']
                      ?? $onu['tx_power']
                      ?? $onu['ont_tx_pwr']
                      ?? null;

                $oltRxRaw = $onu['olt_rx_pwr']
                         ?? $onu['olt_receive_power']
                         ?? $onu['oltRxPower']
                         ?? null;

                $tempRaw = $onu['temp']
                        ?? $onu['temperature']
                        ?? $onu['work_temp']
                        ?? $onu['work_temperature']
                        ?? null;

                $tempVal = null;
                if ($tempRaw !== null && $tempRaw !== '' && $tempRaw !== '---') {
                    if (preg_match('/[-+]?\d+(?:\.\d+)?/', (string)$tempRaw, $tm)) {
                        $t = (float)$tm[0];
                        if ($t > -40 && $t < 120) {
                            $tempVal = $t;
                        }
                    }
                }

                $onuNum = is_numeric($oidKey) ? (int)$oidKey : null;
                $index  = "{$port}/{$oidKey}";

                $name = $onu['onu_name']
                     ?? $onu['ont_name']
                     ?? $onu['name']
                     ?? $onu['onu_desc']
                     ?? "ONU-{$index}";

                $sn = $onu['onu_sn']
                   ?? $onu['ont_sn']
                   ?? $onu['sn']
                   ?? $onu['macaddr']
                   ?? $onu['mac']
                   ?? 'N/A';

                $rawStatus = $onu['status']
                          ?? $onu['rstate']
                          ?? $onu['online_status']
                          ?? $onu['auth_state']
                          ?? 'Offline';

                $downReason = $onu['last_down_reason']
                           ?? $onu['last_deregister_reason']
                           ?? $onu['down_reason']
                           ?? null;

                $downTime = $onu['last_down_time']
                         ?? $onu['last_deregister_time']
                         ?? null;

                $distance = $onu['distance'] ?? $onu['onu_distance'] ?? null;

                $records[] = OnuDataNormalizer::normalizeOnuRecord([
                    'onu_id'           => $index,
                    'onu_index'        => $index,
                    'pon_port'         => (string)$port,
                    'onu_number'       => $onuNum,
                    'onu_name'         => $name,
                    'sn'               => $sn,
                    'mac_address'      => $onu['macaddr'] ?? null,
                    'status'           => $rawStatus,
                    'rx_power'         => $rxRaw,
                    'tx_power'         => $txRaw,
                    'olt_rx_power'     => $oltRxRaw,
                    'distance_m'       => $distance,
                    'temperature_c'    => $tempVal,
                    'model'            => $this->extractModel($onu),
                    'vendor'           => $onu['vendor'] ?? $onu['vendor_id'] ?? null,
                    'last_down_reason' => $downReason,
                    'last_down_time'   => $downTime,
                ], 'HSGQ', $this->getName(), $this->config['host'], $fetchedAt);
            }
        }

        return $records;
    }

    /**
     * Mengekstrak nama tipe/model ONT dari field yang bervariasi antar firmware HSGQ.
     */
    private function extractModel(array $data): ?string
    {
        $candidates = [
            'equip_id', 'equipment_id', 'EquipmentId',
            'onu_type', 'OnuType', 'ont_type',
            'extend_model', 'ExtendModel',
            'model', 'model_name', 'Model',
            'dev_type', 'device_type', 'DeviceType',
            'type', 'product_class',
        ];

        foreach ($candidates as $field) {
            if (!empty($data[$field]) && is_string($data[$field])) {
                $val = trim($data[$field]);
                if (!in_array(strtolower($val), ['n/a', '---', '--', '-', 'unknown', 'none', '0', ''], true) && !is_numeric($val)) {
                    return $val;
                }
            }
        }
        return null;
    }

    /**
     * ========================================================================
     * METODE 2: TELNET CLI READ-ONLY (FALLBACK HSGQ)
     * ========================================================================
     */
    private function fetchViaTelnet(string $fetchedAt): array
    {
        $portCount = max(1, (int)$this->config['pon_ports']);
        $commands  = ['enable', 'show onu-info all'];
        for ($p = 1; $p <= $portCount; $p++) {
            $commands[] = "show onu optical-info {$p}";
        }

        $output = $this->executeTelnetCommands($commands);
        return $this->parseTelnetOutput($output, $fetchedAt);
    }

    private function executeTelnetCommands(array $commands): string
    {
        $host    = $this->config['host'];
        $port    = (int)$this->config['telnet_port'];
        $user    = $this->config['username'];
        $pass    = $this->config['password'];
        $timeout = max(5, (int)$this->config['timeout']);

        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$fp) {
            throw new Exception("Koneksi Telnet ke OLT HSGQ ({$host}:{$port}) gagal: [{$errno}] {$errstr}");
        }

        stream_set_timeout($fp, 2);
        fwrite($fp, "\r\n");

        $output       = '';
        $buffer       = '';
        $loginSent    = false;
        $passSent     = false;
        $cmdIdx       = 0;
        $startTime    = time();

        while (!feof($fp) && (time() - $startTime) < $timeout) {
            $read   = [$fp];
            $write  = null;
            $except = null;

            if (@stream_select($read, $write, $except, 1) > 0) {
                $chunk = fread($fp, 4096);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                // Bersihkan byte kontrol Telnet IAC (0xFF)
                $clean = preg_replace('/\xFF[\xFB-\xFE].|\xFF./', '', $chunk);
                $output .= $clean;
                $buffer .= $clean;
            } else {
                if (!$loginSent) {
                    fwrite($fp, "\r\n");
                }
                continue;
            }

            if (stripos($buffer, 'Login incorrect') !== false || stripos($buffer, 'Authentication failed') !== false) {
                fclose($fp);
                throw new Exception('Autentikasi Telnet OLT HSGQ gagal: Username atau Password salah.');
            }

            if (!$loginSent && preg_match('/(login|username|user)\s*:/i', $buffer)) {
                fwrite($fp, $user . "\r\n");
                $loginSent = true;
                $buffer    = '';
                continue;
            }

            if (!$passSent && preg_match('/(password|passwd)\s*:/i', $buffer)) {
                fwrite($fp, $pass . "\r\n");
                $passSent = true;
                $buffer   = '';
                continue;
            }

            if (stripos($buffer, '--More--') !== false || stripos($buffer, '--Press Enter--') !== false) {
                fwrite($fp, ' ');
                $buffer = '';
                continue;
            }

            if ($loginSent && $passSent && preg_match('/[>#]\s*$/', trim($buffer))) {
                if ($cmdIdx < count($commands)) {
                    fwrite($fp, $commands[$cmdIdx] . "\r\n");
                    $cmdIdx++;
                    $buffer = '';
                    usleep(400000);
                } else {
                    break;
                }
            }
        }

        fclose($fp);
        return $output;
    }

    private function parseTelnetOutput(string $rawOutput, string $fetchedAt): array
    {
        $lines   = preg_split('/\r\n|\r|\n/', $rawOutput);
        $onuMap  = [];

        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                continue;
            }

            // Pola baris CLI HSGQ: 0/1:1 atau 1/1 diikuti MAC/SN, status, dsb.
            if (preg_match('/^(?:0\/)?(\d+)[:\/](\d+)\s+([0-9A-Fa-f:.-]{12,17}|\w{8,16})\s+(online|offline|up|down|working|auth\w*)\s*(.*)$/i', $trim, $m)) {
                $port   = $m[1];
                $onuNum = (int)$m[2];
                $idx    = "{$port}/{$onuNum}";
                $sn     = $m[3];
                $status = $m[4];
                $rest   = trim($m[5]);

                $rx = null;
                $tx = null;
                if (preg_match_all('/[-+]?\d+\.\d+/', $rest, $Floats) && !empty($Floats[0])) {
                    $rx = $Floats[0][0] ?? null;
                    $tx = $Floats[0][1] ?? null;
                }

                $onuMap[$idx] = array_merge($onuMap[$idx] ?? [], [
                    'onu_id'     => $idx,
                    'onu_index'  => $idx,
                    'pon_port'   => $port,
                    'onu_number' => $onuNum,
                    'onu_name'   => $onuMap[$idx]['onu_name'] ?? "ONU-{$idx}",
                    'sn'         => $sn,
                    'status'     => $status,
                    'rx_power'   => $rx ?? ($onuMap[$idx]['rx_power'] ?? null),
                    'tx_power'   => $tx ?? ($onuMap[$idx]['tx_power'] ?? null),
                ]);
            }
        }

        $records = [];
        foreach ($onuMap as $item) {
            $records[] = OnuDataNormalizer::normalizeOnuRecord(
                $item,
                'HSGQ',
                $this->getName(),
                $this->config['host'],
                $fetchedAt
            );
        }

        return $records;
    }
}

