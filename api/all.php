<?php
/**
 * ============================================================================
 * Endpoint Gabungan (HSGQ + HIOSO) - Pengambilan Data Redaman Optik Terpadu
 * ============================================================================
 * URL:
 * - Ambil semua ONU dari kedua OLT : GET /olt-redaman-api/api/all.php
 * - Ambil real-time tanpa cache    : GET /olt-redaman-api/api/all.php?refresh=1
 * - Cari 1 ONU (SN / MAC / Nama)   : GET /olt-redaman-api/api/all.php?onu=LITA
 * - Filter Sinyal Lemah (< -26dBm) : GET /olt-redaman-api/api/all.php?weak_only=1
 * - Filter Status Online           : GET /olt-redaman-api/api/all.php?status=online
 */

require_once dirname(__DIR__) . '/autoload.php';

use OltRedaman\OltRedamanManager;
use OltRedaman\Support\ResponseHelper;

ResponseHelper::initApiHeaders();
ResponseHelper::enforceApiKeyIfConfigured();

$params  = ResponseHelper::getRequestParams();
$manager = new OltRedamanManager();

// Jika pengguna mencari satu ONU spesifik berdasarkan SN / MAC / Nama di kedua OLT
if (!empty($_GET['onu'])) {
    $target = (string)$_GET['onu'];
    $onu    = $manager->findOnu($target, 'all', (bool)$params['refresh']);

    if ($onu !== null) {
        ResponseHelper::sendJson([
            'success'    => true,
            'query'      => $target,
            'fetched_at' => date('c'),
            'data'       => $onu,
        ], 200);
    }

    ResponseHelper::sendJson([
        'success' => false,
        'query'   => $target,
        'error'   => "ONU dengan identitas '{$target}' tidak ditemukan di OLT HSGQ maupun HIOSO.",
        'data'    => null,
    ], 404);
}

$result   = $manager->getCombinedData($params, (bool)$params['refresh']);
$httpCode = !empty($result['success']) ? 200 : 503;

ResponseHelper::sendJson($result, $httpCode);
