<?php
/**
 * ============================================================================
 * CLI Diagnostic, Self-Test & Connection Validator - OLT Redaman API
 * ============================================================================
 * Cara Menjalankan di Terminal / CMD Windows XAMPP:
 *   C:\xampp\php\php.exe cli\test-olt.php
 *   C:\xampp\php\php.exe cli\test-olt.php hsgq
 *   C:\xampp\php\php.exe cli\test-olt.php hioso
 *   C:\xampp\php\php.exe cli\test-olt.php --self-test
 */

require_once dirname(__DIR__) . '/autoload.php';

use OltRedaman\Config\EnvLoader;
use OltRedaman\OltRedamanManager;
use OltRedaman\Support\OnuDataNormalizer;

$arg = strtolower(trim($argv[1] ?? 'all'));

echo "============================================================================" . PHP_EOL;
echo "   OLT REDAMAN API (HSGQ & HIOSO) - PENGUJIAN KONEKSI & VALIDASI REDAMAN    " . PHP_EOL;
echo "============================================================================" . PHP_EOL;
echo "Waktu Pengujian : " . date('Y-m-d H:i:s T') . PHP_EOL;
echo "Versi PHP       : " . PHP_VERSION . " (" . PHP_SAPI . ")" . PHP_EOL;
echo "Ekstensi cURL   : " . (extension_loaded('curl') ? '[OK] Aktif' : '[GAGAL] Tidak Aktif') . PHP_EOL;
echo "Ekstensi SNMP   : " . (extension_loaded('snmp') ? '[OK] Aktif (Native PHP SNMP)' : '[INFO] Tidak Aktif (Menggunakan Fallback CLI/HTTP/Telnet)') . PHP_EOL;
echo "File .env       : " . (EnvLoader::isEnvFilePresent() ? '[OK] Ditemukan (.env)' : '[PERINGATAN] Belum ada file .env (menggunakan nilai default)') . PHP_EOL;
echo "----------------------------------------------------------------------------" . PHP_EOL;

// 1. Validasi Internal Parser & Normalizer Redaman (Self-Test)
echo PHP_EOL . "[1/2] Menjalankan Validasi Internal Parser Redaman Optik..." . PHP_EOL;

$selfTests = [
    [
        'label'    => 'HSGQ Float String (-22.5181 -> -22.52 dBm)',
        'actual'   => OnuDataNormalizer::parseOpticalPower('-22.5181', false),
        'expected' => -22.52,
    ],
    [
        'label'    => 'HSGQ 16-bit Signed Integer (63284 -> -22.52 dBm)',
        'actual'   => OnuDataNormalizer::parseOpticalPower(63284, false),
        'expected' => -22.52,
    ],
    [
        'label'    => 'HIOSO SNMP String ("-19.40 dBm" -> -19.4 dBm)',
        'actual'   => OnuDataNormalizer::parseOpticalPower('STRING: "-19.40 dBm"', false),
        'expected' => -19.4,
    ],
    [
        'label'    => 'HIOSO GPON Scaled Integer (-2485 -> -24.85 dBm)',
        'actual'   => OnuDataNormalizer::parseOpticalPower('-2485', false),
        'expected' => -24.85,
    ],
    [
        'label'    => 'Tx Power Valid ("2.15" -> 2.15 dBm)',
        'actual'   => OnuDataNormalizer::parseOpticalPower('2.15', true),
        'expected' => 2.15,
    ],
    [
        'label'    => 'Offline / Sentinel ("---" -> null)',
        'actual'   => OnuDataNormalizer::parseOpticalPower('---', false),
        'expected' => null,
    ],
];

$allSelfPassed = true;
foreach ($selfTests as $st) {
    $passed = ($st['actual'] === $st['expected']);
    if (!$passed) {
        $allSelfPassed = false;
    }
    $icon = $passed ? '[LULUS]' : '[GAGAL]';
    echo "  {$icon} {$st['label']}" . PHP_EOL;
}

if ($arg === '--self-test') {
    echo PHP_EOL . ($allSelfPassed ? "Hasil Self-Test: 100% LULUS." : "Hasil Self-Test: ADA KEGAGALAN.") . PHP_EOL;
    exit($allSelfPassed ? 0 : 1);
}

// 2. Uji Koneksi ke Perangkat OLT sesuai konfigurasi .env
echo PHP_EOL . "[2/2] Menguji Koneksi ke Perangkat OLT (" . strtoupper($arg) . ")..." . PHP_EOL;

$manager = new OltRedamanManager();
$report  = $manager->testAndValidate($arg, true);

foreach ($report['results'] as $brandKey => $res) {
    echo PHP_EOL . ">>> OLT " . strtoupper($brandKey) . " (" . ($res['olt_name'] ?? '-') . " @ " . ($res['host'] ?? '-') . ")" . PHP_EOL;
    echo "    Status Koneksi : " . (!empty($res['success']) ? '[TERHUBUNG]' : '[TIDAK DAPAT DIAKSES]') . PHP_EOL;
    echo "    Metode         : " . ($res['method_used'] ?? '-') . PHP_EOL;
    if (isset($res['latency_ms']) && $res['latency_ms'] !== null) {
        echo "    Latensi        : " . $res['latency_ms'] . " ms" . PHP_EOL;
    }
    echo "    Pesan          : " . ($res['message'] ?? '-') . PHP_EOL;

    if (!empty($res['reading_validation'])) {
        $val = $res['reading_validation'];
        echo "    Validasi Data  : " . ($val['validation_message'] ?? '-') . PHP_EOL;
        echo "    Total ONU      : " . ($val['total_records_read'] ?? 0) . " ONU" . PHP_EOL;
        echo "    Rx Terbaca     : " . ($val['valid_rx_readings'] ?? 0) . " ONU" . PHP_EOL;
        echo "    Tx Terbaca     : " . ($val['valid_tx_readings'] ?? 0) . " ONU" . PHP_EOL;
    }

    if (!empty($res['sample_onu'])) {
        $s = $res['sample_onu'];
        echo "    Sampel ONU #1  : [{$s['onu_id']}] {$s['onu_name']} | SN: {$s['sn']} | Status: {$s['status_label']} | Rx: " . ($s['rx_power_formatted'] ?? 'N/A') . " | Tx: " . ($s['tx_power_formatted'] ?? 'N/A') . PHP_EOL;
    }

    if (empty($res['success']) && !empty($res['diagnostics']['hints'])) {
        echo "    Panduan Solusi :" . PHP_EOL;
        foreach ($res['diagnostics']['hints'] as $hint) {
            echo "      - {$hint}" . PHP_EOL;
        }
    }
}

echo PHP_EOL . "============================================================================" . PHP_EOL;
echo "Selesai dalam " . $report['execution_time_ms'] . " ms." . PHP_EOL;
