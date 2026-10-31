<?php
/**
 * ============================================================================
 * PSR-4 Standalone Autoloader - OLT Redaman API
 * ============================================================================
 * Memungkinkan seluruh class di folder src/ dimuat secara otomatis tanpa
 * mengharuskan pengguna menjalankan "composer install" di XAMPP Windows.
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'OltRedaman\\';
    $baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// Muat konfigurasi .env secara otomatis saat autoload dipanggil
\OltRedaman\Config\EnvLoader::load(__DIR__ . DIRECTORY_SEPARATOR . '.env');

// Atur timezone default sesuai .env
$timezone = \OltRedaman\Config\EnvLoader::get('APP_TIMEZONE', 'Asia/Jakarta');
if (!empty($timezone)) {
    @date_default_timezone_set($timezone);
}
