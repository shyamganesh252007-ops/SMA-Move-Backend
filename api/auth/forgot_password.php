<?php
declare(strict_types=1);

/**
 * SMA-Move — Forgot Password Request Endpoint
 *
 * POST /api/auth/forgot_password.php
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/middleware/cors.php';
require_once dirname(dirname(__DIR__)) . '/middleware/request_validation.php';
require_once dirname(dirname(__DIR__)) . '/services/AuthService.php';
require_once dirname(dirname(__DIR__)) . '/utils/response.php';

use SmaMove\Middleware\CorsMiddleware;
use SmaMove\Middleware\RequestValidator;
use SmaMove\Services\AuthService;
use SmaMove\Utils\Response;

// Handle CORS
CorsMiddleware::handle();

// Enforce HTTP method
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::methodNotAllowed('Only POST requests are allowed.');
}

// Validate input rules
$rules = [
    'email' => ['required', 'email']
];

$input = RequestValidator::validate($rules);

try {
    $result = AuthService::requestPasswordReset($input['email']);
    Response::success($result, $result['message']);
} catch (\RuntimeException $e) {
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
    Response::error($e->getMessage(), $code);
} catch (\Throwable $e) {
    error_log("[Forgot Password Error] " . $e->getMessage());
    Response::serverError('Unable to process password reset request.');
}
