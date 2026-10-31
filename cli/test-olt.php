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

// 1. Validasi Internal Parser & Uji Mandiri Modul HSGQ + HIOSO (Self-Test)
echo PHP_EOL . "[1/2] Menjalankan Pengujian Mandiri Modul HSGQ, HIOSO & Normalizer..." . PHP_EOL;

$hsgqDriver  = new \OltRedaman\Drivers\HsgqDriver();
$hiosoDriver = new \OltRedaman\Drivers\HiosoDriver();

// Simulasi uji mandiri modul HSGQ (Telnet CLI parser + Normalizer)
$refHsgqTelnet = new \ReflectionMethod($hsgqDriver, 'parseTelnetOutput');
$refHsgqTelnet->setAccessible(true);
$hsgqMockRecords = $refHsgqTelnet->invoke(
    $hsgqDriver,
    "1/1   00:11:41:24:11:da   Online   -17.85   2.10   LITA\n1/2   98:45:62:c5:84:c3   Offline   ---   ---   BUDI\n",
    date('c')
);

// Simulasi uji mandiri modul HIOSO (HTML ASP parser + Telnet CLI parser)
$refHiosoHtml = new \ReflectionMethod($hiosoDriver, 'parseHtmlOnuRows');
$refHiosoHtml->setAccessible(true);
$hiosoHtmlMap = [];
$refHiosoHtml->invokeArgs($hiosoDriver, [
    'var onuList = new Array("0/1/1:1", "PELANGGAN_A", "00:11:22:33:44:55", "Online", "-19.45", "1.95");',
    &$hiosoHtmlMap,
]);

$refHiosoTelnet = new \ReflectionMethod($hiosoDriver, 'parseTelnetOutput');
$refHiosoTelnet->setAccessible(true);
$hiosoTelnetRecords = $refHiosoTelnet->invoke(
    $hiosoDriver,
    "0/1/2:3   AA:BB:CC:DD:EE:FF   Online   -21.30   2.05   WARUNG_KOPI\n",
    date('c')
);

// Uji Filter ResponseHelper
$filteredWeak = \OltRedaman\Support\ResponseHelper::applyFilters($hsgqMockRecords, ['status' => 'online']);

$selfTests = [
    [
        'label'    => 'Parser HSGQ Float String (-22.5181 -> -22.52 dBm)',
        'actual'   => OnuDataNormalizer::parseOpticalPower('-22.5181', false),
        'expected' => -22.52,
    ],
    [
        'label'    => 'Parser HSGQ 16-bit Signed Integer (63284 -> -22.52 dBm)',
        'actual'   => OnuDataNormalizer::parseOpticalPower(63284, false),
        'expected' => -22.52,
    ],
    [
        'label'    => 'Parser HIOSO SNMP String ("-19.40 dBm" -> -19.4 dBm)',
        'actual'   => OnuDataNormalizer::parseOpticalPower('STRING: "-19.40 dBm"', false),
        'expected' => -19.4,
    ],
    [
        'label'    => 'Parser HIOSO GPON Scaled Integer (-2485 -> -24.85 dBm)',
        'actual'   => OnuDataNormalizer::parseOpticalPower('-2485', false),
        'expected' => -24.85,
    ],
    [
        'label'    => 'Parser Tx Power Valid ("2.15" -> 2.15 dBm)',
        'actual'   => OnuDataNormalizer::parseOpticalPower('2.15', true),
        'expected' => 2.15,
    ],
    [
        'label'    => 'Parser Offline / Sentinel ("---" -> null)',
        'actual'   => OnuDataNormalizer::parseOpticalPower('---', false),
        'expected' => null,
    ],
    [
        'label'    => 'Modul Mandiri HSGQDriver (Ekstraksi & Normalisasi ONU Online/Offline)',
        'actual'   => isset($hsgqMockRecords[0]) && $hsgqMockRecords[0]['olt_brand'] === 'HSGQ' && $hsgqMockRecords[0]['rx_power'] === -17.85 && $hsgqMockRecords[1]['status'] === 'offline',
        'expected' => true,
    ],
    [
        'label'    => 'Modul Mandiri HIOSODriver - Web ASP Parser (0/1/1:1 -> -19.45 dBm)',
        'actual'   => isset($hiosoHtmlMap['0/1/1:1']) && $hiosoHtmlMap['0/1/1:1']['rx_power'] === -19.45,
        'expected' => true,
    ],
    [
        'label'    => 'Modul Mandiri HIOSODriver - CLI Parser (0/1/2:3 -> -21.3 dBm)',
        'actual'   => isset($hiosoTelnetRecords[0]) && $hiosoTelnetRecords[0]['olt_brand'] === 'HIOSO' && $hiosoTelnetRecords[0]['rx_power'] === -21.3,
        'expected' => true,
    ],
    [
        'label'    => 'Filter API & SDK Client (Filter Status Online)',
        'actual'   => count($filteredWeak) === 1 && $filteredWeak[0]['onu_id'] === '1/1',
        'expected' => true,
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

