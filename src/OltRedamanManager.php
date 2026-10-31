<?php

namespace OltRedaman;

use OltRedaman\Config\EnvLoader;
use OltRedaman\Drivers\HiosoDriver;
use OltRedaman\Drivers\HsgqDriver;
use OltRedaman\Support\CacheManager;
use OltRedaman\Support\OnuDataNormalizer;

/**
 * ============================================================================
 * OltRedamanManager - Orchestrator / Modul Utama Pengambilan Data Redaman OLT
 * ============================================================================
 * Menyediakan antarmuka terpadu untuk:
 * 1. Mengambil data redaman khusus OLT HSGQ
 * 2. Mengambil data redaman khusus OLT HIOSO
 * 3. Mengambil data redaman gabungan (HSGQ + HIOSO)
 * 4. Menguji koneksi & memvalidasi pembacaan redaman kedua OLT
 * 5. Mencari redaman ONU tertentu berdasarkan SN, MAC Address, atau Nama Pelanggan
 */
class OltRedamanManager
{
    private HsgqDriver $hsgqDriver;
    private HiosoDriver $hiosoDriver;

    public function __construct(array $hsgqOverrides = [], array $hiosoOverrides = [])
    {
        $this->hsgqDriver  = new HsgqDriver($hsgqOverrides);
        $this->hiosoDriver = new HiosoDriver($hiosoOverrides);
    }

    public function getHsgqDriver(): HsgqDriver
    {
        return $this->hsgqDriver;
    }

    public function getHiosoDriver(): HiosoDriver
    {
        return $this->hiosoDriver;
    }

    /**
     * Mengambil data redaman dari OLT HSGQ (dengan dukungan cache otomatis & filter).
     */
    public function getHsgqData(array $filters = [], bool $forceRefresh = false): array
    {
        $cacheKey = 'hsgq_all';

        if (!$forceRefresh) {
            $cached = CacheManager::get($cacheKey);
            if ($cached !== null && isset($cached['data']) && is_array($cached['data'])) {
                $filtered = OnuDataNormalizer::applyFilters($cached['data'], $filters);
                $stats    = OnuDataNormalizer::buildSummaryAndValidation($filtered);

                $cached['data']       = $filtered;
                $cached['summary']    = $stats['summary'];
                $cached['validation'] = $stats['validation'];
                return $cached;
            }
        }

        // Ambil penuh dari OLT (tanpa filter) untuk disimpan ke cache, lalu terapkan filter
        $fullResult = $this->hsgqDriver->fetchOpticalData([]);
        if (!empty($fullResult['success'])) {
            CacheManager::set($cacheKey, $fullResult);
        }

        if (!empty($filters) && !empty($fullResult['data'])) {
            $filtered = OnuDataNormalizer::applyFilters($fullResult['data'], $filters);
            $stats    = OnuDataNormalizer::buildSummaryAndValidation($filtered);

            $fullResult['data']       = $filtered;
            $fullResult['summary']    = $stats['summary'];
            $fullResult['validation'] = $stats['validation'];
        }

        return $fullResult;
    }

    /**
     * Mengambil data redaman dari OLT HIOSO (dengan dukungan cache otomatis & filter).
     */
    public function getHiosoData(array $filters = [], bool $forceRefresh = false): array
    {
        $cacheKey = 'hioso_all';

        if (!$forceRefresh) {
            $cached = CacheManager::get($cacheKey);
            if ($cached !== null && isset($cached['data']) && is_array($cached['data'])) {
                $filtered = OnuDataNormalizer::applyFilters($cached['data'], $filters);
                $stats    = OnuDataNormalizer::buildSummaryAndValidation($filtered);

                $cached['data']       = $filtered;
                $cached['summary']    = $stats['summary'];
                $cached['validation'] = $stats['validation'];
                return $cached;
            }
        }

        $fullResult = $this->hiosoDriver->fetchOpticalData([]);
        if (!empty($fullResult['success'])) {
            CacheManager::set($cacheKey, $fullResult);
        }

        if (!empty($filters) && !empty($fullResult['data'])) {
            $filtered = OnuDataNormalizer::applyFilters($fullResult['data'], $filters);
            $stats    = OnuDataNormalizer::buildSummaryAndValidation($filtered);

            $fullResult['data']       = $filtered;
            $fullResult['summary']    = $stats['summary'];
            $fullResult['validation'] = $stats['validation'];
        }

        return $fullResult;
    }

    /**
     * Mengambil data redaman gabungan dari kedua OLT (HSGQ + HIOSO).
     * Jika salah satu OLT offline/gagal, data dari OLT yang online tetap dikembalikan
     * disertai informasi status per perangkat OLT.
     */
    public function getCombinedData(array $filters = [], bool $forceRefresh = false): array
    {
        $startTime = microtime(true);
        $fetchedAt = date('c');

        $hsgqRes  = $this->getHsgqData($filters, $forceRefresh);
        $hiosoRes = $this->getHiosoData($filters, $forceRefresh);

        $combinedOnus = array_merge(
            $hsgqRes['data'] ?? [],
            $hiosoRes['data'] ?? []
        );

        $stats     = OnuDataNormalizer::buildSummaryAndValidation($combinedOnus);
        $elapsedMs = round((microtime(true) - $startTime) * 1000, 2);

        $anySuccess = !empty($hsgqRes['success']) || !empty($hiosoRes['success']);
        $allSuccess = !empty($hsgqRes['success']) && !empty($hiosoRes['success']);

        $errors = [];
        if (empty($hsgqRes['success'])) {
            $errors['HSGQ'] = $hsgqRes['error'] ?? 'Gagal mengambil data OLT HSGQ';
        }
        if (empty($hiosoRes['success'])) {
            $errors['HIOSO'] = $hiosoRes['error'] ?? 'Gagal mengambil data OLT HIOSO';
        }

        return [
            'success'           => $anySuccess,
            'partial_success'   => $anySuccess && !$allSuccess,
            'fetched_at'        => $fetchedAt,
            'execution_time_ms' => $elapsedMs,
            'olt_sources'       => [
                'hsgq' => [
                    'enabled'           => (bool)$this->hsgqDriver->getConfig()['enabled'],
                    'success'           => (bool)($hsgqRes['success'] ?? false),
                    'olt_name'          => $hsgqRes['olt_name'] ?? $this->hsgqDriver->getName(),
                    'olt_host'          => $hsgqRes['olt_host'] ?? $this->hsgqDriver->getConfig()['host'],
                    'method_used'       => $hsgqRes['method_used'] ?? null,
                    'from_cache'        => (bool)($hsgqRes['from_cache'] ?? false),
                    'onu_count'         => count($hsgqRes['data'] ?? []),
                    'execution_time_ms' => $hsgqRes['execution_time_ms'] ?? 0.0,
                    'error'             => $hsgqRes['error'] ?? null,
                ],
                'hioso' => [
                    'enabled'           => (bool)$this->hiosoDriver->getConfig()['enabled'],
                    'success'           => (bool)($hiosoRes['success'] ?? false),
                    'olt_name'          => $hiosoRes['olt_name'] ?? $this->hiosoDriver->getName(),
                    'olt_host'          => $hiosoRes['olt_host'] ?? $this->hiosoDriver->getConfig()['host'],
                    'method_used'       => $hiosoRes['method_used'] ?? null,
                    'from_cache'        => (bool)($hiosoRes['from_cache'] ?? false),
                    'onu_count'         => count($hiosoRes['data'] ?? []),
                    'execution_time_ms' => $hiosoRes['execution_time_ms'] ?? 0.0,
                    'error'             => $hiosoRes['error'] ?? null,
                ],
            ],
            'errors'            => !empty($errors) ? $errors : null,
            'summary'           => $stats['summary'],
            'validation'        => $stats['validation'],
            'data'              => $combinedOnus,
        ];
    }

    /**
     * Menguji koneksi ke OLT dan memvalidasi hasil pembacaan redaman.
     *
     * @param string $brand 'hsgq', 'hioso', atau 'all'
     * @param bool   $validateReading Apakah sekaligus memvalidasi pembacaan sampel redaman
     */
    public function testAndValidate(string $brand = 'all', bool $validateReading = true): array
    {
        $brand     = strtolower(trim($brand));
        $startTime = microtime(true);
        $results   = [];

        if ($brand === 'all' || $brand === 'hsgq') {
            $connTest = $this->hsgqDriver->testConnection();
            if ($validateReading && !empty($connTest['success'])) {
                $readData = $this->hsgqDriver->fetchOpticalData([]);
                $connTest['reading_validation'] = $readData['validation'] ?? null;
                $connTest['summary']            = $readData['summary'] ?? null;
                $connTest['sample_onu']         = $readData['data'][0] ?? null;
            }
            $results['hsgq'] = $connTest;
        }

        if ($brand === 'all' || $brand === 'hioso') {
            $connTest = $this->hiosoDriver->testConnection();
            if ($validateReading && !empty($connTest['success'])) {
                $readData = $this->hiosoDriver->fetchOpticalData([]);
                $connTest['reading_validation'] = $readData['validation'] ?? null;
                $connTest['summary']            = $readData['summary'] ?? null;
                $connTest['sample_onu']         = $readData['data'][0] ?? null;
            }
            $results['hioso'] = $connTest;
        }

        $overallSuccess = false;
        foreach ($results as $r) {
            if (!empty($r['success'])) {
                $overallSuccess = true;
            }
        }

        return [
            'success'           => $overallSuccess,
            'tested_brand'      => $brand,
            'checked_at'        => date('c'),
            'execution_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
            'config_summary'    => EnvLoader::getSafeConfigSummary(),
            'results'           => $results,
        ];
    }

    /**
     * Mencari satu ONU spesifik berdasarkan SN, MAC Address, atau Nama ONU/Pelanggan.
     * Sangat berguna saat diintegrasikan dengan titik marker ONU di Monitoring Map FTTH.
     */
    public function findOnu(string $identifier, string $brand = 'all', bool $forceRefresh = false): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        $brand = strtolower(trim($brand));
        if ($brand === 'hsgq') {
            $dataset = $this->getHsgqData([], $forceRefresh)['data'] ?? [];
        } elseif ($brand === 'hioso') {
            $dataset = $this->getHiosoData([], $forceRefresh)['data'] ?? [];
        } else {
            $dataset = $this->getCombinedData([], $forceRefresh)['data'] ?? [];
        }

        $cleanTarget = strtolower(str_replace([':', '-', ' '], '', $identifier));
        $lowerTarget = strtolower($identifier);

        // Prioritas 1: Cocok persis pada SN / MAC / ONU Name / ONU ID
        foreach ($dataset as $onu) {
            $cleanSn = strtolower(str_replace([':', '-', ' '], '', (string)($onu['sn'] ?? '')));
            if ($cleanSn !== '' && $cleanSn === $cleanTarget) {
                return $onu;
            }
            if (strtolower((string)($onu['onu_name'] ?? '')) === $lowerTarget) {
                return $onu;
            }
            if (strtolower((string)($onu['onu_id'] ?? '')) === $lowerTarget) {
                return $onu;
            }
        }

        // Prioritas 2: Cocok sebagian (substring)
        foreach ($dataset as $onu) {
            $cleanSn = strtolower(str_replace([':', '-', ' '], '', (string)($onu['sn'] ?? '')));
            if ($cleanSn !== '' && strpos($cleanSn, $cleanTarget) !== false) {
                return $onu;
            }
            if (strpos(strtolower((string)($onu['onu_name'] ?? '')), $lowerTarget) !== false) {
                return $onu;
            }
        }

        return null;
    }
}
