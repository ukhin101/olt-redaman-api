<?php

namespace OltRedaman\Support;

/**
 * ============================================================================
 * NetworkProbe - Pemeriksaan Jaringan Read-Only untuk Diagnostik OLT
 * ============================================================================
 * Menyediakan pemeriksaan:
 *  - ICMP ping (keterjangkauan IP)
 *  - TCP port (HTTP / Telnet)
 *  - ARP lookup (khusus jika OLT berada di subnet LAN yang sama)
 *
 * Seluruh pemeriksaan bersifat pasif/read-only dan tidak mengubah konfigurasi OLT.
 */
class NetworkProbe
{
    /**
     * Ping ICMP ke host. Catatan: beberapa OLT/firewall memblokir ICMP, sehingga
     * hasil "false" tidak selalu berarti OLT mati — status port TCP lebih menentukan.
     */
    public static function ping(string $host, int $timeoutMs = 1000): array
    {
        if (!self::isSafeHost($host) || !function_exists('exec')) {
            return ['reachable' => null, 'latency_ms' => null, 'note' => 'Ping tidak dapat dijalankan pada server ini.'];
        }

        $isWindows = stripos(PHP_OS_FAMILY, 'Windows') !== false;
        $cmd = $isWindows
            ? 'ping -n 1 -w ' . (int)$timeoutMs . ' ' . escapeshellarg($host)
            : 'ping -c 1 -W ' . max(1, (int)ceil($timeoutMs / 1000)) . ' ' . escapeshellarg($host);

        $out = [];
        $rc  = 1;
        @exec($cmd . ' 2>&1', $out, $rc);
        $text = implode("\n", $out);

        $latency = null;
        if (preg_match('/(?:time|waktu)[=<]\s*([\d.]+)\s*ms/i', $text, $m)) {
            $latency = (float)$m[1];
        }

        // Pada Windows, "Destination host unreachable" bisa ber-exit-code 0; cek TTL agar akurat
        $reachable = ($rc === 0) && (stripos($text, 'TTL=') !== false || stripos($text, 'ttl=') !== false);

        return [
            'reachable'  => $reachable,
            'latency_ms' => $reachable ? $latency : null,
            'note'       => $reachable ? 'IP merespons ICMP ping.' : 'IP tidak merespons ICMP ping (bisa karena perangkat mati, IP salah, atau ICMP diblokir).',
        ];
    }

    /**
     * Pemeriksaan port TCP (membuka lalu langsung menutup soket).
     */
    public static function tcp(string $host, int $port, int $timeoutSec = 3): array
    {
        $t0 = microtime(true);
        $fp = @fsockopen($host, $port, $errno, $errstr, max(1, $timeoutSec));
        $ms = round((microtime(true) - $t0) * 1000, 2);

        if ($fp) {
            fclose($fp);
            return ['open' => true, 'latency_ms' => $ms, 'error' => null];
        }

        return [
            'open'       => false,
            'latency_ms' => null,
            'error'      => self::describeSocketError((int)$errno, (string)$errstr, $ms, $timeoutSec),
        ];
    }

    /**
     * Mencari entri ARP untuk host (hanya bermakna jika host berada di subnet LAN yang sama).
     * Tidak adanya entri ARP setelah ping menandakan tidak ada perangkat yang memakai IP tersebut.
     */
    public static function arpLookup(string $host): ?string
    {
        if (!self::isSafeHost($host) || !function_exists('exec') || !filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }
        $out = [];
        @exec('arp -a ' . escapeshellarg($host) . ' 2>&1', $out);
        foreach ($out as $line) {
            if (strpos($line, $host) !== false && preg_match('/([0-9a-f]{2}[:-]){5}[0-9a-f]{2}/i', $line, $m)) {
                return strtoupper(str_replace('-', ':', $m[0]));
            }
        }
        return null;
    }

    private static function describeSocketError(int $errno, string $errstr, float $elapsedMs, int $timeoutSec): string
    {
        // 10061 (Windows) / 111 (Linux) = connection refused: host hidup tetapi port tertutup
        if (in_array($errno, [10061, 111], true)) {
            return "Koneksi ditolak (port tertutup/layanan tidak aktif) [{$errno}] {$errstr}";
        }
        // 10060 / 110 = timeout: host tidak menjawab (mati, IP salah, atau terfilter firewall)
        if (in_array($errno, [10060, 110], true) || $elapsedMs >= ($timeoutSec * 1000) - 50) {
            return "Timeout setelah {$timeoutSec} detik (host tidak menjawab: perangkat mati, IP salah, atau diblokir firewall) [{$errno}] {$errstr}";
        }
        if (in_array($errno, [10065, 113, 10051, 101], true)) {
            return "Rute ke host tidak tersedia (network/host unreachable) [{$errno}] {$errstr}";
        }
        return "[{$errno}] {$errstr}";
    }

    private static function isSafeHost(string $host): bool
    {
        return $host !== '' && (bool)preg_match('/^[A-Za-z0-9.\-:]+$/', $host);
    }
}

