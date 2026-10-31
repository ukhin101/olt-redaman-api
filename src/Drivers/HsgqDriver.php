<?php

namespace OltRedaman\Drivers;

use Exception;
use OltRedaman\Config\EnvLoader;
use OltRedaman\Contracts\OltDriverInterface;
use OltRedaman\Support\Logger;
use OltRedaman\Support\NetworkProbe;
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
    private int $lastHttpCode = 0;

    /** @var array|null Informasi perangkat dari endpoint board_info (model, firmware, jumlah PON) */
    private ?array $boardInfo = null;

    /** @var array<string, bool> Endpoint yang terbukti tidak didukung firmware (HTTP 404) */
    private array $unsupportedEndpoints = [];

    /** @var array|null Detail kegagalan login terakhir (tanpa password) */
    private ?array $lastAuthError = null;

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
     * Menguji koneksi ke OLT HSGQ secara bertahap (read-only):
     *  1. Keterjangkauan IP (ICMP ping)
     *  2. Akses port (TCP HTTP / Telnet)
     *  3. Autentikasi (login Web JSON API HSGQ)
     *  4. Pengambilan data ONU/ONT + validasi Rx Power (opsional, dilakukan di Manager)
     * Password tidak pernah ditampilkan dalam hasil diagnostik.
     */
    public function testConnection(): array
    {
        $checkedAt = date('c');
        $host      = $this->config['host'];
        $method    = $this->config['method'];
        $port      = ($method === 'telnet') ? (int)$this->config['telnet_port'] : (int)$this->config['port'];
        $timeout   = min(5, max(2, (int)$this->config['timeout']));

        $steps = [
            'ip_reachability' => ['status' => 'skipped'],
            'port_access'     => ['status' => 'skipped'],
            'authentication'  => ['status' => 'skipped'],
            'onu_data_fetch'  => ['status' => 'skipped'],
        ];
        $diagnostics = [
            'configured_enabled' => (bool)$this->config['enabled'],
            'host'               => $host,
            'port'               => $port,
            'method_configured'  => $method,
            'username'           => $this->config['username'],
            'password_set'       => $this->config['password'] !== '' && $this->config['password'] !== 'your_hsgq_password_here',
            'olt_type'           => $this->config['type'],
            'tcp_reachable'      => false,
            'authenticated'      => false,
            'auth_variant'       => null,
            'olt_device'         => null,
            'hints'              => [],
        ];

        $fail = function (string $message, ?array $cause, array $hints) use (&$steps, &$diagnostics, $host, $port, $method, $checkedAt) {
            $diagnostics['hints'] = $hints;
            return [
                'success'        => false,
                'brand'          => 'HSGQ',
                'olt_name'       => $this->getName(),
                'host'           => "{$host}:{$port}",
                'method_used'    => $method,
                'latency_ms'     => $steps['port_access']['latency_ms'] ?? null,
                'message'        => $message,
                'failure_cause'  => $cause,
                'steps'          => $steps,
                'diagnostics'    => $diagnostics,
                'checked_at'     => $checkedAt,
            ];
        };

        if (!$this->config['enabled']) {
            return $fail('Modul OLT HSGQ dinonaktifkan (HSGQ_ENABLED=false).', ['stage' => 'config', 'cause' => 'Modul dinonaktifkan di .env.'], []);
        }
        if (empty($host)) {
            return $fail('Alamat IP / Host OLT HSGQ belum diatur pada file .env (HSGQ_HOST).', ['stage' => 'config', 'cause' => 'HSGQ_HOST kosong.'], []);
        }

        // Langkah 1: Keterjangkauan IP (ICMP) — informatif, karena ICMP bisa diblokir
        $ping = NetworkProbe::ping($host);
        $steps['ip_reachability'] = [
            'status'     => $ping['reachable'] === true ? 'ok' : ($ping['reachable'] === false ? 'failed' : 'unknown'),
            'latency_ms' => $ping['latency_ms'],
            'detail'     => $ping['note'],
        ];

        // Langkah 2: Akses port TCP
        $tcp = NetworkProbe::tcp($host, $port, $timeout);
        $steps['port_access'] = [
            'status'     => $tcp['open'] ? 'ok' : 'failed',
            'port'       => $port,
            'protocol'   => $method === 'telnet' ? 'telnet' : 'http',
            'latency_ms' => $tcp['latency_ms'],
            'detail'     => $tcp['open'] ? "Port TCP {$port} terbuka." : $tcp['error'],
        ];

        if (!$tcp['open']) {
            Logger::warning('HSGQ', "Tes koneksi gagal (TCP) ke {$host}:{$port}", ['error' => $tcp['error']]);
            $cause = $ping['reachable'] === true
                ? "IP {$host} hidup, tetapi port {$port} tidak dapat diakses: {$tcp['error']}"
                : "IP {$host} tidak menjawab ping dan port {$port} tidak dapat diakses: {$tcp['error']}";
            return $fail(
                "OLT HSGQ ({$host}:{$port}) tidak dapat dijangkau.",
                ['stage' => 'network', 'cause' => $cause],
                [
                    "Pastikan OLT HSGQ ({$host}) menyala dan terhubung ke jaringan/VPN yang sama dengan server XAMPP.",
                    "Pastikan HSGQ_PORT ({$port}) adalah port Web Management OLT (standar 80, atau port forwarding).",
                    'Coba buka http://' . $host . ($port !== 80 ? ":{$port}" : '') . '/ di browser dari komputer ini.',
                ]
            );
        }
        $diagnostics['tcp_reachable'] = true;

        // Langkah 3: Autentikasi
        try {
            if ($method === 'telnet') {
                $output = $this->executeTelnetCommands(['show onu-info all']);
                $diagnostics['authenticated'] = true;
                $diagnostics['auth_variant']  = 'telnet_cli';
                $steps['authentication'] = ['status' => 'ok', 'variant' => 'telnet_cli', 'detail' => 'Login Telnet CLI berhasil.'];
                $steps['onu_data_fetch'] = ['status' => strlen($output) > 10 ? 'ok' : 'failed', 'detail' => 'Output CLI ' . strlen($output) . ' byte.'];

                return [
                    'success'     => true,
                    'brand'       => 'HSGQ',
                    'olt_name'    => $this->getName(),
                    'host'        => "{$host}:{$port}",
                    'method_used' => 'telnet',
                    'latency_ms'  => $tcp['latency_ms'],
                    'message'     => "Terhubung ke OLT HSGQ ({$host}:{$port}) via Telnet CLI.",
                    'steps'       => $steps,
                    'diagnostics' => $diagnostics,
                    'checked_at'  => $checkedAt,
                ];
            }

            $this->loginHttp();
        } catch (Exception $e) {
            $authErr = $this->lastAuthError ?? ['stage' => 'auth', 'cause' => $e->getMessage(), 'solution' => 'Periksa kredensial HSGQ di .env.'];
            $steps['authentication'] = [
                'status'      => 'failed',
                'username'    => $this->config['username'],
                'olt_code'    => $authErr['olt_code'] ?? null,
                'olt_message' => $authErr['olt_message'] ?? null,
                'detail'      => $authErr['cause'],
            ];
            Logger::error('HSGQ', 'Tes autentikasi gagal: ' . $e->getMessage(), ['host' => $host, 'port' => $port]);

            return $fail(
                'Koneksi jaringan ke OLT HSGQ terbuka, tetapi autentikasi gagal: ' . $authErr['cause'],
                $authErr,
                array_values(array_filter([
                    $authErr['solution'] ?? null,
                    'Mekanisme login yang dipakai: POST /userlogin?form=login (key=md5("user:pass"), value=base64(pass), sesi via header X-Token).',
                    'Uji login manual di browser: http://' . $host . ($port !== 80 ? ":{$port}" : '') . '/ dengan akun yang sama.',
                ]))
            );
        }

        $diagnostics['authenticated'] = true;
        $diagnostics['auth_variant']  = $this->authVariantUsed;
        $steps['authentication'] = [
            'status'   => 'ok',
            'username' => $this->config['username'],
            'variant'  => $this->authVariantUsed,
            'detail'   => 'Login Web API HSGQ berhasil (token sesi X-Token diterima).',
        ];

        // Langkah 4: Sampel pembacaan ONU (port PON 1)
        try {
            $diagnostics['olt_device'] = $this->loadBoardInfo();
            $ts     = round(microtime(true) * 1000);
            $sample = $this->requestDataEndpoint('onu_allow_list', "port_id=1&_={$ts}");
            if (!isset($sample['data']) || !is_array($sample['data'])) {
                $sample = $this->requestDataEndpoint('onu_list', "port_id=1&_={$ts}");
            }

            if (isset($sample['data']) && is_array($sample['data'])) {
                $steps['onu_data_fetch'] = [
                    'status'           => 'ok',
                    'pon_ports'        => $this->getEffectivePonPorts(),
                    'port_1_onu_count' => count($sample['data']),
                    'detail'           => 'Daftar ONU port PON 1 berhasil dibaca.',
                ];
            } else {
                $steps['onu_data_fetch'] = [
                    'status' => 'failed',
                    'detail' => 'Login berhasil tetapi endpoint daftar ONU (onu_allow_list / onu_list) tidak mengembalikan data. HTTP ' . $this->lastHttpCode . '.',
                ];
                return $fail(
                    'Login OLT HSGQ berhasil, tetapi daftar ONU tidak dapat dibaca.',
                    ['stage' => 'data', 'cause' => $steps['onu_data_fetch']['detail']],
                    ['Pastikan akun ' . $this->config['username'] . ' memiliki hak akses membaca daftar ONU di OLT.']
                );
            }
        } catch (Exception $e) {
            $steps['onu_data_fetch'] = ['status' => 'failed', 'detail' => $e->getMessage()];
            return $fail('Login OLT HSGQ berhasil, tetapi pembacaan ONU gagal.', ['stage' => 'data', 'cause' => $e->getMessage()], []);
        }

        return [
            'success'     => true,
            'brand'       => 'HSGQ',
            'olt_name'    => $this->getName(),
            'host'        => "{$host}:{$port}",
            'method_used' => "http ({$this->authVariantUsed})",
            'latency_ms'  => $tcp['latency_ms'],
            'message'     => "Berhasil terhubung dan login ke OLT HSGQ ({$host}:{$port}). Daftar ONU dapat dibaca.",
            'steps'       => $steps,
            'diagnostics' => $diagnostics,
            'checked_at'  => $checkedAt,
        ];
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
                'olt_type'              => $this->config['type'],
                'olt_device'            => $this->boardInfo,
                'pon_ports_scanned'     => $method === 'telnet' ? (int)$this->config['pon_ports'] : $this->getEffectivePonPorts(),
                'unsupported_endpoints' => array_keys($this->unsupportedEndpoints),
                'method_used'           => $methodUsed,
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
                'failure_detail'    => $this->lastAuthError,
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
            $this->lastHttpCode = 0;
            throw new Exception("Gagal menghubungi HTTP API OLT HSGQ ({$this->config['host']}:{$this->config['port']}): {$err}");
        }

        $this->lastHttpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
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
        $this->token         = null;
        $this->lastAuthError = null;

        $user = $this->config['username'];
        $pass = $this->config['password'];

        if ($user === '' || $pass === '' || $pass === 'your_hsgq_password_here') {
            $this->lastAuthError = [
                'stage'    => 'config',
                'cause'    => 'Kredensial HSGQ belum diisi pada file .env (HSGQ_USERNAME / HSGQ_PASSWORD).',
                'solution' => 'Isi HSGQ_USERNAME dan HSGQ_PASSWORD dengan akun login Web OLT HSGQ.',
            ];
            throw new Exception($this->lastAuthError['cause']);
        }

        // Varian 1: Firmware HSGQ Web JSON API (userlogin?form=login)
        // Mekanisme diverifikasi dari JS login Web HSGQ: key = md5("user:pass"), value = base64(pass),
        // token sesi dikembalikan lewat header "X-Token" dan wajib dikirim ulang di setiap request.
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
        if (isset($res['code']) && in_array((int)$res['code'], [1, 200], true)) {
            $this->authVariantUsed = 'userlogin_json';
            return true;
        }

        // Jika firmware sudah menjawab endpoint userlogin dengan kode error JSON, berarti mekanisme
        // login sudah benar namun kredensial ditolak. Jangan mencoba varian lain agar tidak
        // menambah percobaan login gagal (menghindari lockout akun di OLT).
        if (is_array($res) && isset($res['code'])) {
            $this->lastAuthError = $this->explainLoginError((int)$res['code'], trim((string)($res['message'] ?? $res['msg'] ?? '')));
            throw new Exception("Login ke OLT HSGQ gagal: {$this->lastAuthError['cause']} (kode OLT {$res['code']}: \"{$this->lastAuthError['olt_message']}\")");
        }

        // Varian 2: Firmware HSGQ CGI Lama (login.cgi) — hanya bila endpoint userlogin tidak tersedia
        $resCgi = $this->httpRequest(
            'login.cgi?username=' . rawurlencode($user) . '&password=' . rawurlencode($pass) . '&method=login'
        );
        if (isset($resCgi['code']) && in_array((int)$resCgi['code'], [1, 200], true)) {
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

        $this->lastAuthError = [
            'stage'       => 'auth_endpoint',
            'olt_code'    => null,
            'olt_message' => null,
            'cause'       => 'Tidak ada endpoint login HSGQ yang dikenali (userlogin, login.cgi, api/login). Firmware/web server mungkin berbeda atau port bukan port Web OLT.',
            'solution'    => 'Pastikan HSGQ_PORT mengarah ke port Web Management OLT (biasanya 80) dan halaman login Web dapat dibuka di browser.',
        ];
        throw new Exception('Login ke OLT HSGQ gagal: ' . $this->lastAuthError['cause']);
    }

    /**
     * Menerjemahkan kode/pesan error login firmware HSGQ menjadi penyebab & solusi yang spesifik.
     */
    private function explainLoginError(int $code, string $message): array
    {
        $lower = strtolower($message);
        $user  = $this->config['username'];

        if (strpos($lower, 'not exist') !== false) {
            $cause    = "Username '{$user}' tidak terdaftar di OLT HSGQ.";
            $solution = 'Periksa HSGQ_USERNAME di .env (huruf besar/kecil berpengaruh) dan pastikan akun tersebut ada di menu User Management OLT.';
        } elseif (strpos($lower, 'password') !== false) {
            $cause    = "Password untuk username '{$user}' ditolak oleh OLT HSGQ.";
            $solution = 'Periksa HSGQ_PASSWORD di .env. Pastikan sama dengan password login Web OLT.';
        } elseif (strpos($lower, 'lock') !== false || strpos($lower, 'too many') !== false) {
            $cause    = 'Akun dikunci sementara oleh OLT karena terlalu banyak percobaan login gagal.';
            $solution = 'Tunggu beberapa menit sebelum mencoba kembali.';
        } elseif (strpos($lower, 'online') !== false || strpos($lower, 'max') !== false || strpos($lower, 'session') !== false) {
            $cause    = 'Batas sesi login OLT penuh atau akun sedang dipakai di sesi lain.';
            $solution = 'Logout dari sesi Web OLT yang lain, lalu coba kembali.';
        } elseif (strpos($lower, 'captcha') !== false || strpos($lower, 'verif') !== false) {
            $cause    = 'Firmware OLT meminta captcha/verifikasi pada login.';
            $solution = 'Nonaktifkan captcha pada pengaturan login Web OLT atau gunakan metode Telnet (HSGQ_METHOD=telnet).';
        } else {
            $cause    = 'OLT HSGQ menolak login.';
            $solution = 'Periksa kembali HSGQ_USERNAME dan HSGQ_PASSWORD di .env.';
        }

        return [
            'stage'       => 'auth',
            'olt_code'    => $code,
            'olt_message' => $message,
            'cause'       => $cause,
            'solution'    => $solution,
        ];
    }

    /**
     * Membaca informasi perangkat (model, firmware, jumlah port PON) dari endpoint board_info.
     * Endpoint ini read-only dan dipakai oleh halaman dashboard Web HSGQ.
     */
    private function loadBoardInfo(): ?array
    {
        if ($this->boardInfo !== null) {
            return $this->boardInfo;
        }
        $res = $this->httpRequest('board_info?_=' . round(microtime(true) * 1000));
        if (isset($res['code']) && (int)$res['code'] === 1 && isset($res['data']) && is_array($res['data'])) {
            $d = $res['data'];
            $this->boardInfo = [
                'product_name' => $d['product_name'] ?? null,
                'fw_ver'       => $d['fw_ver'] ?? null,
                'sys_ver'      => $d['sys_ver'] ?? null,
                'hw_ver'       => $d['hw_ver'] ?? null,
                'pon_ports'    => isset($d['ponports']) && is_numeric($d['ponports']) ? (int)$d['ponports'] : null,
            ];
        }
        return $this->boardInfo;
    }

    /**
     * Jumlah port PON yang dipindai: memakai nilai dari board_info OLT bila tersedia,
     * selain itu memakai HSGQ_PON_PORTS dari .env.
     */
    private function getEffectivePonPorts(): int
    {
        $detected = $this->boardInfo['pon_ports'] ?? null;
        if (is_int($detected) && $detected > 0) {
            return $detected;
        }
        return max(1, (int)$this->config['pon_ports']);
    }

    /**
     * Request ke endpoint data per-PON; endpoint yang membalas HTTP 404 dicatat sebagai
     * tidak didukung firmware sehingga tidak diminta ulang untuk port berikutnya.
     */
    private function requestDataEndpoint(string $name, string $query): ?array
    {
        if (isset($this->unsupportedEndpoints[$name])) {
            return null;
        }
        $res = $this->httpRequest($name . '?' . $query);
        if ($this->lastHttpCode === 404) {
            $this->unsupportedEndpoints[$name] = true;
            return null;
        }
        return $res;
    }

    /**
     * Mengambil data ONU & redaman optik per port PON melalui HTTP API HSGQ.
     */
    private function fetchViaHttpApi(string $fetchedAt): array
    {
        $this->loginHttp();
        $this->loadBoardInfo();

        $portCount = $this->getEffectivePonPorts();
        $ts        = round(microtime(true) * 1000);
        $records   = [];

        for ($port = 1; $port <= $portCount; $port++) {
            $q = "port_id={$port}&_={$ts}";

            // Firmware HSGQ EPON (mis. HSGQ-E04R V3.5.x) menyediakan daftar ONU + receive_power
            // melalui onu_allow_list, serta Tx Power + Rx Power + Suhu melalui ponmgmt?form=optical_onu.
            // Endpoint yang membalas 404 dicatat dan tidak diminta ulang di port berikutnya.
            $allowData  = $this->requestDataEndpoint('onu_allow_list', $q);
            $ponOptData = $this->requestDataEndpoint('ponmgmt', "form=optical_onu&{$q}");
            $listData   = $this->requestDataEndpoint('onu_list', $q);
            $optData    = $this->requestDataEndpoint('onu_optical_info', $q);
            $infoData   = $this->requestDataEndpoint('onu_info', $q);

            // Dukungan tambahan untuk firmware HSGQ GPON (gponont_mgmt)
            $gponAuth = null;
            $gponOpt  = null;
            if (
                (!isset($listData['data']) || empty($listData['data'])) &&
                (!isset($allowData['data']) || empty($allowData['data'])) &&
                (!isset($optData['data']) || empty($optData['data']))
            ) {
                $gponAuth = $this->requestDataEndpoint('gponont_mgmt', "form=auth&{$q}");
                $gponOpt  = isset($this->unsupportedEndpoints['gponont_mgmt']) ? null : $this->httpRequest("gponont_mgmt?form=optical&{$q}");
            }

            // Gabungkan seluruh data berdasarkan onu_id pada port ini
            $mergedByOnuId = [];
            $sources       = [$listData, $allowData, $infoData, $optData, $ponOptData, $gponAuth, $gponOpt];

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
                        ?? $onu['work_temprature']
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

