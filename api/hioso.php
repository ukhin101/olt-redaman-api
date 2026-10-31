<?php
/**
 * ============================================================================
 * Endpoint OLT HIOSO - Pengambilan Data Redaman Optik & Tes Koneksi
 * ============================================================================
 * URL:
 * - Ambil semua ONU HIOSO: GET /olt-redaman-api/api/hioso.php
 * - Ambil paksa real-time: GET /olt-redaman-api/api/hioso.php?refresh=1
 * - Filter Port PON      : GET /olt-redaman-api/api/hioso.php?pon_port=1
 * - Filter Status Online : GET /olt-redaman-api/api/hioso.php?status=online
 * - Cari Nama / SN ONU   : GET /olt-redaman-api/api/hioso.php?search=00:11
 * - Tes Koneksi HIOSO    : GET /olt-redaman-api/api/hioso.php?action=test
 */

require_once dirname(__DIR__) . '/autoload.php';

use OltRedaman\OltRedamanManager;
use OltRedaman\Support\ResponseHelper;

ResponseHelper::initApiHeaders();
ResponseHelper::enforceApiKeyIfConfigured();

$params  = ResponseHelper::getRequestParams();
$manager = new OltRedamanManager();

// Mode 1: Uji koneksi ke OLT HIOSO
if (strtolower((string)$params['action']) === 'test') {
    $testResult = $manager->getHiosoDriver()->testConnection();
    $httpCode   = !empty($testResult['success']) ? 200 : 503;
    ResponseHelper::sendJson($testResult, $httpCode);
}

// Mode 2: Cari 1 ONU spesifik pada OLT HIOSO jika parameter ?onu= diberikan
if (!empty($_GET['onu'])) {
    $onu = $manager->findOnu((string)$_GET['onu'], 'hioso', (bool)$params['refresh']);
    if ($onu !== null) {
        ResponseHelper::sendJson([
            'success'    => true,
            'brand'      => 'HIOSO',
            'fetched_at' => date('c'),
            'data'       => $onu,
        ], 200);
    }
    ResponseHelper::sendJson([
        'success' => false,
        'brand'   => 'HIOSO',
        'error'   => 'ONU dengan identitas "' . $_GET['onu'] . '" tidak ditemukan pada OLT HIOSO.',
        'data'    => null,
    ], 404);
}

// Mode 3: Ambil daftar lengkap data redaman OLT HIOSO
$result   = $manager->getHiosoData($params, (bool)$params['refresh']);
$httpCode = !empty($result['success']) ? 200 : 503;

ResponseHelper::sendJson($result, $httpCode);
