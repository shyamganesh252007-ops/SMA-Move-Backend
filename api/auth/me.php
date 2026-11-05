<?php
declare(strict_types=1);

/**
 * SMA-Move — Current Authenticated User Endpoint
 *
 * GET /api/auth/me.php
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
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Response::methodNotAllowed('Only GET requests are allowed.');
}

// Authenticate caller
$authUser = AuthMiddleware::authenticate();

try {
    $userProfile = AuthService::me((int)$authUser['id']);

    if ($userProfile === null) {
        Response::notFound('User profile not found.');
    }

    Response::success($userProfile, 'User profile retrieved successfully.');
} catch (\Throwable $e) {
    error_log("[Me Endpoint Error] " . $e->getMessage());
    Response::serverError('Unable to retrieve user information.');
}
