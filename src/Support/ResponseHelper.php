<?php

namespace OltRedaman\Support;

use OltRedaman\Config\EnvLoader;

/**
 * ============================================================================
 * ResponseHelper - Standarisasi HTTP JSON Response & Keamanan Endpoint API
 * ============================================================================
 */
class ResponseHelper
{
    /**
     * Mengatur header standar CORS & JSON, serta menangani preflight OPTIONS.
     */
    public static function initApiHeaders(): void
    {
        if (php_sapi_name() === 'cli') {
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-Requested-With');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        if (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }

    /**
     * Memeriksa API_KEY jika diaktifkan di dalam file .env.
     */
    public static function enforceApiKeyIfConfigured(): void
    {
        $configuredKey = trim((string)EnvLoader::get('API_KEY', ''));
        if ($configuredKey === '') {
            return;
        }

        $providedKey = '';
        if (!empty($_SERVER['HTTP_X_API_KEY'])) {
            $providedKey = trim((string)$_SERVER['HTTP_X_API_KEY']);
        } elseif (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = trim((string)$_SERVER['HTTP_AUTHORIZATION']);
            if (stripos($auth, 'Bearer ') === 0) {
                $providedKey = trim(substr($auth, 7));
            }
        } elseif (isset($_GET['api_key'])) {
            $providedKey = trim((string)$_GET['api_key']);
        } elseif (isset($_POST['api_key'])) {
            $providedKey = trim((string)$_POST['api_key']);
        }

        if (!hash_equals($configuredKey, $providedKey)) {
            self::sendJson([
                'success' => false,
                'error'   => 'Unauthorized: API Key tidak valid atau belum disertakan.',
                'hint'    => 'Kirim header X-API-Key atau parameter ?api_key= sesuai pengaturan di .env',
            ], 401);
        }
    }

    /**
     * Mengambil parameter filter dan opsi request dari query string / JSON body.
     */
    public static function getRequestParams(): array
    {
        $params = $_GET;

        if (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'POST') {
            $rawBody = file_get_contents('php://input');
            if (!empty($rawBody)) {
                $jsonBody = json_decode($rawBody, true);
                if (is_array($jsonBody)) {
                    $params = array_merge($params, $jsonBody);
                }
            }
            $params = array_merge($params, $_POST);
        }

        return [
            'pon_port'       => $params['pon_port'] ?? $params['port'] ?? null,
            'status'         => $params['status'] ?? null,
            'sn'             => $params['sn'] ?? $params['mac'] ?? null,
            'search'         => $params['search'] ?? $params['q'] ?? null,
            'weak_only'      => !empty($params['weak_only']) || !empty($params['weak_signal']),
            'weak_threshold' => $params['weak_threshold'] ?? null,
            'refresh'        => !empty($params['refresh']) || !empty($params['nocache']),
            'action'         => $params['action'] ?? 'fetch',
        ];
    }

    /**
     * Mengirim output JSON dan menghentikan eksekusi script.
     */
    public static function sendJson(array $payload, int $statusCode = 200): void
    {
        if (php_sapi_name() !== 'cli' && !headers_sent()) {
            http_response_code($statusCode);
        }

        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

