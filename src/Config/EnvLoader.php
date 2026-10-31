<?php

namespace OltRedaman\Config;

/**
 * ============================================================================
 * EnvLoader - Pembaca Konfigurasi .env Mandiri
 * ============================================================================
 * Membaca file .env tanpa memerlukan library pihak ketiga (zero-dependency),
 * serta menyediakan helper pengambilan nilai konfigurasi bertipe (string, int,
 * float, bool) untuk OLT HSGQ dan HIOSO.
 */
class EnvLoader
{
    /** @var array<string, mixed> */
    private static array $variables = [];

    /** @var bool */
    private static bool $loaded = false;

    /** @var string */
    private static string $loadedPath = '';

    /**
     * Memuat variabel konfigurasi dari file .env (atau fallback ke .env.example jika diminta).
     *
     * @param string $filePath Path absolut ke file .env
     * @param bool   $forceReload Paksa baca ulang file
     * @return bool True jika file .env ditemukan dan berhasil dimuat
     */
    public static function load(string $filePath, bool $forceReload = false): bool
    {
        if (self::$loaded && !$forceReload && self::$loadedPath === $filePath) {
            return true;
        }

        self::$loadedPath = $filePath;

        if (!is_file($filePath) || !is_readable($filePath)) {
            self::$loaded = false;
            return false;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return false;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Abaikan baris kosong atau komentar (# atau ;)
            if ($trimmed === '' || $trimmed[0] === '#' || $trimmed[0] === ';') {
                continue;
            }

            // Hapus prefix "export " jika ada
            if (stripos($trimmed, 'export ') === 0) {
                $trimmed = trim(substr($trimmed, 7));
            }

            $eqPos = strpos($trimmed, '=');
            if ($eqPos === false) {
                continue;
            }

            $key = trim(substr($trimmed, 0, $eqPos));
            $val = trim(substr($trimmed, $eqPos + 1));

            if ($key === '') {
                continue;
            }

            // Tangani nilai yang diapit tanda kutip ganda atau tunggal
            if (strlen($val) >= 2) {
                $firstChar = $val[0];
                $lastChar  = $val[strlen($val) - 1];
                if (($firstChar === '"' && $lastChar === '"') || ($firstChar === "'" && $lastChar === "'")) {
                    $val = substr($val, 1, -1);
                } else {
                    // Hapus komentar inline jika tidak di dalam tanda kutip
                    $hashPos = strpos($val, ' #');
                    if ($hashPos !== false) {
                        $val = trim(substr($val, 0, $hashPos));
                    }
                }
            }

            self::$variables[$key] = $val;
            $_ENV[$key] = $val;
            @putenv("{$key}={$val}");
        }

        self::$loaded = true;
        return true;
    }

    /**
     * Memeriksa apakah file .env sudah tersedia dan dimuat.
     */
    public static function isEnvFilePresent(): bool
    {
        $defaultEnv = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
        return is_file($defaultEnv) && is_readable($defaultEnv);
    }

    /**
     * Mengambil nilai konfigurasi sebagai string/mixed.
     *
     * @param string $key     Nama variabel .env
     * @param mixed  $default Nilai bawaan jika tidak ditemukan
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        if (array_key_exists($key, self::$variables)) {
            return self::$variables[$key];
        }
        if (array_key_exists($key, $_ENV)) {
            return $_ENV[$key];
        }
        $envVal = getenv($key);
        if ($envVal !== false) {
            return $envVal;
        }
        return $default;
    }

    /**
     * Mengambil nilai konfigurasi sebagai boolean.
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        $val = self::get($key, null);
        if ($val === null || $val === '') {
            return $default;
        }
        if (is_bool($val)) {
            return $val;
        }
        $lower = strtolower(trim((string)$val));
        if (in_array($lower, ['1', 'true', 'yes', 'on', 'active', 'enabled'], true)) {
            return true;
        }
        if (in_array($lower, ['0', 'false', 'no', 'off', 'inactive', 'disabled'], true)) {
            return false;
        }
        return $default;
    }

    /**
     * Mengambil nilai konfigurasi sebagai integer.
     */
    public static function getInt(string $key, int $default = 0): int
    {
        $val = self::get($key, null);
        if ($val === null || $val === '' || !is_numeric($val)) {
            return $default;
        }
        return (int)$val;
    }

    /**
     * Mengambil nilai konfigurasi sebagai float.
     */
    public static function getFloat(string $key, float $default = 0.0): float
    {
        $val = self::get($key, null);
        if ($val === null || $val === '' || !is_numeric($val)) {
            return $default;
        }
        return (float)$val;
    }

    /**
     * Mengambil konfigurasi terstruktur untuk OLT HSGQ dari .env
     * (Bisa ditimpa sebagian melalui parameter $overrides).
     */
    public static function getHsgqConfig(array $overrides = []): array
    {
        $config = [
            'enabled'     => self::getBool('HSGQ_ENABLED', true),
            'brand'       => 'HSGQ',
            'name'        => (string)self::get('HSGQ_NAME', 'OLT-HSGQ-01'),
            'host'        => trim((string)self::get('HSGQ_HOST', '192.168.150.2')),
            'port'        => self::getInt('HSGQ_PORT', 80),
            'scheme'      => strtolower(trim((string)self::get('HSGQ_SCHEME', 'http'))),
            'username'    => trim((string)self::get('HSGQ_USERNAME', 'admin')),
            'password'    => (string)self::get('HSGQ_PASSWORD', ''),
            'method'      => strtolower(trim((string)self::get('HSGQ_METHOD', 'http'))),
            'type'        => strtoupper(trim((string)self::get('HSGQ_TYPE', 'EPON'))),
            'pon_ports'   => self::getInt('HSGQ_PON_PORTS', 8),
            'timeout'     => self::getInt('HSGQ_TIMEOUT', 15),
            'telnet_port' => self::getInt('HSGQ_TELNET_PORT', 23),
        ];

        // Jika host mengandung ":port" (contoh: 192.168.150.2:9154), pisahkan secara otomatis
        $cleanHost = preg_replace('#^https?://#i', '', $config['host']);
        $cleanHost = rtrim($cleanHost, '/');
        if (strpos($cleanHost, ':') !== false) {
            [$h, $p] = explode(':', $cleanHost, 2);
            $config['host'] = trim($h);
            if (is_numeric($p)) {
                $config['port'] = (int)$p;
            }
        } else {
            $config['host'] = $cleanHost;
        }

        return array_merge($config, $overrides);
    }

    /**
     * Mengambil konfigurasi terstruktur untuk OLT HIOSO dari .env
     * (Bisa ditimpa sebagian melalui parameter $overrides).
     */
    public static function getHiosoConfig(array $overrides = []): array
    {
        $config = [
            'enabled'          => self::getBool('HIOSO_ENABLED', true),
            'brand'            => 'HIOSO',
            'name'             => (string)self::get('HIOSO_NAME', 'OLT-HIOSO-01'),
            'host'             => trim((string)self::get('HIOSO_HOST', '192.168.0.88')),
            'method'           => strtolower(trim((string)self::get('HIOSO_METHOD', 'auto'))),
            'snmp_port'        => self::getInt('HIOSO_SNMP_PORT', 161),
            'snmp_community'   => trim((string)self::get('HIOSO_SNMP_COMMUNITY', 'public')),
            'snmp_timeout_sec' => self::getInt('HIOSO_SNMP_TIMEOUT_SEC', 3),
            'snmp_retries'     => self::getInt('HIOSO_SNMP_RETRIES', 2),
            'snmp_profile'     => trim((string)self::get('HIOSO_SNMP_PROFILE', 'auto')),
            'http_port'        => self::getInt('HIOSO_HTTP_PORT', 80),
            'telnet_port'      => self::getInt('HIOSO_TELNET_PORT', 23),
            'username'         => trim((string)self::get('HIOSO_USERNAME', 'admin')),
            'password'         => (string)self::get('HIOSO_PASSWORD', ''),
            'type'             => strtoupper(trim((string)self::get('HIOSO_TYPE', 'EPON'))),
            'pon_ports'        => self::getInt('HIOSO_PON_PORTS', 4),
            'timeout'          => self::getInt('HIOSO_TIMEOUT', 15),
            'custom_oids'      => [
                'name'   => trim((string)self::get('HIOSO_OID_NAME', '')),
                'sn'     => trim((string)self::get('HIOSO_OID_SN', '')),
                'status' => trim((string)self::get('HIOSO_OID_STATUS', '')),
                'rx'     => trim((string)self::get('HIOSO_OID_RX', '')),
                'tx'     => trim((string)self::get('HIOSO_OID_TX', '')),
            ],
        ];

        $cleanHost = preg_replace('#^https?://#i', '', $config['host']);
        $cleanHost = rtrim($cleanHost, '/');
        if (strpos($cleanHost, ':') !== false) {
            [$h, $p] = explode(':', $cleanHost, 2);
            $config['host'] = trim($h);
            if (is_numeric($p) && (int)$p !== 161) {
                $config['http_port'] = (int)$p;
            }
        } else {
            $config['host'] = $cleanHost;
        }

        return array_merge($config, $overrides);
    }

    /**
     * Mengembalikan konfigurasi aman (tanpa mengekspos password / community penuh) untuk keperluan diagnosa.
     */
    public static function getSafeConfigSummary(): array
    {
        $hsgq  = self::getHsgqConfig();
        $hioso = self::getHiosoConfig();

        $maskSecret = function (string $val): string {
            if ($val === '' || $val === 'your_hsgq_password_here' || $val === 'your_hioso_password_here') {
                return '(belum diatur)';
            }
            $len = strlen($val);
            if ($len <= 2) {
                return str_repeat('*', $len);
            }
            return str_repeat('*', max(4, $len - 2)) . substr($val, -2);
        };

        return [
            'env_file_exists' => self::isEnvFilePresent(),
            'cache_enabled'   => self::getBool('CACHE_ENABLED', true),
            'cache_ttl_sec'   => self::getInt('CACHE_TTL_SECONDS', 30),
            'api_key_active'  => trim((string)self::get('API_KEY', '')) !== '',
            'hsgq' => [
                'enabled'     => $hsgq['enabled'],
                'name'        => $hsgq['name'],
                'host'        => $hsgq['host'],
                'port'        => $hsgq['port'],
                'scheme'      => $hsgq['scheme'],
                'username'    => $hsgq['username'],
                'password'    => $maskSecret($hsgq['password']),
                'method'      => $hsgq['method'],
                'type'        => $hsgq['type'],
                'pon_ports'   => $hsgq['pon_ports'],
                'telnet_port' => $hsgq['telnet_port'],
            ],
            'hioso' => [
                'enabled'        => $hioso['enabled'],
                'name'           => $hioso['name'],
                'host'           => $hioso['host'],
                'method'         => $hioso['method'],
                'snmp_port'      => $hioso['snmp_port'],
                'snmp_community' => $maskSecret($hioso['snmp_community']),
                'snmp_profile'   => $hioso['snmp_profile'],
                'http_port'      => $hioso['http_port'],
                'telnet_port'    => $hioso['telnet_port'],
                'username'       => $hioso['username'],
                'password'       => $maskSecret($hioso['password']),
                'type'           => $hioso['type'],
                'pon_ports'      => $hioso['pon_ports'],
            ],
        ];
    }
}
