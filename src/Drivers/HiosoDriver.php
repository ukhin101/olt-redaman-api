<?php

namespace OltRedaman\Drivers;

use Exception;
use OltRedaman\Config\EnvLoader;
use OltRedaman\Contracts\OltDriverInterface;
use OltRedaman\Support\Logger;
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

    /**
     * Menguji koneksi ke OLT HIOSO (SNMP / HTTP / Telnet sesuai konfigurasi)
     * dan memberikan laporan diagnosa lengkap jika OLT tidak dapat diakses.
     */
    public function testConnection(): array
    {
        $checkedAt = date('c');
        $host      = $this->config['host'];
        $method    = $this->config['method'];

        $diagnostics = [
            'configured_enabled' => (bool)$this->config['enabled'],
            'host'               => $host,
            'method_configured'  => $method,
            'php_snmp_extension' => function_exists('snmp2_walk'),
            'snmp_port'          => (int)$this->config['snmp_port'],
            'http_port'          => (int)$this->config['http_port'],
            'telnet_port'        => (int)$this->config['telnet_port'],
            'snmp_reachable'     => false,
            'http_reachable'     => false,
            'telnet_reachable'   => false,
            'sys_descr'          => null,
            'sys_object_id'      => null,
            'detected_profile'   => null,
            'hints'              => [],
        ];

        if (empty($host)) {
            return [
                'success'     => false,
                'brand'       => 'HIOSO',
                'olt_name'    => $this->getName(),
                'host'        => '(kosong)',
                'method_used' => $method,
                'latency_ms'  => null,
                'message'     => 'Alamat IP / Host OLT HIOSO belum diatur pada file .env (HIOSO_HOST).',
                'diagnostics' => $diagnostics,
                'checked_at'  => $checkedAt,
            ];
        }

        $t0 = microtime(true);

        // 1. Uji SNMP jika method = snmp atau auto
        if (in_array($method, ['snmp', 'auto'], true)) {
            $sysDescr = $this->snmpWalk('.1.3.6.1.2.1.1.1');
            if ($sysDescr !== false && !empty($sysDescr)) {
                $latencyMs = round((microtime(true) - $t0) * 1000, 2);
                $diagnostics['snmp_reachable'] = true;
                $diagnostics['sys_descr']      = trim(str_replace(['STRING:', '"'], '', (string)reset($sysDescr)));

                $sysObj = $this->snmpWalk('.1.3.6.1.2.1.1.2.0');
                if (is_array($sysObj) && !empty($sysObj)) {
                    $diagnostics['sys_object_id'] = trim((string)reset($sysObj));
                }

                $profile = $this->detectSnmpProfile();
                if ($profile !== null) {
                    $diagnostics['detected_profile'] = $this->detectedProfileName . ' (' . ($profile['description'] ?? '') . ')';
                    return [
                        'success'     => true,
                        'brand'       => 'HIOSO',
                        'olt_name'    => $this->getName(),
                        'host'        => "{$host}:{$this->config['snmp_port']}",
                        'method_used' => "snmp ({$this->detectedProfileName})",
                        'latency_ms'  => $latencyMs,
                        'message'     => "Berhasil terhubung ke OLT HIOSO ({$host}) via SNMP v2c dalam {$latencyMs} ms. Profil MIB terdeteksi: {$this->detectedProfileName}.",
                        'diagnostics' => $diagnostics,
                        'checked_at'  => $checkedAt,
                    ];
                }

                $diagnostics['hints'][] = 'SNMP merespon sysDescr, namun pohon OID ONU Hioso tidak ditemukan atau semua PON masih kosong.';
                if ($method === 'snmp') {
                    return [
                        'success'     => false,
                        'brand'       => 'HIOSO',
                        'olt_name'    => $this->getName(),
                        'host'        => "{$host}:{$this->config['snmp_port']}",
                        'method_used' => 'snmp',
                        'latency_ms'  => $latencyMs,
                        'message'     => "Koneksi SNMP ke {$host} berhasil (sysDescr: {$diagnostics['sys_descr']}), namun OID ONU HIOSO belum merespon data.",
                        'diagnostics' => $diagnostics,
                        'checked_at'  => $checkedAt,
                    ];
                }
            }
        }

        // 2. Uji HTTP Web jika method = http atau auto
        if (in_array($method, ['http', 'auto'], true)) {
            $httpPort = (int)$this->config['http_port'];
            $tHttp    = microtime(true);
            $fpHttp   = @fsockopen($host, $httpPort, $eCode, $eStr, 3);
            if ($fpHttp) {
                fclose($fpHttp);
                $latencyMs = round((microtime(true) - $tHttp) * 1000, 2);
                $diagnostics['http_reachable'] = true;

                if ($method === 'http' || !$diagnostics['snmp_reachable']) {
                    return [
                        'success'     => true,
                        'brand'       => 'HIOSO',
                        'olt_name'    => $this->getName(),
                        'host'        => "{$host}:{$httpPort}",
                        'method_used' => 'http',
                        'latency_ms'  => $latencyMs,
                        'message'     => "Berhasil terhubung ke Web Management OLT HIOSO ({$host}:{$httpPort}) dalam {$latencyMs} ms.",
                        'diagnostics' => $diagnostics,
                        'checked_at'  => $checkedAt,
                    ];
                }
            }
        }

        // 3. Uji Telnet jika method = telnet atau auto
        if (in_array($method, ['telnet', 'auto'], true)) {
            $telnetPort = (int)$this->config['telnet_port'];
            $tTel       = microtime(true);
            $fpTel      = @fsockopen($host, $telnetPort, $eCode, $eStr, 3);
            if ($fpTel) {
                fclose($fpTel);
                $latencyMs = round((microtime(true) - $tTel) * 1000, 2);
                $diagnostics['telnet_reachable'] = true;

                return [
                    'success'     => true,
                    'brand'       => 'HIOSO',
                    'olt_name'    => $this->getName(),
                    'host'        => "{$host}:{$telnetPort}",
                    'method_used' => 'telnet',
                    'latency_ms'  => $latencyMs,
                    'message'     => "Berhasil terhubung ke port Telnet CLI OLT HIOSO ({$host}:{$telnetPort}) dalam {$latencyMs} ms.",
                    'diagnostics' => $diagnostics,
                    'checked_at'  => $checkedAt,
                ];
            }
        }

        $diagnostics['hints'] = [
            "Pastikan perangkat OLT HIOSO ({$host}) menyala dan terhubung ke jaringan lokal atau tunnel VPN yang sama.",
            "Jika menggunakan SNMP (UDP Port {$this->config['snmp_port']}), pastikan fitur SNMP Agent sudah aktif di OLT dan Community ('{$this->config['snmp_community']}') sudah sesuai.",
            "Jika menggunakan Web/Telnet, pastikan port HTTP ({$this->config['http_port']}) atau Telnet ({$this->config['telnet_port']}) dapat diakses.",
            "Detail terakhir: {$this->lastError}",
        ];

        Logger::warning('HIOSO', "Tes koneksi gagal ke OLT HIOSO ({$host})", ['method' => $method, 'last_error' => $this->lastError]);

        return [
            'success'     => false,
            'brand'       => 'HIOSO',
            'olt_name'    => $this->getName(),
            'host'        => $host,
            'method_used' => $method,
            'latency_ms'  => null,
            'message'     => "OLT HIOSO ({$host}) tidak dapat diakses melalui metode '{$method}'. Periksa koneksi jaringan/VPN, IP OLT, atau pengaturan SNMP/Web di .env.",
            'diagnostics' => $diagnostics,
            'checked_at'  => $checkedAt,
        ];
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
                $methodUsed = 'http_asp';
            } elseif ($method === 'telnet') {
                $rawRecords = $this->fetchViaTelnet($fetchedAt);
                $methodUsed = 'telnet_cli';
            } else {
                // Mode 'auto': Prioritaskan SNMP -> HTTP Web -> Telnet CLI
                $errors = [];
                try {
                    $rawRecords = $this->fetchViaSnmp($fetchedAt);
                    $methodUsed = "snmp ({$this->detectedProfileName})";
                } catch (Exception $snmpEx) {
                    $errors['snmp'] = $snmpEx->getMessage();
                    Logger::warning('HIOSO', 'SNMP gagal pada mode auto, mencoba fallback HTTP Web: ' . $snmpEx->getMessage());

                    try {
                        $rawRecords = $this->fetchViaHttpWeb($fetchedAt);
                        $methodUsed = 'http_asp (fallback)';
                    } catch (Exception $httpEx) {
                        $errors['http'] = $httpEx->getMessage();
                        Logger::warning('HIOSO', 'HTTP Web gagal pada mode auto, mencoba fallback Telnet: ' . $httpEx->getMessage());

                        try {
                            $rawRecords = $this->fetchViaTelnet($fetchedAt);
                            $methodUsed = 'telnet_cli (fallback)';
                        } catch (Exception $telEx) {
                            $errors['telnet'] = $telEx->getMessage();
                            throw new Exception(
                                "Gagal mengambil data OLT HIOSO ({$host}) di semua jalur komunikasi. " .
                                "SNMP: {$errors['snmp']} | HTTP: {$errors['http']} | Telnet: {$errors['telnet']}"
                            );
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
                'troubleshooting'   => [
                    'Pastikan IP OLT HIOSO (' . $host . ') dapat di-ping dari server XAMPP ini.',
                    'Jika menggunakan SNMP, pastikan port UDP 161 terbuka dan HIOSO_SNMP_COMMUNITY di .env sesuai dengan pengaturan SNMP OLT.',
                    'Jika menggunakan Web/Telnet, pastikan HIOSO_USERNAME dan HIOSO_PASSWORD di .env sesuai.',
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
    private function fetchViaHttpWeb(string $fetchedAt): array
    {
        $host      = $this->config['host'];
        $port      = (int)$this->config['http_port'];
        $user      = $this->config['username'];
        $pass      = $this->config['password'];
        $portCount = max(1, (int)$this->config['pon_ports']);
        $timeout   = max(5, (int)$this->config['timeout']);

        $baseUrl    = ($port === 80) ? "http://{$host}" : "http://{$host}:{$port}";
        $cookieFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'hioso_web_' . md5($baseUrl) . '.cookie';

        // Login ke /goform/login jika firmware menggunakan form-based session
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => "{$baseUrl}/goform/login",
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'user'     => $user,
                'pass'     => $pass,
                'username' => $user,
                'password' => $pass,
                'submit'   => 'Login',
            ]),
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(6, $timeout),
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => "{$user}:{$pass}",
        ]);
        @curl_exec($ch);

        $onuMap = [];

        for ($p = 1; $p <= $portCount; $p++) {
            $ponIdStr = "0/1/{$p}";

            // Daftar halaman ASP standar yang menyimpan daftar ONU & sinyal optik pada Hioso HA7302/HA7304/HA7308
            $candidatePages = [
                "onuConfigPonList.asp?ponId={$ponIdStr}&pon={$p}",
                "ponOnuList.asp?ponId={$ponIdStr}&pon={$p}",
                "onuOpticalList.asp?ponId={$ponIdStr}&pon={$p}",
                "onuOverviewPonList.asp?ponId={$ponIdStr}&pon={$p}",
            ];

            foreach ($candidatePages as $page) {
                curl_setopt($ch, CURLOPT_HTTPGET, true);
                curl_setopt($ch, CURLOPT_URL, "{$baseUrl}/{$page}");
                $html = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                if ($html === false || $code >= 400 || empty($html)) {
                    continue;
                }

                $this->parseHiosoAspHtml((string)$html, $p, $onuMap);
            }
        }

        curl_close($ch);
        if (is_file($cookieFile)) {
            @unlink($cookieFile);
        }

        if (empty($onuMap)) {
            throw new Exception("Tidak ditemukan data ONU dari halaman Web Management OLT HIOSO ({$baseUrl}). Pastikan kredensial Web benar atau gunakan metode SNMP.");
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
     * Mem-parsing halaman ASP Hioso (mendukung format JS Array maupun tabel HTML).
     */
    private function parseHiosoAspHtml(string $html, int $defaultPon, array &$onuMap): void
    {
        // Format 1: Firmware Hioso menyematkan data di dalam Array JavaScript
        // Contoh: new Array("0/1/1:1", "NAMA_ONU", "00:11:22:33:44:55", "up", "-21.45", "1.82", ...)
        if (preg_match_all('/(?:new\s+Array|\[)\s*\(([^)]+)\)/i', $html, $arrMatches)) {
            foreach ($arrMatches[1] as $inside) {
                $tokens = str_getcsv($inside, ',', "'");
                if (count($tokens) < 3) {
                    $tokens = str_getcsv($inside, ',', '"');
                }
                $tokens = array_map(static fn($t) => trim((string)$t, " \t\n\r\0\x0B\"'"), $tokens);

                foreach ($tokens as $idx => $tok) {
                    if (preg_match('/^(?:0\/\d+\/)?(\d+):(\d+)$/', $tok, $idMatch)) {
                        $pon    = (int)$idMatch[1];
                        $onuNum = (int)$idMatch[2];
                        $onuId  = "0/1/{$pon}:{$onuNum}";

                        $mac    = null;
                        $status = null;
                        $floats = [];
                        $name   = null;

                        for ($j = $idx + 1; $j < count($tokens); $j++) {
                            $val = $tokens[$j];
                            if ($mac === null && preg_match('/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/', $val)) {
                                $mac = strtoupper(str_replace('-', ':', $val));
                            } elseif ($status === null && preg_match('/^(up|down|online|offline|auth\w*|registered|deregistered)$/i', $val)) {
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
                    if (preg_match('/^(?:0\/\d+\/)?(\d+):(\d+)$/', $cell, $idMatch)) {
                        $pon    = (int)$idMatch[1];
                        $onuNum = (int)$idMatch[2];
                        $onuId  = "0/1/{$pon}:{$onuNum}";

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
                            } elseif ($status === null && preg_match('/^(up|down|online|offline|working)$/i', $val)) {
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

