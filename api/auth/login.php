<?php
declare(strict_types=1);

/**
 * SMA-Move — User Authentication & Login Endpoint
 *
 * POST /api/auth/login.php
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
    'email' => ['required', 'email'],
    'password' => ['required']
];

$input = RequestValidator::validate($rules);

try {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    $result = AuthService::login($input['email'], $input['password'], $userAgent, $ip);
    Response::success($result, 'Authentication successful.');
} catch (\RuntimeException $e) {
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 401;
    Response::error($e->getMessage(), $code);
} catch (\Throwable $e) {
    error_log("[Login Error] " . $e->getMessage());
    Response::serverError('Authentication failed due to an unexpected server issue.');
}
