<?php

namespace OltRedaman\Support;

use OltRedaman\Config\EnvLoader;

/**
 * ============================================================================
 * CacheManager - Pengelola Cache Pembacaan Redaman OLT
 * ============================================================================
 * Mencegah perangkat OLT mengalami beban berlebih (CPU spike / session lock)
 * ketika aplikasi Monitoring Map melakukan polling secara berulang dalam waktu singkat.
 */
class CacheManager
{
    private static function getCacheDir(): string
    {
        $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private static function getFilePath(string $key): string
    {
        $safeKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($key));
        return self::getCacheDir() . DIRECTORY_SEPARATOR . "olt_cache_{$safeKey}.json";
    }

    /**
     * Mengambil data dari cache jika masih berlaku (belum melewati TTL).
     */
    public static function get(string $key, ?int $customTtl = null): ?array
    {
        if (!EnvLoader::getBool('CACHE_ENABLED', true)) {
            return null;
        }

        $file = self::getFilePath($key);
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        $ttl = $customTtl ?? EnvLoader::getInt('CACHE_TTL_SECONDS', 30);
        if ($ttl <= 0) {
            return null;
        }

        $mtime = @filemtime($file);
        if ($mtime === false || (time() - $mtime) > $ttl) {
            return null;
        }

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $decoded['from_cache'] = true;
        $decoded['cache_age_seconds'] = time() - $mtime;
        $decoded['cache_ttl_seconds'] = $ttl;

        return $decoded;
    }

    /**
     * Menyimpan hasil pembacaan OLT ke dalam file cache JSON.
     */
    public static function set(string $key, array $data): bool
    {
        if (!EnvLoader::getBool('CACHE_ENABLED', true)) {
            return false;
        }

        // Hanya simpan ke cache jika pengambilan data berhasil
        if (empty($data['success'])) {
            return false;
        }

        $file = self::getFilePath($key);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return @file_put_contents($file, $json, LOCK_EX) !== false;
    }

    /**
     * Menghapus cache tertentu atau seluruh cache OLT.
     */
    public static function clear(?string $key = null): void
    {
        if ($key !== null) {
            $file = self::getFilePath($key);
            if (is_file($file)) {
                @unlink($file);
            }
            return;
        }

        $files = glob(self::getCacheDir() . DIRECTORY_SEPARATOR . 'olt_cache_*.json');
        if (is_array($files)) {
            foreach ($files as $f) {
                @unlink($f);
            }
        }
    }
}

