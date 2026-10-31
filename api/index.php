<?php
/**
 * ============================================================================
 * Endpoint Indeks & Status Layanan - OLT Redaman API
 * ============================================================================
 * URL: http://localhost/olt-redaman-api/api/
 *      http://localhost/olt-redaman-api/api/index.php
 */

require_once dirname(__DIR__) . '/autoload.php';

use OltRedaman\Config\EnvLoader;
use OltRedaman\Support\ResponseHelper;

ResponseHelper::initApiHeaders();
ResponseHelper::enforceApiKeyIfConfigured();

$baseUrl = '';
if (isset($_SERVER['HTTP_HOST'])) {
    $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $dirPath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    $baseUrl = "{$scheme}://{$_SERVER['HTTP_HOST']}{$dirPath}";
}

ResponseHelper::sendJson([
    'success'     => true,
    'service'     => EnvLoader::get('APP_NAME', 'OLT Redaman API'),
    'version'     => '1.0.0',
    'description' => 'Backend REST API modular untuk mengambil data redaman optik (Rx/Tx Power) ONT/ONU dari OLT HSGQ dan HIOSO.',
    'timestamp'   => date('c'),
    'config'      => EnvLoader::getSafeConfigSummary(),
    'endpoints'   => [
        'hsgq_optical_data' => [
            'method'      => 'GET',
            'url'         => "{$baseUrl}/hsgq.php",
            'description' => 'Mengambil data redaman optik seluruh ONU dari OLT HSGQ.',
            'query_params'=> [
                'pon_port'  => 'Filter nomor port PON (misal: 1, 2, 3)',
                'status'    => 'Filter status ONU (online / offline)',
                'sn'        => 'Filter berdasarkan SN atau MAC Address ONU',
                'search'    => 'Pencarian bebas (nama ONU, SN, port, model)',
                'weak_only' => 'Set 1 untuk hanya menampilkan sinyal lemah (< -26 dBm)',
                'refresh'   => 'Set 1 untuk bypass cache dan baca langsung dari OLT',
                'action'    => 'Set "test" untuk menguji koneksi OLT HSGQ',
            ],
        ],
        'hioso_optical_data' => [
            'method'      => 'GET',
            'url'         => "{$baseUrl}/hioso.php",
            'description' => 'Mengambil data redaman optik seluruh ONU dari OLT HIOSO.',
            'query_params'=> [
                'pon_port'  => 'Filter nomor port PON (misal: 1, 2, 3)',
                'status'    => 'Filter status ONU (online / offline)',
                'sn'        => 'Filter berdasarkan SN atau MAC Address ONU',
                'search'    => 'Pencarian bebas (nama ONU, SN, port)',
                'weak_only' => 'Set 1 untuk hanya menampilkan sinyal lemah (< -26 dBm)',
                'refresh'   => 'Set 1 untuk bypass cache dan baca langsung dari OLT',
                'action'    => 'Set "test" untuk menguji koneksi OLT HIOSO',
            ],
        ],
        'combined_optical_data' => [
            'method'      => 'GET',
            'url'         => "{$baseUrl}/all.php",
            'description' => 'Mengambil data redaman optik gabungan dari kedua OLT (HSGQ + HIOSO).',
            'query_params'=> [
                'onu'       => 'Cari 1 ONU spesifik berdasarkan SN/MAC/Nama di seluruh OLT',
                'pon_port'  => 'Filter nomor port PON',
                'status'    => 'Filter status ONU (online / offline)',
                'search'    => 'Pencarian bebas (nama ONU, SN, port, merek OLT)',
                'weak_only' => 'Set 1 untuk hanya menampilkan sinyal lemah',
                'refresh'   => 'Set 1 untuk bypass cache dan baca langsung dari OLT',
            ],
        ],
        'connection_test_and_validation' => [
            'method'      => 'GET / POST',
            'url'         => "{$baseUrl}/test.php",
            'description' => 'Menguji koneksi ke OLT HSGQ & HIOSO sekaligus memvalidasi hasil pembacaan redaman.',
            'query_params'=> [
                'brand'    => 'all (default), hsgq, atau hioso',
                'validate' => '1 (default) untuk memvalidasi pembacaan redaman, 0 untuk tes port/login saja',
            ],
        ],
    ],
]);
