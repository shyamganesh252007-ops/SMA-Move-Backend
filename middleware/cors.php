<?php
declare(strict_types=1);

/**
 * SMA-Move — Secure CORS Middleware
 *
 * Implements origin validation against an explicit whitelist without using
 * wildcards (`*`) when credentials or authorization headers are permitted.
 */

namespace SmaMove\Middleware;

require_once dirname(__DIR__) . '/config/environment.php';
require_once dirname(__DIR__) . '/utils/response.php';

use SmaMove\Utils\Response;

class CorsMiddleware
{
    /**
     * Handle incoming CORS requests and preflight OPTIONS.
     */
    public static function handle(): void
    {
        $rawOrigins = (string)env(
            'CORS_ALLOWED_ORIGINS',
            'http://localhost,http://127.0.0.1,http://10.0.2.2,http://172.23.51.176'
        );
        $allowedOrigins = array_filter(array_map('trim', explode(',', $rawOrigins)));

        $allowedMethods = (string)env('CORS_ALLOWED_METHODS', 'GET,POST,PUT,PATCH,DELETE,OPTIONS');
        $allowedHeaders = (string)env('CORS_ALLOWED_HEADERS', 'Content-Type,Authorization,X-Requested-With,Accept');
        $allowCredentials = (bool)env('CORS_ALLOW_CREDENTIALS', true);
        $maxAge = (string)env('CORS_MAX_AGE', '86400');

        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;

        if ($origin !== null) {
            // Check if origin matches whitelist
            if (in_array($origin, $allowedOrigins, true)) {
                header("Access-Control-Allow-Origin: {$origin}");
                header('Vary: Origin');

                if ($allowCredentials) {
                    header('Access-Control-Allow-Credentials: true');
                }
            } else {
                // If this is an unauthorized origin sending an OPTIONS preflight, reject it
                if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
                    Response::forbidden('CORS request rejected: Origin not allowed.');
                }
            }
        }

        header("Access-Control-Allow-Methods: {$allowedMethods}");
        header("Access-Control-Allow-Headers: {$allowedHeaders}");
        header("Access-Control-Max-Age: {$maxAge}");

        // Handle preflight OPTIONS request
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
