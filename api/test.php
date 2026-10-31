<?php
/**
 * ============================================================================
 * Endpoint Pengujian Koneksi & Validasi Pembacaan Redaman OLT
 * ============================================================================
 * URL:
 * - Uji kedua OLT + validasi redaman : GET /olt-redaman-api/api/test.php
 * - Uji hanya OLT HSGQ               : GET /olt-redaman-api/api/test.php?brand=hsgq
 * - Uji hanya OLT HIOSO              : GET /olt-redaman-api/api/test.php?brand=hioso
 * - Uji koneksi cepat tanpa baca ONU : GET /olt-redaman-api/api/test.php?validate=0
 */

require_once dirname(__DIR__) . '/autoload.php';

use OltRedaman\OltRedamanManager;
use OltRedaman\Support\CacheManager;
use OltRedaman\Support\ResponseHelper;

ResponseHelper::initApiHeaders();
ResponseHelper::enforceApiKeyIfConfigured();

$brand           = $_GET['brand'] ?? $_POST['brand'] ?? 'all';
$validateReading = !isset($_GET['validate']) || (string)$_GET['validate'] !== '0';

// Jika diminta hapus cache melalui ?clear_cache=1
if (!empty($_GET['clear_cache'])) {
    CacheManager::clear();
}

$manager    = new OltRedamanManager();
$testReport = $manager->testAndValidate((string)$brand, $validateReading);
$httpCode   = !empty($testReport['success']) ? 200 : 503;

ResponseHelper::sendJson($testReport, $httpCode);
