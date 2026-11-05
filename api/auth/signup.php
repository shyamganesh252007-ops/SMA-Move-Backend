<?php
declare(strict_types=1);

/**
 * SMA-Move — User Registration Endpoint
 *
 * POST /api/auth/signup.php
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/middleware/cors.php';
require_once dirname(dirname(__DIR__)) . '/middleware/request_validation.php';
require_once dirname(dirname(__DIR__)) . '/services/AuthService.php';
require_once dirname(dirname(__DIR__)) . '/utils/response.php';
require_once dirname(dirname(__DIR__)) . '/utils/rate_limiter.php';

use SmaMove\Middleware\CorsMiddleware;
use SmaMove\Middleware\RequestValidator;
use SmaMove\Services\AuthService;
use SmaMove\Utils\Response;
use SmaMove\Utils\RateLimiter;

// Handle CORS
CorsMiddleware::handle();

// Enforce HTTP method
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::methodNotAllowed('Only POST requests are allowed.');
}

// SEC-03: Rate Limiting for Account Registration
$ip = RateLimiter::getClientIp();
$maxAttempts = (int)env('RATE_LIMIT_SIGNUP_ATTEMPTS', 5);
$windowSeconds = (int)env('RATE_LIMIT_SIGNUP_WINDOW', 3600); // 1 hour

if (RateLimiter::isExceeded($ip, 'signup', $maxAttempts, $windowSeconds)) {
    Response::tooManyRequests('Too many registration attempts from this address. Please try again in 1 hour.');
}

// Record attempt against rate limit quota atomically (ensures race-free throttling under concurrency)
$currentAttempts = RateLimiter::hit($ip, 'signup', $windowSeconds);
if ($currentAttempts > $maxAttempts) {
    Response::tooManyRequests('Too many registration attempts from this address. Please try again in 1 hour.');
}

// Validate input rules
$rules = [
    'full_name' => ['required', 'min:2', 'max:150'],
    'email' => ['required', 'email'],
    'password' => ['required', 'password_strength'],
    'role' => ['required', 'enum:patient,caregiver,professional']
];

$input = RequestValidator::validate($rules);

try {
    $result = AuthService::signup($input);
    Response::created($result, 'Registration successful. Account created.');
} catch (\RuntimeException $e) {
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
    Response::error($e->getMessage(), $code);
} catch (\Throwable $e) {
    error_log("[Signup Error] " . $e->getMessage());
    Response::serverError('Unable to complete registration. Please try again.');
}
