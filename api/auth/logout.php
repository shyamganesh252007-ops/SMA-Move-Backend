<?php
declare(strict_types=1);

/**
 * SMA-Move — User Logout & Session Revocation Endpoint
 *
 * POST /api/auth/logout.php
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/middleware/cors.php';
require_once dirname(dirname(__DIR__)) . '/middleware/auth_middleware.php';
require_once dirname(dirname(__DIR__)) . '/services/AuthService.php';
require_once dirname(dirname(__DIR__)) . '/utils/response.php';

use SmaMove\Middleware\CorsMiddleware;
use SmaMove\Middleware\AuthMiddleware;
use SmaMove\Services\AuthService;
use SmaMove\Utils\Response;

// Handle CORS
CorsMiddleware::handle();

// Enforce HTTP method
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::methodNotAllowed('Only POST requests are allowed.');
}

// Authenticate caller
$authUser = AuthMiddleware::authenticate();

// Extract raw token from Authorization header using robust resolver
$authHeader = AuthMiddleware::getAuthorizationHeader() ?? '';
$plainToken = trim(substr($authHeader, 7));

try {
    AuthService::logout($plainToken);
    Response::success(null, 'Successfully logged out. Authentication token revoked.');
} catch (\Throwable $e) {
    error_log("[Logout Error] " . $e->getMessage());
    Response::serverError('Unable to process logout.');
}
