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
 * HiosoDriver - Modul Backend Khusus OLT HIOSO (HA7302CS / HA7304 / HA7308)
 * ============================================================================
 * Mengambil data redaman optik (Rx Power, Tx Power, Status, SN/MAC, Port PON)
 * dari perangkat OLT HIOSO secara Read-Only tanpa mengubah konfigurasi OLT.
 *
 * Metode Komunikasi yang Didukung:
 * 1. SNMP v2c Read-Only (Utama & Tercepat):
 *    - Mendukung deteksi otomatis multi-branch MIB Hioso:
 *      * HIOSO_C    : Enterprise .1.3.6.1.4.1.25355.3.2 (EPON C-Data/Hioso)
 *      * HIOSO_GPON : Enterprise .1.3.6.1.4.1.25355.3.3 (GPON C-Data/Hioso)
 *      * HIOSO_B    : Enterprise .1.3.6.1.4.1.3320.101  (EPON BDCOM-based)
 *      * HIOSO_STD  : Enterprise .1.3.6.1.4.1.17409.2.3 (Standard EPON MIB)
 *    - Mendukung ekstensi PHP native (php_snmp) maupun fallback CLI (snmpwalk).
 * 2. HTTP Web Management ASP Read-Only (Alternatif 1):
 *    - Membaca halaman .asp Hioso (onuConfigPonList.asp, onuOpticalList.asp,
 *      ponOnuList.asp) menggunakan HTTP Basic Auth / sesi /goform/login.
 * 3. Telnet CLI Read-Only (Alternatif 2):
 *    - Negosiasi penuh protokol Telnet IAC dan eksekusi perintah baca:
 *      show onu info / show onu optical-ddm / show onu optical-info.
 */
class HiosoDriver implements OltDriverInterface
{
    private array $config;
    private string $lastError = '';
    private string $detectedProfileName = '';
    private ?array $deviceInfo = null;
    private array $detectedPonIds = [];
    private ?array $lastAuthError = null;

    /**
     * Daftar profil OID resmi untuk berbagai varian firmware OLT HIOSO.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $oidProfiles = [
        'HIOSO_C' => [
            'description' => 'Hioso / C-Data EPON MIB (.1.3.6.1.4.1.25355.3.2)',
            'name'        => '.1.3.6.1.4.1.25355.3.2.6.3.2.1.37',
            'name_alt'    => '.1.3.6.1.4.1.25355.3.2.6.3.2.1.2',
            'sn'          => '.1.3.6.1.4.1.25355.3.2.6.3.2.1.11',
            'status'      => '.1.3.6.1.4.1.25355.3.2.6.3.2.1.39',
            'tx'          => '.1.3.6.1.4.1.25355.3.2.6.14.2.1.4',
            'rx'          => '.1.3.6.1.4.1.25355.3.2.6.14.2.1.8',
            'temp'        => '.1.3.6.1.4.1.25355.3.2.6.14.2.1.7',
            'distance'    => '.1.3.6.1.4.1.25355.3.2.6.3.2.1.25',
            'is_gpon'     => false,
        ],
        'HIOSO_GPON' => [
            'description' => 'Hioso / C-Data GPON MIB (.1.3.6.1.4.1.25355.3.3)',
            'name'        => '.1.3.6.1.4.1.25355.3.3.1.1.1.2',
            'sn'          => '.1.3.6.1.4.1.25355.3.3.1.1.1.5',
            'status'      => '.1.3.6.1.4.1.25355.3.3.1.1.1.11',
            'tx'          => '.1.3.6.1.4.1.25355.3.3.1.1.4.1.2',
            'rx'          => '.1.3.6.1.4.1.25355.3.3.1.1.4.1.1',
            'temp'        => '.1.3.6.1.4.1.25355.3.3.1.1.4.1.3',
            'distance'    => '.1.3.6.1.4.1.25355.3.3.1.1.1.13',
            'is_gpon'     => true,
        ],
        'HIOSO_B' => [
            'description' => 'Hioso / BDCOM EPON MIB (.1.3.6.1.4.1.3320.101)',
            'name'        => '.1.3.6.1.4.1.3320.101.10.1.1.79',
            'sn'          => '.1.3.6.1.4.1.3320.101.10.1.1.3',
            'status'      => '.1.3.6.1.4.1.3320.101.10.1.1.26',
            'tx'          => '.1.3.6.1.4.1.3320.101.10.5.1.5',
            'rx'          => '.1.3.6.1.4.1.3320.101.10.5.1.6',
            'temp'        => '.1.3.6.1.4.1.3320.101.10.5.1.2',
            'distance'    => '.1.3.6.1.4.1.3320.101.10.1.1.27',
            'is_gpon'     => false,
        ],
        'HIOSO_STD' => [
            'description' => 'Hioso HA7302/HA7304 Standard EPON MIB (.1.3.6.1.4.1.17409.2.3)',
            'name'        => '.1.3.6.1.4.1.17409.2.3.4.1.1.2',
            'sn'          => '.1.3.6.1.4.1.17409.2.3.4.1.1.7',
            'status'      => '.1.3.6.1.4.1.17409.2.3.4.1.1.8',
            'tx'          => '.1.3.6.1.4.1.17409.2.3.4.2.1.5',
            'rx'          => '.1.3.6.1.4.1.17409.2.3.4.2.1.4',
            'temp'        => '.1.3.6.1.4.1.17409.2.3.4.2.1.8',
            'distance'    => '.1.3.6.1.4.1.17409.2.3.4.1.1.15',
            'is_gpon'     => false,
        ],
    ];

    public function __construct(array $configOverrides = [])
    {
        $this->config = EnvLoader::getHiosoConfig($configOverrides);
    }

    public function getBrand(): string
    {
        return 'HIOSO';
    }

    public function getName(): string
    {
        return (string)$this->config['name'];
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    private function hasHttpCredentials(): bool
    {
        $u = trim((string)$this->config['username']);
        $p = (string)$this->config['password'];
        return $u !== '' && $p !== '' && $p !== 'your_hioso_password_here';
    }

    /**
     * Menguji koneksi ke OLT HIOSO secara bertahap (read-only):
     *  1. Keterjangkauan IP (ICMP ping)
     *  2. Akses port (HTTP GoAhead-Webs / SNMP / Telnet)
     *  3. Autentikasi (HTTP Basic Auth / SNMP Community / Telnet)
     *  4. Pengambilan data ONU/ONT + informasi perangkat
     * Password tidak pernah ditampilkan dalam hasil diagnostik.
     */
    public function testConnection(): array
    {
        $checkedAt  = date('c');
        $host       = $this->config['host'];
        $method     = $this->config['method'];
        $httpPort   = (int)$this->config['http_port'];
        $telnetPort = (int)$this->config['telnet_port'];
        $snmpPort   = (int)$this->config['snmp_port'];

        $steps = [
            'ip_reachability' => ['status' => 'skipped'],
            'port_access'     => ['status' => 'skipped'],
            'authentication'  => ['status' => 'skipped'],
            'onu_data_fetch'  => ['status' => 'skipped'],
        ];

        $diagnostics = [
            'configured_enabled' => (bool)$this->config['enabled'],
            'host'               => $host,
            'method_configured'  => $method,
            'username'           => $this->config['username'],
            'password_set'       => $this->hasHttpCredentials(),
            'php_snmp_extension' => function_exists('snmp2_walk'),
            'snmp_port'          => $snmpPort,
            'http_port'          => $httpPort,
            'telnet_port'        => $telnetPort,
            'snmp_reachable'     => false,
            'http_reachable'     => false,
            'telnet_reachable'   => false,
            'sys_descr'          => null,
            'sys_object_id'      => null,
            'olt_device'         => null,
            'detected_profile'   => null,
            'hints'              => [],
        ];

        $fail = function (string $message, ?array $cause, array $hints, ?float $lat = null) use (&$steps, &$diagnostics, $host, $method, $checkedAt) {
            $diagnostics['hints'] = $hints;
            return [
                'success'       => false,
                'brand'         => 'HIOSO',
                'olt_name'      => $this->getName(),
                'host'          => $host,
                'method_used'   => $method,
                'latency_ms'    => $lat,
                'message'       => $message,
                'failure_cause' => $cause,
                'steps'         => $steps,
                'diagnostics'   => $diagnostics,
                'checked_at'    => $checkedAt,
            ];
        };

        if (!$this->config['enabled']) {
            return $fail('Modul OLT HIOSO dinonaktifkan (HIOSO_ENABLED=false).', ['stage' => 'config', 'cause' => 'HIOSO_ENABLED=false di .env.'], []);
        }
        if (empty($host)) {
            return $fail('Alamat IP / Host OLT HIOSO belum diatur pada file .env (HIOSO_HOST).', ['stage' => 'config', 'cause' => 'HIOSO_HOST kosong.'], []);
        }

        // Langkah 1: Keterjangkauan IP (ICMP Ping)
        $ping = NetworkProbe::ping($host);
        $steps['ip_reachability'] = [
            'status'     => $ping['reachable'] === true ? 'ok' : ($ping['reachable'] === false ? 'failed' : 'unknown'),
            'latency_ms' => $ping['latency_ms'],
            'detail'     => $ping['note'],
        ];

        // Langkah 2: Pemeriksaan akses port sesuai metode
        $httpTcp   = in_array($method, ['http', 'auto'], true) ? NetworkProbe::tcp($host, $httpPort, 3) : ['open' => false, 'latency_ms' => null, 'error' => 'skipped'];
        $telnetTcp = in_array($method, ['telnet', 'auto'], true) ? NetworkProbe::tcp($host, $telnetPort, 2) : ['open' => false, 'latency_ms' => null, 'error' => 'skipped'];

        $diagnostics['http_reachable']   = (bool)$httpTcp['open'];
        $diagnostics['telnet_reachable'] = (bool)$telnetTcp['open'];

        if (in_array($method, ['snmp', 'auto'], true) && function_exists('snmp2_get')) {
            $sysDescrVal = @snmp2_get($host, $this->config['snmp_community'], '.1.3.6.1.2.1.1.1.0', 1200000, 1);
            if ($sysDescrVal !== false) {
                $diagnostics['snmp_reachable'] = true;
                $diagnostics['sys_descr']      = trim(str_replace(['STRING:', '"', '\\"'], '', (string)$sysDescrVal));
                $sysObjVal = @snmp2_get($host, $this->config['snmp_community'], '.1.3.6.1.2.1.1.2.0', 1200000, 1);
                if ($sysObjVal !== false) {
                    $diagnostics['sys_object_id'] = trim((string)$sysObjVal);
                }
            }
        }

        $anyPortOpen = $diagnostics['http_reachable'] || $diagnostics['snmp_reachable'] || $diagnostics['telnet_reachable'];
        $steps['port_access'] = [
            'status'      => $anyPortOpen ? 'ok' : 'failed',
            'http_port'   => ['port' => $httpPort, 'open' => $diagnostics['http_reachable'], 'latency_ms' => $httpTcp['latency_ms']],
            'snmp_port'   => ['port' => $snmpPort, 'open' => $diagnostics['snmp_reachable']],
            'telnet_port' => ['port' => $telnetPort, 'open' => $diagnostics['telnet_reachable'], 'latency_ms' => $telnetTcp['latency_ms']],
            'detail'      => $anyPortOpen
                ? 'Port komunikasi OLT HIOSO terbuka (' . implode(', ', array_keys(array_filter([
                    "HTTP:{$httpPort}"     => $diagnostics['http_reachable'],
                    "SNMP:{$snmpPort}"     => $diagnostics['snmp_reachable'],
                    "Telnet:{$telnetPort}" => $diagnostics['telnet_reachable'],
                ]))) . ').'
                : 'Semua port komunikasi HIOSO (HTTP/SNMP/Telnet) tidak dapat diakses.',
        ];

        if (!$anyPortOpen) {
            Logger::warning('HIOSO', "Tes koneksi gagal (semua port tertutup/timeout) ke {$host}");
            return $fail(
                "OLT HIOSO ({$host}) tidak dapat dijangkau dari server XAMPP.",
                ['stage' => 'network', 'cause' => "IP {$host} tidak merespons pada port HTTP {$httpPort}, SNMP {$snmpPort}, maupun Telnet {$telnetPort}."],
                [
                    "Pastikan perangkat OLT HIOSO ({$host}) menyala dan terhubung ke jaringan/router yang dapat diakses dari server XAMPP.",
                    "Periksa kembali HIOSO_HOST pada file .env.",
                    "Detail TCP HTTP {$httpPort}: " . ($httpTcp['error'] ?? '-'),
                ]
            );
        }

        // Langkah 3 & 4 (Jalur Utama Web GoAhead-Webs bila HTTP terbuka pada mode http/auto)
        if (in_array($method, ['http', 'auto'], true) && $diagnostics['http_reachable']) {
            try {
                if (!$this->hasHttpCredentials()) {
                    throw new Exception('Kredensial Web HIOSO belum diatur pada file .env (HIOSO_USERNAME / HIOSO_PASSWORD).');
                }
                $devInfo = $this->loadWebSystemInfo();
                $diagnostics['olt_device'] = $devInfo;

                $steps['authentication'] = [
                    'status'   => 'ok',
                    'username' => $this->config['username'],
                    'variant'  => 'http_basic_goahead',
                    'detail'   => 'Autentikasi HTTP Basic ke Web Management GoAhead-Webs berhasil.',
                ];

                $allPage = $this->httpGetGoAhead('/onuAllPonOnuList.asp');
                $onuMap  = [];
                if ($allPage['code'] === 200 && $allPage['body'] !== '') {
                    $this->parseHiosoAspHtml($allPage['body'], 1, $onuMap);
                }
                if (empty($onuMap)) {
                    $ponIds = !empty($this->detectedPonIds) ? $this->detectedPonIds : ['0/1/1', '0/1/2'];
                    foreach ($ponIds as $idxPon => $ponIdStr) {
                        $pRes = $this->httpGetGoAhead('/onuConfigOnuList.asp?oltponno=' . rawurlencode($ponIdStr));
                        if ($pRes['code'] === 200) {
                            $this->parseHiosoAspHtml($pRes['body'], $idxPon + 1, $onuMap);
                        }
                    }
                }

                $steps['onu_data_fetch'] = [
                    'status'    => 'ok',
                    'pon_ports' => count($this->detectedPonIds) ?: ($devInfo['pon_ports'] ?? (int)$this->config['pon_ports']),
                    'onu_count' => count($onuMap),
                    'detail'    => 'Berhasil membaca ' . count($onuMap) . ' ONU beserta Rx Power & Tx Power dari Web Management HIOSO.',
                ];

                return [
                    'success'     => true,
                    'brand'       => 'HIOSO',
                    'olt_name'    => $this->getName(),
                    'host'        => "{$host}:{$httpPort}",
                    'method_used' => 'http_asp (GoAhead-Webs)',
                    'latency_ms'  => $httpTcp['latency_ms'],
                    'message'     => "Berhasil terhubung dan login ke OLT HIOSO ({$host}:{$httpPort}" . (!empty($devInfo['model']) ? " — {$devInfo['model']} {$devInfo['fw_ver']}" : '') . "). Data ONU & redaman siap dibaca.",
                    'steps'       => $steps,
                    'diagnostics' => $diagnostics,
                    'checked_at'  => $checkedAt,
                ];
            } catch (Exception $httpEx) {
                $authErr = $this->lastAuthError ?? [
                    'stage'    => 'auth',
                    'cause'    => $httpEx->getMessage(),
                    'solution' => 'Periksa HIOSO_USERNAME dan HIOSO_PASSWORD pada file .env.',
                ];
                $steps['authentication'] = [
                    'status'    => 'failed',
                    'username'  => $this->config['username'],
                    'http_code' => $authErr['http_code'] ?? null,
                    'detail'    => $authErr['cause'],
                ];

                // Jika metode adalah http (atau SNMP tidak punya tabel optik), kembalikan error autentikasi yang spesifik
                if ($method === 'http' || !$diagnostics['snmp_reachable']) {
                    return $fail(
                        'Port HTTP OLT HIOSO terbuka, tetapi autentikasi/pembacaan gagal: ' . $authErr['cause'],
                        $authErr,
                        [
                            $authErr['solution'] ?? 'Pastikan HIOSO_USERNAME dan HIOSO_PASSWORD sesuai dengan login Web http://' . $host . '/',
                            'Server Web OLT menggunakan GoAhead-Webs HTTP Basic Authentication (realm="EPON-System").',
                        ],
                        $httpTcp['latency_ms']
                    );
                }
            }
        }

        // Langkah 3 & 4 Alternatif: SNMP (jika method=snmp atau fallback auto ketika HTTP tidak digunakan)
        if (in_array($method, ['snmp', 'auto'], true) && $diagnostics['snmp_reachable']) {
            $steps['authentication'] = [
                'status' => 'ok',
                'variant' => 'snmp_v2c',
                'detail' => "SNMP v2c merespons sysDescr ('{$diagnostics['sys_descr']}').",
            ];
            $profile = $this->detectSnmpProfile();
            if ($profile !== null) {
                $diagnostics['detected_profile'] = $this->detectedProfileName . ' (' . ($profile['description'] ?? '') . ')';
                $steps['onu_data_fetch'] = [
                    'status' => 'ok',
                    'detail' => "Tabel OID ONU HIOSO ditemukan ({$this->detectedProfileName}).",
                ];
                return [
                    'success'     => true,
                    'brand'       => 'HIOSO',
                    'olt_name'    => $this->getName(),
                    'host'        => "{$host}:{$snmpPort}",
                    'method_used' => "snmp ({$this->detectedProfileName})",
                    'latency_ms'  => $ping['latency_ms'],
                    'message'     => "Berhasil terhubung ke OLT HIOSO ({$host}) via SNMP v2c ({$this->detectedProfileName}).",
                    'steps'       => $steps,
                    'diagnostics' => $diagnostics,
                    'checked_at'  => $checkedAt,
                ];
            }

            $steps['onu_data_fetch'] = [
                'status' => 'failed',
                'detail' => "SNMP hanya mengekspos MIB-2 dasar (sysDescr: '{$diagnostics['sys_descr']}'), tidak menyediakan OID redaman optik ONU pada firmware ini.",
            ];
            if ($method === 'snmp') {
                return $fail(
                    "SNMP OLT HIOSO ({$host}) aktif, tetapi firmware ini tidak menyediakan tabel OID optik ONU via SNMP.",
                    ['stage' => 'data', 'cause' => $steps['onu_data_fetch']['detail']],
                    ['Ubah HIOSO_METHOD=http atau HIOSO_METHOD=auto pada file .env agar menggunakan jalur Web Management GoAhead-Webs.']
                );
            }
        }

        // Langkah 3 & 4 Alternatif: Telnet CLI
        if (in_array($method, ['telnet', 'auto'], true) && $diagnostics['telnet_reachable']) {
            $steps['authentication'] = [
                'status'  => 'ok',
                'variant' => 'telnet_port_open',
                'detail'  => "Port Telnet {$telnetPort} terbuka.",
            ];
            return [
                'success'     => true,
                'brand'       => 'HIOSO',
                'olt_name'    => $this->getName(),
                'host'        => "{$host}:{$telnetPort}",
                'method_used' => 'telnet',
                'latency_ms'  => $telnetTcp['latency_ms'],
                'message'     => "Port Telnet CLI OLT HIOSO ({$host}:{$telnetPort}) terbuka.",
                'steps'       => $steps,
                'diagnostics' => $diagnostics,
                'checked_at'  => $checkedAt,
            ];
        }

        return $fail(
            "OLT HIOSO ({$host}) tidak dapat diakses melalui metode '{$method}'.",
            $this->lastAuthError ?? ['stage' => 'connection', 'cause' => $this->lastError ?: 'Semua jalur komunikasi gagal.'],
            [
                "Pastikan HIOSO_HOST ({$host}), HIOSO_USERNAME, dan HIOSO_PASSWORD pada file .env sudah benar.",
                "Coba buka http://{$host}/ di browser untuk memastikan Web Management OLT aktif.",
            ]
        );
    }

    /**
     * Mengambil data redaman optik seluruh ONU dari OLT HIOSO.
     */
    public function fetchOpticalData(array $filters = []): array
    {
        $startTime = microtime(true);
        $fetchedAt = date('c');
        $host      = $this->config['host'];
        $method    = $this->config['method'];

        if (!$this->config['enabled']) {
            $emptyStats = OnuDataNormalizer::buildSummaryAndValidation([]);
            return [
                'success'           => false,
                'brand'             => 'HIOSO',
                'olt_name'          => $this->getName(),
                'olt_host'          => $host,
                'method_used'       => 'disabled',
                'fetched_at'        => $fetchedAt,
                'execution_time_ms' => 0.0,
                'error'             => 'Modul OLT HIOSO dinonaktifkan di konfigurasi .env (HIOSO_ENABLED=false).',
                'summary'           => $emptyStats['summary'],
                'validation'        => $emptyStats['validation'],
                'data'              => [],
            ];
        }

        try {
            $rawRecords = [];
            $methodUsed = $method;

            if ($method === 'snmp') {
                $rawRecords = $this->fetchViaSnmp($fetchedAt);
                $methodUsed = "snmp ({$this->detectedProfileName})";
            } elseif ($method === 'http') {
                $rawRecords = $this->fetchViaHttpWeb($fetchedAt);
                $methodUsed = 'http_asp (GoAhead-Webs)';
            } elseif ($method === 'telnet') {
                $rawRecords = $this->fetchViaTelnet($fetchedAt);
                $methodUsed = 'telnet_cli';
            } else {
                // Mode 'auto':
                // Jika kredensial HTTP tersedia, prioritaskan HTTP Web Management (.asp GoAhead-Webs)
                // karena model HIOSO HA7302/HA7304 mengembalikan seluruh ONU + Rx + Tx + Suhu dalam 1 request (< 100 ms),
                // lalu fallback ke SNMP v2c dan Telnet CLI bila diperlukan.
                $errors = [];
                if ($this->hasHttpCredentials()) {
                    try {
                        $rawRecords = $this->fetchViaHttpWeb($fetchedAt);
                        $methodUsed = 'http_asp (GoAhead-Webs)';
                    } catch (Exception $httpEx) {
                        $errors['http'] = $httpEx->getMessage();
                        // Jika gagal karena password HTTP 401 ditolak eksplisit, hentikan agar pengguna tahu password salah
                        if (($this->lastAuthError['http_code'] ?? 0) === 401) {
                            throw $httpEx;
                        }
                        Logger::warning('HIOSO', 'HTTP Web gagal pada mode auto, mencoba fallback SNMP: ' . $httpEx->getMessage());
                    }
                }

                if (empty($rawRecords)) {
                    try {
                        $rawRecords = $this->fetchViaSnmp($fetchedAt);
                        $methodUsed = "snmp ({$this->detectedProfileName})";
                    } catch (Exception $snmpEx) {
                        $errors['snmp'] = $snmpEx->getMessage();
                        Logger::warning('HIOSO', 'SNMP gagal pada mode auto, mencoba fallback Telnet: ' . $snmpEx->getMessage());

                        try {
                            $rawRecords = $this->fetchViaTelnet($fetchedAt);
                            $methodUsed = 'telnet_cli (fallback)';
                        } catch (Exception $telEx) {
                            $errors['telnet'] = $telEx->getMessage();
                            $errParts = [];
                            foreach ($errors as $k => $msg) {
                                $errParts[] = strtoupper($k) . ": {$msg}";
                            }
                            throw new Exception("Gagal mengambil data OLT HIOSO ({$host}). " . implode(' | ', $errParts));
                        }
                    }
                }
            }

            $filteredRecords = OnuDataNormalizer::applyFilters($rawRecords, $filters);
            $stats           = OnuDataNormalizer::buildSummaryAndValidation($filteredRecords);
            $elapsedMs       = round((microtime(true) - $startTime) * 1000, 2);

            Logger::info('HIOSO', "Berhasil mengambil " . count($filteredRecords) . " data ONU dari {$host} via {$methodUsed} ({$elapsedMs} ms)");

            return [
                'success'           => true,
                'brand'             => 'HIOSO',
                'olt_name'          => $this->getName(),
                'olt_host'          => $host,
                'olt_type'          => $this->config['type'],
                'olt_device'        => $this->deviceInfo,
                'pon_ports_scanned' => count($this->detectedPonIds) ?: ($this->deviceInfo['pon_ports'] ?? (int)$this->config['pon_ports']),
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
            Logger::error('HIOSO', 'Gagal mengambil data redaman: ' . $e->getMessage(), ['host' => $host]);

            $emptyStats = OnuDataNormalizer::buildSummaryAndValidation([]);

            return [
                'success'           => false,
                'brand'             => 'HIOSO',
                'olt_name'          => $this->getName(),
                'olt_host'          => $host,
                'olt_type'          => $this->config['type'],
                'method_used'       => $method,
                'fetched_at'        => $fetchedAt,
                'execution_time_ms' => $elapsedMs,
                'error'             => $e->getMessage(),
                'failure_detail'    => $this->lastAuthError,
                'troubleshooting'   => [
                    'Pastikan IP OLT HIOSO (' . $host . ') dapat dijangkau dari server XAMPP ini.',
                    'Pastikan HIOSO_USERNAME dan HIOSO_PASSWORD di .env sesuai dengan login Web http://' . $host . '/.',
                    'Gunakan endpoint /api/test.php?brand=hioso untuk melihat diagnosa lengkap per protokol.',
                ],
                'summary'           => $emptyStats['summary'],
                'validation'        => $emptyStats['validation'],
                'data'              => [],
            ];
        }
    }

    /**
     * ========================================================================
     * METODE 1: SNMP v2c READ-ONLY (MULTI-PROFIL MIB HIOSO)
     * ========================================================================
     */
    private function snmpWalk(string $oid)
    {
        $host      = $this->config['host'];
        $port      = (int)$this->config['snmp_port'];
        $target    = ($port !== 161 && strpos($host, ':') === false) ? "{$host}:{$port}" : $host;
        $community = $this->config['snmp_community'];
        $timeoutUs = max(1, (int)$this->config['snmp_timeout_sec']) * 1000000;
        $retries   = max(1, (int)$this->config['snmp_retries']);

        if (function_exists('snmp2_real_walk')) {
            if (function_exists('snmp_set_oid_numeric_print')) {
                @snmp_set_oid_numeric_print(1);
            }
            if (function_exists('snmp_set_quick_print')) {
                @snmp_set_quick_print(1);
            }
            if (function_exists('snmp_set_enum_print')) {
                @snmp_set_enum_print(0);
            }

            $res = @snmp2_real_walk($target, $community, $oid, $timeoutUs, $retries);
            if (is_array($res) && count($res) > 0) {
                return $res;
            }

            $this->lastError = "snmp2_real_walk tidak mengembalikan data untuk OID {$oid} pada {$target}.";
            return false;
        }

        // Fallback ke CLI snmpwalk jika ekstensi php_snmp belum diaktifkan
        $timeoutSec = max(1, (int)$this->config['snmp_timeout_sec']);
        $cmd = sprintf(
            'snmpwalk -v2c -c %s -Oqn -t %d -r %d %s %s 2>&1',
            escapeshellarg($community),
            $timeoutSec,
            $retries,
            escapeshellarg($target),
            escapeshellarg($oid)
        );

        $output = @shell_exec($cmd);
        if (
            !$output ||
            stripos($output, 'Timeout') !== false ||
            stripos($output, 'No Such Object') !== false ||
            stripos($output, 'No Such Instance') !== false ||
            stripos($output, 'not recognized') !== false ||
            stripos($output, 'not found') !== false
        ) {
            $this->lastError = 'CLI snmpwalk gagal: ' . trim((string)$output);
            return false;
        }

        $lines  = preg_split('/\r\n|\r|\n/', trim($output));
        $result = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $cleanLine = preg_replace('/\s*[:=]\s*/', ' ', $line, 1);
            $parts     = explode(' ', trim($cleanLine), 2);
            if (count($parts) >= 2) {
                $oidKey          = rtrim($parts[0], ':');
                $result[$oidKey] = trim($parts[1]);
            }
        }

        return count($result) > 0 ? $result : false;
    }

    /**
     * Mendeteksi profil MIB HIOSO yang aktif pada perangkat OLT.
     */
    private function detectSnmpProfile(): ?array
    {
        // Cek apakah pengguna mengisi Custom OID di .env
        $custom = $this->config['custom_oids'] ?? [];
        if (!empty($custom['rx'])) {
            $this->detectedProfileName = 'CUSTOM_ENV';
            return [
                'description' => 'Custom OID dari file .env',
                'name'        => $custom['name'] ?: '.1.3.6.1.4.1.25355.3.2.6.3.2.1.37',
                'sn'          => $custom['sn'] ?: '.1.3.6.1.4.1.25355.3.2.6.3.2.1.11',
                'status'      => $custom['status'] ?: '.1.3.6.1.4.1.25355.3.2.6.3.2.1.39',
                'rx'          => $custom['rx'],
                'tx'          => $custom['tx'] ?: '.1.3.6.1.4.1.25355.3.2.6.14.2.1.4',
                'is_gpon'     => stripos($this->config['type'], 'GPON') !== false,
            ];
        }

        // Jika pengguna memilih profil spesifik di .env (bukan 'auto')
        $pref = strtoupper(trim((string)$this->config['snmp_profile']));
        if ($pref !== '' && $pref !== 'AUTO' && isset($this->oidProfiles[$pref])) {
            $this->detectedProfileName = $pref;
            return $this->oidProfiles[$pref];
        }

        // Loop semua profil resmi HIOSO untuk menemukan yang merespon
        foreach ($this->oidProfiles as $profileName => $map) {
            $testWalk = $this->snmpWalk($map['name']);
            if ($testWalk === false && !empty($map['name_alt'])) {
                $testWalk = $this->snmpWalk($map['name_alt']);
                if ($testWalk !== false) {
                    $map['name'] = $map['name_alt'];
                }
            }
            if ($testWalk !== false) {
                $this->detectedProfileName = $profileName;
                return $map;
            }
        }

        return null;
    }

    /**
     * Mengambil data redaman optik ONU melalui SNMP v2c.
     */
    private function fetchViaSnmp(string $fetchedAt): array
    {
        $sysDescr = $this->snmpWalk('.1.3.6.1.2.1.1.1');
        if ($sysDescr === false) {
            throw new Exception(
                "Koneksi SNMP ke OLT HIOSO ({$this->config['host']}:{$this->config['snmp_port']}) timeout atau ditolak. " .
                "Pastikan IP, Port UDP 161, dan SNMP Community benar."
            );
        }

        $activeProfile = $this->detectSnmpProfile();
        if ($activeProfile === null) {
            throw new Exception(
                "SNMP terhubung ke {$this->config['host']}, namun tidak ditemukan tabel ONU pada MIB HIOSO (EPON/GPON/BDCOM/17409)."
            );
        }

        $names = $this->snmpWalk($activeProfile['name']);

        // Cari OID SN/MAC terbaik yang memiliki isi valid
        $parentBranch = substr($activeProfile['name'], 0, (int)strrpos($activeProfile['name'], '.'));
        $snCandidates = array_values(array_unique(array_filter([
            $activeProfile['sn'] ?? '',
            $parentBranch . '.11',
            '.1.3.6.1.4.1.25355.3.2.10.1.1.2',
            $parentBranch . '.12',
            $parentBranch . '.2',
            '.1.3.6.1.4.1.25355.3.2.1.2.1.2',
            '.1.3.6.1.4.1.25355.3.2.6.1.1.18',
            '.1.3.6.1.4.1.25355.3.2.6.3.2.1.12',
            '.1.3.6.1.4.1.25355.3.3.1.1.1.5',
            '.1.3.6.1.4.1.3320.101.10.1.1.3',
            '.1.3.6.1.4.1.17409.2.3.4.1.1.7',
        ])));

        $sns       = false;
        $snOidUsed = $activeProfile['sn'];
        foreach ($snCandidates as $soid) {
            $walkRes = $this->snmpWalk($soid);
            if (is_array($walkRes) && count($walkRes) > 0) {
                $sample      = (string)reset($walkRes);
                $cleanSample = trim(str_replace(['"', ' ', 'Hex-STRING:', 'STRING:'], '', $sample));
                $isStatusWord = preg_match('/^(Registered|Offline|Active|Online|Down|Up|Power|Alarm)$/i', $cleanSample);
                if ($cleanSample !== '' && strlen($cleanSample) >= 4 && !$isStatusWord) {
                    $sns       = $walkRes;
                    $snOidUsed = $soid;
                    break;
                }
            }
        }

        $statuses  = $this->snmpWalk($activeProfile['status']);
        $txs       = $this->snmpWalk($activeProfile['tx']);
        $rxs       = $this->snmpWalk($activeProfile['rx']);
        $temps     = !empty($activeProfile['temp']) ? $this->snmpWalk($activeProfile['temp']) : false;
        $distances = !empty($activeProfile['distance']) ? $this->snmpWalk($activeProfile['distance']) : false;
        $isGpon    = !empty($activeProfile['is_gpon']);

        $parsedByIdx = [];

        if (is_array($names)) {
            foreach ($names as $oidKey => $val) {
                $idx = $this->extractSnmpIndex((string)$oidKey, $activeProfile['name']);
                $cleanVal = trim(str_replace(['"', 'STRING:'], '', (string)$val));
                $parsedByIdx[$idx]['onu_name'] = $cleanVal;
            }
        }

        if (is_array($sns)) {
            foreach ($sns as $oidKey => $val) {
                $idx = $this->extractSnmpIndex(rtrim((string)$oidKey, ':'), $snOidUsed);
                $parsedByIdx[$idx]['sn'] = $this->cleanAndDecodeSn((string)$val);
            }
        }

        if (is_array($statuses)) {
            foreach ($statuses as $oidKey => $val) {
                $idx = $this->extractSnmpIndex((string)$oidKey, $activeProfile['status']);
                preg_match('/\d+/', (string)$val, $m);
                $statusCode = isset($m[0]) ? (int)$m[0] : 0;

                if ($isGpon) {
                    // C-Data/Hioso GPON: 1=offline, 2=online, 3=registered, 4=authenticated
                    $isUp = ($statusCode >= 2 && $statusCode <= 4);
                } else {
                    // Hioso EPON: 1=Up/Auth, 3=Registered, 4=Online
                    $isUp = in_array($statusCode, [1, 3, 4], true);
                }
                $parsedByIdx[$idx]['status'] = $isUp ? 'online' : 'offline';
            }
        }

        if (is_array($txs)) {
            foreach ($txs as $oidKey => $val) {
                $idx = $this->extractSnmpIndex((string)$oidKey, $activeProfile['tx']);
                $parsedByIdx[$idx]['tx_power'] = $val;
            }
        }

        if (is_array($rxs)) {
            foreach ($rxs as $oidKey => $val) {
                $idx = $this->extractSnmpIndex((string)$oidKey, $activeProfile['rx']);
                $parsedByIdx[$idx]['rx_power'] = $val;
            }
        }

        if (is_array($temps)) {
            foreach ($temps as $oidKey => $val) {
                $idx = $this->extractSnmpIndex((string)$oidKey, $activeProfile['temp']);
                if (preg_match('/[-+]?\d+(?:\.\d+)?/', (string)$val, $m)) {
                    $t = (float)$m[0];
                    if (abs($t) > 200) {
                        $t /= 100.0;
                    }
                    if ($t > -40 && $t < 120) {
                        $parsedByIdx[$idx]['temperature_c'] = $t;
                    }
                }
            }
        }

        if (is_array($distances)) {
            foreach ($distances as $oidKey => $val) {
                $idx = $this->extractSnmpIndex((string)$oidKey, $activeProfile['distance']);
                if (preg_match('/\d+/', (string)$val, $m)) {
                    $parsedByIdx[$idx]['distance_m'] = (int)$m[0];
                }
            }
        }

        $records = [];
        foreach ($parsedByIdx as $snmpIdx => $item) {
            $name = trim((string)($item['onu_name'] ?? ''));
            $sn   = trim((string)($item['sn'] ?? ''));
            $rx   = OnuDataNormalizer::parseOpticalPower($item['rx_power'] ?? null, false);
            $tx   = OnuDataNormalizer::parseOpticalPower($item['tx_power'] ?? null, true);

            // Abaikan slot ONU kosong / Ghost ONU
            if ($rx === null && $tx === null && ($name === '' || stripos($name, 'NO SUCH') !== false) && ($sn === '' || $sn === 'N/A')) {
                continue;
            }

            $portAndOnu = $this->decodeHiosoIndexToPortAndOnu((string)$snmpIdx);

            $records[] = OnuDataNormalizer::normalizeOnuRecord([
                'onu_id'          => $portAndOnu['onu_id'],
                'onu_index'       => (string)$snmpIdx,
                'pon_port'        => (string)$portAndOnu['pon_port'],
                'onu_number'      => $portAndOnu['onu_number'],
                'onu_name'        => $name !== '' ? $name : "ONU-{$portAndOnu['onu_id']}",
                'sn'              => $sn !== '' ? $sn : 'N/A',
                'status'          => $item['status'] ?? 'offline',
                'rx_power_parsed' => $rx,
                'tx_power_parsed' => $tx,
                'temperature_c'   => $item['temperature_c'] ?? null,
                'distance_m'      => $item['distance_m'] ?? null,
            ], 'HIOSO', $this->getName(), $this->config['host'], $fetchedAt);
        }

        return $records;
    }

    /**
     * Mengekstrak suffix index ONU dari full OID SNMP.
     */
    private function extractSnmpIndex(string $rawOid, string $baseOid): string
    {
        $normalize = static function (string $oid): string {
            $o = trim($oid);
            $o = rtrim($o, ':');
            $o = ltrim($o, '.');
            $o = preg_replace('/^iso\./i', '1.', $o);
            return $o;
        };

        $nRaw  = $normalize($rawOid);
        $nBase = $normalize($baseOid);

        if (strpos($nRaw, $nBase) === 0) {
            $sub = ltrim(substr($nRaw, strlen($nBase)), '.');
            // Beberapa firmware Hioso menambahkan suffix .1 di ujung OID optik (misal 1.1.4.1),
            // sedangkan tabel nama memakai 3 segmen (1.1.4). Seragamkan ke 3 segmen jika terdiri dari 4 segmen berakhiran .1
            $parts = explode('.', $sub);
            if (count($parts) === 4 && $parts[3] === '1') {
                return implode('.', array_slice($parts, 0, 3));
            }
            return $sub;
        }

        $baseParts  = explode('.', $nBase);
        $lastAnchor = end($baseParts);
        $rawParts   = explode('.', $nRaw);

        $foundPos = -1;
        foreach ($rawParts as $pIdx => $pVal) {
            if ($pVal === $lastAnchor) {
                $foundPos = $pIdx;
            }
        }

        if ($foundPos !== -1) {
            $slice = array_slice($rawParts, $foundPos + 1);
            if (count($slice) === 4 && $slice[3] === '1') {
                $slice = array_slice($slice, 0, 3);
            }
            return implode('.', $slice);
        }

        return $rawOid;
    }

    /**
     * Mengubah index SNMP Hioso (contoh: "1.1.16" atau integer bitmask "268500993")
     * menjadi nomor Port PON, nomor ONU, dan ID standar "0/1/{port}:{onu}".
     */
    private function decodeHiosoIndexToPortAndOnu(string $indexStr): array
    {
        if (strpos($indexStr, '.') !== false) {
            $parts  = explode('.', $indexStr);
            $count  = count($parts);
            $onuNum = (int)$parts[$count - 1];
            $port   = ($count >= 2) ? (int)$parts[$count - 2] : 1;
            $board  = ($count >= 3) ? (int)$parts[$count - 3] : 1;

            if ($port <= 0) {
                $port = 1;
            }

            return [
                'board'      => $board,
                'pon_port'   => $port,
                'onu_number' => $onuNum,
                'onu_id'     => "0/{$board}/{$port}:{$onuNum}",
            ];
        }

        // Format Integer Bitmask (EPON SNMP Index)
        $intIdx = (int)$indexStr;
        $port   = ($intIdx >> 16) & 0xFF;
        if ($port <= 0 || $port > 16) {
            $port = ($intIdx >> 8) & 0xFF;
        }
        if ($port <= 0 || $port > 16) {
            $port = 1;
        }
        $onuNum = $intIdx & 0xFF;

        return [
            'board'      => 1,
            'pon_port'   => $port,
            'onu_number' => $onuNum,
            'onu_id'     => "0/1/{$port}:{$onuNum}",
        ];
    }

    /**
     * Membersihkan dan mendekode nilai SN / MAC Address dari Hex-STRING / binary SNMP.
     */
    private function cleanAndDecodeSn(string $rawVal): string
    {
        $clean = str_replace(['"', 'Hex-STRING:', 'STRING:'], '', $rawVal);
        $clean = trim($clean);

        if (preg_match('/^([0-9A-Fa-f]{2}\s+)+[0-9A-Fa-f]{2}$/', $clean)) {
            $hex   = preg_replace('/\s+/', '', $clean);
            $clean = $this->decodeHexSn($hex);
        } elseif (preg_match('/^[0-9A-Fa-f]{12}$/', $clean) || preg_match('/^[0-9A-Fa-f]{16}$/', $clean)) {
            $clean = $this->decodeHexSn($clean);
        } elseif (!ctype_print($clean) && (strlen($clean) === 6 || strlen($clean) === 8)) {
            $clean = $this->decodeHexSn(bin2hex($clean));
        } else {
            $clean = str_replace(' ', '', $clean);
        }

        return strtoupper(trim($clean));
    }

    private function decodeHexSn(string $hex): string
    {
        $hex = str_replace([' ', ':', '-'], '', $hex);
        if (strlen($hex) < 12) {
            return strtoupper($hex);
        }

        $asciiPrefix = '';
        for ($i = 0; $i < 8 && $i < strlen($hex); $i += 2) {
            $asciiPrefix .= chr(hexdec(substr($hex, $i, 2)));
        }

        // Jika 4 byte pertama adalah kode vendor GPON ASCII (misal HWTC, ZTEG, RTEG) diikuti 8 hex
        if (strlen($hex) === 16 && ctype_print($asciiPrefix) && preg_match('/^[A-Z0-9]{4}$/i', $asciiPrefix)) {
            return strtoupper($asciiPrefix . substr($hex, 8));
        }

        // Jika 12 karakter hex (6 byte MAC Address EPON)
        if (strlen($hex) === 12) {
            return strtoupper(implode(':', str_split($hex, 2)));
        }

        return strtoupper($hex);
    }

    /**
     * ========================================================================
     * METODE 2: HTTP WEB INTERFACE (.ASP) BAWAAN OLT HIOSO (HA7302/HA7304/HA7308)
     * ========================================================================
     */
    private function httpGetGoAhead(string $path): array
    {
        $host    = $this->config['host'];
        $port    = (int)$this->config['http_port'];
        $user    = $this->config['username'];
        $pass    = $this->config['password'];
        $timeout = max(5, (int)$this->config['timeout']);
        $baseUrl = ($port === 80) ? "http://{$host}" : "http://{$host}:{$port}";

        $url  = $baseUrl . '/' . ltrim($path, '/');
        $auth = 'Authorization: Basic ' . base64_encode("{$user}:{$pass}");

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(6, $timeout),
            CURLOPT_HTTPHEADER     => [
                $auth,
                "Referer: {$baseUrl}/",
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
            ],
        ]);

        $body = curl_exec($ch);
        if (curl_errno($ch)) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception("Gagal menghubungi Web Management OLT HIOSO ({$baseUrl}): {$err}");
        }

        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $bodyStr = (string)$body;
        if ($code === 401 || stripos($bodyStr, 'Access Denied!Please login') !== false) {
            $this->lastAuthError = [
                'stage'     => 'auth',
                'http_code' => 401,
                'cause'     => "Autentikasi HTTP Basic ke OLT HIOSO ditolak (HTTP 401): Username '{$user}' atau password salah.",
                'solution'  => "Periksa HIOSO_USERNAME dan HIOSO_PASSWORD pada file .env dan pastikan dapat login ke {$baseUrl}/.",
            ];
            throw new Exception($this->lastAuthError['cause']);
        }

        return [
            'code' => $code,
            'body' => $bodyStr,
        ];
    }

    /**
     * Membaca informasi perangkat (model, firmware, jumlah port PON) dari halaman /system.asp
     * dan daftar port PON aktif dari /onuConfigPonList.asp (read-only).
     */
    private function loadWebSystemInfo(): ?array
    {
        if ($this->deviceInfo !== null) {
            return $this->deviceInfo;
        }

        $sysRes = $this->httpGetGoAhead('/system.asp');
        if ($sysRes['code'] === 200 && preg_match('/var\s+sysInfo\s*=\s*new\s+Array\s*\((.*?)\)\s*;/is', $sysRes['body'], $m)) {
            $tokens = $this->tokenizeJsArray($m[1]);
            if (count($tokens) >= 6) {
                $this->deviceInfo = [
                    'sys_name' => $tokens[0] ?? null,
                    'descr'    => $tokens[1] ?? null,
                    'location' => $tokens[2] ?? null,
                    'model'    => $tokens[3] ?? null,
                    'fw_ver'   => $tokens[4] ?? null,
                    'release'  => $tokens[5] ?? null,
                    'mac'      => $tokens[6] ?? null,
                    'sn'       => $tokens[7] ?? null,
                ];
            }
        }

        $ponRes = $this->httpGetGoAhead('/onuConfigPonList.asp');
        if ($ponRes['code'] === 200 && preg_match('/var\s+ponListTable\s*=\s*new\s+Array\s*\((.*?)\)\s*;/is', $ponRes['body'], $pm)) {
            $ptokens = $this->tokenizeJsArray($pm[1]);
            $ponIds  = [];
            foreach ($ptokens as $tok) {
                if (preg_match('/^\d+\/\d+\/\d+$/', $tok)) {
                    $ponIds[] = $tok;
                }
            }
            if (!empty($ponIds)) {
                $this->detectedPonIds = array_values(array_unique($ponIds));
                if ($this->deviceInfo === null) {
                    $this->deviceInfo = [];
                }
                $this->deviceInfo['pon_ports'] = count($this->detectedPonIds);
            }
        }

        return $this->deviceInfo;
    }

    private function fetchViaHttpWeb(string $fetchedAt): array
    {
        $host      = $this->config['host'];
        $port      = (int)$this->config['http_port'];
        $portCount = max(1, (int)$this->config['pon_ports']);
        $baseUrl   = ($port === 80) ? "http://{$host}" : "http://{$host}:{$port}";

        if (!$this->hasHttpCredentials()) {
            $this->lastAuthError = [
                'stage'    => 'config',
                'cause'    => 'Kredensial Web HIOSO belum diisi pada file .env (HIOSO_USERNAME / HIOSO_PASSWORD).',
                'solution' => 'Isi HIOSO_USERNAME dan HIOSO_PASSWORD di file .env.',
            ];
            throw new Exception($this->lastAuthError['cause']);
        }

        $this->lastAuthError = null;
        $this->loadWebSystemInfo();

        $onuMap = [];

        // Prioritas 1: Halaman gabungan seluruh PON (/onuAllPonOnuList.asp pada HA7302CST/HA7304/HA7308)
        // Menyediakan seluruh ONU + Nama + MAC/SN + Status + Suhu + TxPower + RxPower + RTT + Waktu Down dalam 1 request
        $allRes = $this->httpGetGoAhead('/onuAllPonOnuList.asp');
        if ($allRes['code'] === 200 && $allRes['body'] !== '') {
            $this->parseHiosoAspHtml($allRes['body'], 1, $onuMap);
        }

        // Prioritas 2: Jika halaman gabungan kosong/tidak didukung firmware, pindai per port PON
        if (empty($onuMap)) {
            $ponIds = !empty($this->detectedPonIds) ? $this->detectedPonIds : [];
            if (empty($ponIds)) {
                for ($p = 1; $p <= $portCount; $p++) {
                    $ponIds[] = "0/1/{$p}";
                }
            }

            foreach ($ponIds as $idx => $ponIdStr) {
                $ponNum = $idx + 1;
                if (preg_match('/\/(\d+)$/', $ponIdStr, $pm)) {
                    $ponNum = (int)$pm[1];
                }
                $candidatePages = [
                    'onuConfigOnuList.asp?oltponno=' . rawurlencode($ponIdStr),
                    "ponOnuList.asp?ponId={$ponIdStr}&pon={$ponNum}",
                    "onuOpticalList.asp?ponId={$ponIdStr}&pon={$ponNum}",
                    "onuOverviewPonList.asp?ponId={$ponIdStr}&pon={$ponNum}",
                ];

                foreach ($candidatePages as $page) {
                    $res = $this->httpGetGoAhead('/' . $page);
                    if ($res['code'] === 200 && $res['body'] !== '') {
                        $this->parseHiosoAspHtml($res['body'], $ponNum, $onuMap);
                    }
                }
            }
        }

        $records = [];
        foreach ($onuMap as $onuKey => $item) {
            $records[] = OnuDataNormalizer::normalizeOnuRecord(
                $item,
                'HIOSO',
                $this->getName(),
                $this->config['host'],
                $fetchedAt
            );
        }

        return $records;
    }

    /**
     * Memecah isi literal JavaScript new Array(...) menjadi daftar nilai bersih,
     * mengabaikan komentar // atau /* ... *\/ di dalam script ASP Hioso.
     *
     * @return string[]
     */
    private function tokenizeJsArray(string $inside): array
    {
        // Hapus komentar blok /* ... */ dan komentar baris // di luar tanda kutip
        $clean = (string)preg_replace('#/\*.*?\*/#s', '', $inside);
        $clean = (string)preg_replace('/^\s*\/\/.*$/m', '', $clean);

        $tokens = [];
        $len    = strlen($clean);
        $i      = 0;

        while ($i < $len) {
            // Lewati spasi dan koma pemisah
            while ($i < $len && ($clean[$i] === ' ' || $clean[$i] === "\t" || $clean[$i] === "\r" || $clean[$i] === "\n" || $clean[$i] === ',')) {
                $i++;
            }
            if ($i >= $len) {
                break;
            }

            // Komentar // inline setelah koma
            if ($clean[$i] === '/' && ($i + 1 < $len) && $clean[$i + 1] === '/') {
                while ($i < $len && $clean[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            $ch = $clean[$i];
            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                $i++;
                $buf = '';
                while ($i < $len && $clean[$i] !== $quote) {
                    if ($clean[$i] === '\\' && ($i + 1) < $len) {
                        $i++;
                    }
                    $buf .= $clean[$i];
                    $i++;
                }
                if ($i < $len && $clean[$i] === $quote) {
                    $i++;
                }
                $tokens[] = trim($buf);
            } else {
                $buf = '';
                while ($i < $len && $clean[$i] !== ',' && $clean[$i] !== "\r" && $clean[$i] !== "\n") {
                    $buf .= $clean[$i];
                    $i++;
                }
                $val = trim($buf);
                if ($val !== '') {
                    $tokens[] = $val;
                }
            }
        }

        return $tokens;
    }

    /**
     * Mem-parsing halaman ASP Hioso (mendukung format JS Array multi-baris onutable / ponOnuTable
     * maupun tabel HTML standar).
     */
    private function parseHiosoAspHtml(string $html, int $defaultPon, array &$onuMap): void
    {
        // Format 1: Array JavaScript Hioso (onutable stride 18 di onuAllPonOnuList.asp,
        // ponOnuTable stride 13 di onuConfigOnuList.asp, atau array per-ONU)
        if (preg_match_all('/new\s+Array\s*\((.*?)\)\s*;/is', $html, $arrMatches)) {
            foreach ($arrMatches[1] as $inside) {
                $tokens = $this->tokenizeJsArray($inside);
                $count  = count($tokens);
                if ($count < 4) {
                    continue;
                }

                // Temukan seluruh posisi awal baris ONU berdasarkan pola ID "0/1/1:1" atau "1:1"
                $rowStarts = [];
                for ($idx = 0; $idx < $count; $idx++) {
                    if (preg_match('/^(?:(\d+\/\d+)\/)?(\d+):(\d+)$/', $tokens[$idx])) {
                        $rowStarts[] = $idx;
                    }
                }

                $numRows = count($rowStarts);
                for ($r = 0; $r < $numRows; $r++) {
                    $start = $rowStarts[$r];
                    $end   = ($r + 1 < $numRows) ? $rowStarts[$r + 1] : $count;
                    $row   = array_slice($tokens, $start, $end - $start);
                    $rLen  = count($row);

                    if (!preg_match('/^(?:(\d+\/\d+)\/)?(\d+):(\d+)$/', $row[0], $idMatch)) {
                        continue;
                    }

                    $slot   = ($idMatch[1] !== '') ? $idMatch[1] : '0/1';
                    $pon    = (int)$idMatch[2];
                    $onuNum = (int)$idMatch[3];
                    $onuId  = "{$slot}/{$pon}:{$onuNum}";

                    // Struktur resmi Hioso GoAhead-Webs (onutable = 18 kolom, ponOnuTable = 13 kolom):
                    // [0]=Id, [1]=Name, [2]=MacAddress, [3]=Status(Up/PwrDown/Down), [4]=Version,
                    // [5]=ChipId, [6]=Ports, [7]=Temperature, [8]=Voltage, [9]=BiasCurrent,
                    // [10]=TxPower(dBm), [11]=RxPower(dBm), ...
                    if ($rLen >= 12 && preg_match('/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/', $row[2])) {
                        $name   = $row[1] !== '' ? $row[1] : "ONU-{$onuId}";
                        $mac    = strtoupper(str_replace('-', ':', $row[2]));
                        $status = $row[3] !== '' ? $row[3] : 'Offline';
                        $ver    = $row[4] ?? '';
                        $chip   = $row[5] ?? '';
                        $temp   = is_numeric($row[7]) ? (float)$row[7] : null;
                        $tx     = is_numeric($row[10]) ? (float)$row[10] : $row[10];
                        $rx     = is_numeric($row[11]) ? (float)$row[11] : $row[11];

                        $model      = ($chip !== '' && $chip !== '0000') ? "Chip-{$chip}" . ($ver !== '' ? " (v{$ver})" : '') : null;
                        $downReason = null;
                        $downTime   = null;
                        $distanceM  = null;

                        if ($rLen >= 18) {
                            $reasonStr  = ((string)$row[14] === '1') ? 'Dying_gasp' : 'Timeout';
                            $downReason = (strtolower($status) !== 'up') ? "{$status} ({$reasonStr})" : $reasonStr;
                            $rtt        = is_numeric($row[15]) ? (int)$row[15] : null;
                            if ($rtt !== null && $rtt > 0) {
                                $calcDist  = (int)round(($rtt * 1.6393) - 157);
                                $distanceM = max(1, $calcDist);
                            }
                            $downTime = $row[17] ?? null;
                        } elseif ($rLen >= 13 && is_numeric($row[12])) {
                            $rtt = (int)$row[12];
                            if ($rtt > 0) {
                                $distanceM = max(1, (int)round(($rtt * 1.6393) - 157));
                            }
                        }

                        $onuMap[$onuId] = [
                            'onu_id'           => $onuId,
                            'onu_index'        => "1.{$pon}.{$onuNum}",
                            'slot'             => $slot,
                            'pon_port'         => (string)$pon,
                            'onu_number'       => $onuNum,
                            'onu_name'         => $name,
                            'sn'               => $mac,
                            'mac_address'      => $mac,
                            'status'           => $status,
                            'rx_power'         => $rx,
                            'tx_power'         => $tx,
                            'temperature_c'    => $temp,
                            'distance_m'       => $distanceM,
                            'model'            => $model,
                            'last_down_reason' => $downReason,
                            'last_down_time'   => $downTime,
                        ];
                        continue;
                    }

                    // Fallback generik untuk array ringkas (mis. 6 kolom: Id, Name, MAC, Status, Rx, Tx)
                    $mac    = null;
                    $status = null;
                    $floats = [];
                    $name   = null;

                    for ($j = 1; $j < $rLen; $j++) {
                        $val = $row[$j];
                        if ($mac === null && preg_match('/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/', $val)) {
                            $mac = strtoupper(str_replace('-', ':', $val));
                        } elseif ($status === null && preg_match('/^(up|down|pwrdown|wiredown|los|online|offline|auth\w*|registered|deregistered)$/i', $val)) {
                            $status = $val;
                        } elseif (preg_match('/^[-+]?\d+\.\d+$/', $val)) {
                            $floats[] = (float)$val;
                        } elseif ($name === null && strlen($val) > 1 && !is_numeric($val)) {
                            $name = $val;
                        }
                    }

                    $rx = null;
                    $tx = null;
                    foreach ($floats as $f) {
                        if ($rx === null && $f < -3.0 && $f > -45.0) {
                            $rx = $f;
                        } elseif ($tx === null && $f >= -3.0 && $f <= 10.0) {
                            $tx = $f;
                        }
                    }

                    $onuMap[$onuId] = array_merge($onuMap[$onuId] ?? [], array_filter([
                        'onu_id'     => $onuId,
                        'onu_index'  => "1.{$pon}.{$onuNum}",
                        'slot'       => $slot,
                        'pon_port'   => (string)$pon,
                        'onu_number' => $onuNum,
                        'onu_name'   => $name,
                        'sn'         => $mac,
                        'status'     => $status,
                        'rx_power'   => $rx,
                        'tx_power'   => $tx,
                    ], static fn($v) => $v !== null && $v !== ''));
                }
            }
        }

        // Format 2: Baris tabel HTML <tr><td>0/1/1:1</td>...</tr>
        if (preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $html, $trMatches)) {
            foreach ($trMatches[1] as $trContent) {
                if (!preg_match_all('/<td[^>]*>(.*?)<\/td>/is', $trContent, $tdMatches)) {
                    continue;
                }
                $cells = array_map(static fn($c) => trim(strip_tags($c)), $tdMatches[1]);
                if (count($cells) < 3) {
                    continue;
                }

                foreach ($cells as $cIdx => $cell) {
                    if (preg_match('/^(?:(\d+\/\d+)\/)?(\d+):(\d+)$/', $cell, $idMatch)) {
                        $slot   = ($idMatch[1] !== '') ? $idMatch[1] : '0/1';
                        $pon    = (int)$idMatch[2];
                        $onuNum = (int)$idMatch[3];
                        $onuId  = "{$slot}/{$pon}:{$onuNum}";

                        $mac    = null;
                        $status = null;
                        $rx     = null;
                        $tx     = null;
                        $name   = null;

                        foreach ($cells as $k => $val) {
                            if ($k === $cIdx || $val === '') {
                                continue;
                            }
                            if ($mac === null && preg_match('/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/', $val)) {
                                $mac = strtoupper(str_replace('-', ':', $val));
                            } elseif ($status === null && preg_match('/^(up|down|pwrdown|online|offline|working)$/i', $val)) {
                                $status = $val;
                            } elseif (preg_match('/[-+]?\d+\.\d+/', $val, $fm)) {
                                $f = (float)$fm[0];
                                if ($rx === null && $f < -3.0 && $f > -45.0) {
                                    $rx = $f;
                                } elseif ($tx === null && $f >= -3.0 && $f <= 10.0) {
                                    $tx = $f;
                                }
                            } elseif ($name === null && !is_numeric($val) && strlen($val) >= 2) {
                                $name = $val;
                            }
                        }

                        $onuMap[$onuId] = array_merge($onuMap[$onuId] ?? [], array_filter([
                            'onu_id'     => $onuId,
                            'onu_index'  => "1.{$pon}.{$onuNum}",
                            'slot'       => $slot,
                            'pon_port'   => (string)$pon,
                            'onu_number' => $onuNum,
                            'onu_name'   => $name ?? ($onuMap[$onuId]['onu_name'] ?? "ONU-{$onuId}"),
                            'sn'         => $mac ?? ($onuMap[$onuId]['sn'] ?? 'N/A'),
                            'status'     => $status ?? ($onuMap[$onuId]['status'] ?? 'offline'),
                            'rx_power'   => $rx ?? ($onuMap[$onuId]['rx_power'] ?? null),
                            'tx_power'   => $tx ?? ($onuMap[$onuId]['tx_power'] ?? null),
                        ], static fn($v) => $v !== null && $v !== ''));
                        break;
                    }
                }
            }
        }
    }

    /**
     * ========================================================================
     * METODE 3: TELNET CLI READ-ONLY (DENGAN NEGOSIASI TELNET IAC PENUH)
     * ========================================================================
     */
    private function fetchViaTelnet(string $fetchedAt): array
    {
        $portCount = max(1, (int)$this->config['pon_ports']);
        $commands  = ['enable', 'epon'];

        for ($p = 1; $p <= $portCount; $p++) {
            $commands[] = "show onu info epon 0/1/{$p}";
            $commands[] = "show onu optical-ddm epon 0/1/{$p}";
            $commands[] = "show onu optical-info {$p}";
        }

        $rawOutput = $this->executeTelnetCommands($commands);
        return $this->parseTelnetOutput($rawOutput, $fetchedAt);
    }

    private function executeTelnetCommands(array $commands): string
    {
        $host    = $this->config['host'];
        $port    = (int)$this->config['telnet_port'];
        $user    = $this->config['username'];
        $pass    = $this->config['password'];
        $timeout = max(6, (int)$this->config['timeout']);

        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$fp) {
            throw new Exception("Koneksi Telnet ke OLT HIOSO ({$host}:{$port}) gagal: [{$errno}] {$errstr}");
        }

        stream_set_timeout($fp, 2);

        $output       = '';
        $buffer       = '';
        $loginHandled = false;
        $passHandled  = false;
        $cmdIndex     = 0;
        $startTime    = time();

        fwrite($fp, "\r\n");
        usleep(100000);

        $iacState = 0;
        $iacCmd   = 0;

        while (!feof($fp) && (time() - $startTime) < $timeout) {
            $read   = [$fp];
            $write  = null;
            $except = null;

            if (@stream_select($read, $write, $except, 1) > 0) {
                $data = fread($fp, 4096);
            } else {
                if ($cmdIndex === 0) {
                    fwrite($fp, "\r\n");
                }
                continue;
            }

            if ($data === false || $data === '') {
                continue;
            }

            // Negosiasi Telnet IAC (0xFF) sesuai spesifikasi firmware Hioso
            $cleanData = '';
            $dataLen   = strlen($data);
            for ($i = 0; $i < $dataLen; $i++) {
                $byte = ord($data[$i]);
                if ($iacState === 0) {
                    if ($byte === 0xFF) {
                        $iacState = 1;
                    } else {
                        $cleanData .= chr($byte);
                    }
                } elseif ($iacState === 1) {
                    if ($byte >= 251 && $byte <= 254) {
                        $iacCmd   = $byte;
                        $iacState = 2;
                    } elseif ($byte === 0xFF) {
                        $cleanData .= chr(0xFF);
                        $iacState = 0;
                    } else {
                        $iacState = 0;
                    }
                } elseif ($iacState === 2) {
                    $opt     = $byte;
                    $respCmd = ($iacCmd === 253) ? 252 : 254;
                    if ($iacCmd === 254) {
                        $respCmd = 252;
                    }
                    if ($iacCmd === 252) {
                        $respCmd = 254;
                    }
                    @fwrite($fp, chr(0xFF) . chr($respCmd) . chr($opt));
                    $iacState = 0;
                }
            }

            $output .= $cleanData;
            $buffer .= $cleanData;
            if (strlen($buffer) > 512) {
                $buffer = substr($buffer, -256);
            }
            $trimmed = trim($buffer);

            if (stripos($trimmed, 'Login incorrect') !== false || stripos($trimmed, 'authentication failed') !== false) {
                fclose($fp);
                throw new Exception('Autentikasi Telnet OLT HIOSO ditolak: Username atau Password salah.');
            }

            if (!$loginHandled && (stripos($trimmed, 'Login') !== false || stripos($trimmed, 'Username') !== false || stripos($trimmed, 'User') !== false)) {
                fwrite($fp, "{$user}\r\n");
                $loginHandled = true;
                $buffer       = '';
                continue;
            }

            if (stripos($trimmed, 'Password') !== false || stripos($trimmed, 'Passwd') !== false) {
                if (!$passHandled || stripos($trimmed, 'Access') !== false || stripos($trimmed, 'Enable') !== false) {
                    fwrite($fp, "{$pass}\r\n");
                    $passHandled = true;
                    $buffer      = '';
                    usleep(300000);
                    continue;
                }
            }

            if (stripos($trimmed, '--Press Enter--') !== false || stripos($trimmed, '--More--') !== false) {
                fwrite($fp, ' ');
                $buffer = '';
                continue;
            }

            if ($loginHandled && $passHandled && preg_match('/[>#]\s*$/', $trimmed)) {
                if ($cmdIndex < count($commands)) {
                    fwrite($fp, $commands[$cmdIndex] . "\r\n");
                    $cmdIndex++;
                    $buffer = '';
                    usleep(500000);
                } else {
                    usleep(400000);
                    $output .= (string)@fread($fp, 4096);
                    break;
                }
            }
        }

        fclose($fp);
        return $output;
    }

    private function parseTelnetOutput(string $rawOutput, string $fetchedAt): array
    {
        $lines  = preg_split('/\r\n|\r|\n/', $rawOutput);
        $onuMap = [];

        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') {
                continue;
            }

            // Pola baris CLI Hioso: 0/1/1:1  00:11:22:33:44:55  up/online  ...
            if (preg_match('/^(?:0\/\d+\/)?(\d+)[:\/](\d+)\s+([0-9A-Fa-f:.-]{12,17}|\w{8,16})\s+(up|down|online|offline|working|auth\w*)\s*(.*)$/i', $trim, $m)) {
                $pon    = (int)$m[1];
                $onuNum = (int)$m[2];
                $onuId  = "0/1/{$pon}:{$onuNum}";
                $sn     = $m[3];
                $status = $m[4];
                $rest   = trim($m[5]);

                $rx = null;
                $tx = null;
                if (preg_match_all('/[-+]?\d+\.\d+/', $rest, $floats) && !empty($floats[0])) {
                    foreach ($floats[0] as $fStr) {
                        $f = (float)$fStr;
                        if ($rx === null && $f < -3.0 && $f > -45.0) {
                            $rx = $f;
                        } elseif ($tx === null && $f >= -3.0 && $f <= 10.0) {
                            $tx = $f;
                        }
                    }
                }

                $onuMap[$onuId] = array_merge($onuMap[$onuId] ?? [], [
                    'onu_id'     => $onuId,
                    'onu_index'  => "1.{$pon}.{$onuNum}",
                    'pon_port'   => (string)$pon,
                    'onu_number' => $onuNum,
                    'onu_name'   => $onuMap[$onuId]['onu_name'] ?? "ONU-{$onuId}",
                    'sn'         => $sn,
                    'status'     => $status,
                    'rx_power'   => $rx ?? ($onuMap[$onuId]['rx_power'] ?? null),
                    'tx_power'   => $tx ?? ($onuMap[$onuId]['tx_power'] ?? null),
                ]);
            }
        }

        $records = [];
        foreach ($onuMap as $item) {
            $records[] = OnuDataNormalizer::normalizeOnuRecord(
                $item,
                'HIOSO',
                $this->getName(),
                $this->config['host'],
                $fetchedAt
            );
        }

        return $records;
    }
}

