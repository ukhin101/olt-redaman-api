<?php
/**
 * ============================================================================
 * Endpoint OLT HSGQ - Pengambilan Data Redaman Optik & Tes Koneksi
 * ============================================================================
 * URL:
 * - Ambil semua ONU HSGQ : GET /olt-redaman-api/api/hsgq.php
 * - Ambil paksa real-time: GET /olt-redaman-api/api/hsgq.php?refresh=1
 * - Filter Port PON      : GET /olt-redaman-api/api/hsgq.php?pon_port=1
 * - Filter Status Online : GET /olt-redaman-api/api/hsgq.php?status=online
 * - Cari Nama / SN ONU   : GET /olt-redaman-api/api/hsgq.php?search=LITA
 * - Tes Koneksi HSGQ     : GET /olt-redaman-api/api/hsgq.php?action=test
 */

require_once dirname(__DIR__) . '/autoload.php';

use OltRedaman\OltRedamanManager;
use OltRedaman\Support\ResponseHelper;

ResponseHelper::initApiHeaders();
ResponseHelper::enforceApiKeyIfConfigured();

$params  = ResponseHelper::getRequestParams();
$manager = new OltRedamanManager();

// Mode 1: Uji koneksi ke OLT HSGQ
if (strtolower((string)$params['action']) === 'test') {
    $testResult = $manager->getHsgqDriver()->testConnection();
    $httpCode   = !empty($testResult['success']) ? 200 : 503;
    ResponseHelper::sendJson($testResult, $httpCode);
}

// Mode 2: Cari 1 ONU spesifik pada OLT HSGQ jika parameter ?onu= diberikan
if (!empty($_GET['onu'])) {
    $onu = $manager->findOnu((string)$_GET['onu'], 'hsgq', (bool)$params['refresh']);
    if ($onu !== null) {
        ResponseHelper::sendJson([
            'success'    => true,
            'brand'      => 'HSGQ',
            'fetched_at' => date('c'),
            'data'       => $onu,
        ], 200);
    }
    ResponseHelper::sendJson([
        'success' => false,
        'brand'   => 'HSGQ',
        'error'   => 'ONU dengan identitas "' . $_GET['onu'] . '" tidak ditemukan pada OLT HSGQ.',
        'data'    => null,
    ], 404);
}

// Mode 3: Ambil daftar lengkap data redaman OLT HSGQ
$result   = $manager->getHsgqData($params, (bool)$params['refresh']);
$httpCode = !empty($result['success']) ? 200 : 503;

ResponseHelper::sendJson($result, $httpCode);

