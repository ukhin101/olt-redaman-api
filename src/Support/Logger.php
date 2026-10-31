<?php

namespace OltRedaman\Support;

/**
 * ============================================================================
 * Logger - Pencatat Log Koneksi & Error OLT
 * ============================================================================
 * Menyimpan catatan koneksi, peringatan, dan error ke storage/logs/olt-api.log
 * tanpa pernah mencatat password mentah.
 */
class Logger
{
    private static function getLogFilePath(): string
    {
        $logDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        return $logDir . DIRECTORY_SEPARATOR . 'olt-api-' . date('Y-m-d') . '.log';
    }

    public static function info(string $brand, string $message, array $context = []): void
    {
        self::write('INFO', $brand, $message, $context);
    }

    public static function warning(string $brand, string $message, array $context = []): void
    {
        self::write('WARNING', $brand, $message, $context);
    }

    public static function error(string $brand, string $message, array $context = []): void
    {
        self::write('ERROR', $brand, $message, $context);
    }

    private static function write(string $level, string $brand, string $message, array $context = []): void
    {
        // Pastikan kunci sensitif disamarkan sebelum masuk ke log
        foreach (['password', 'pass', 'community', 'snmp_community', 'token', 'key', 'value'] as $secretKey) {
            if (isset($context[$secretKey])) {
                $context[$secretKey] = '***REDACTED***';
            }
        }

        $timestamp = date('Y-m-d H:i:s');
        $ctxStr = !empty($context) ? ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        $line = "[{$timestamp}] [{$level}] [{$brand}] {$message}{$ctxStr}" . PHP_EOL;

        @file_put_contents(self::getLogFilePath(), $line, FILE_APPEND | LOCK_EX);
    }
}
