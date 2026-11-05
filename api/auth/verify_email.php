<?php
declare(strict_types=1);

/**
 * SMA-Move — Email Verification & Account Activation Endpoint
 *
 * POST /api/auth/verify_email.php
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

// SEC-03: Rate Limiting for Email Verification Attempts
$ip = RateLimiter::getClientIp();
$maxAttempts = (int)env('RATE_LIMIT_VERIFY_ATTEMPTS', 5);
$windowSeconds = (int)env('RATE_LIMIT_VERIFY_WINDOW', 900); // 15 minutes
$ipCeilingKey = $ip . ':verify_email_ip';

// Check global IP ceiling (prevents network-level verification code spraying)
if (RateLimiter::isExceeded($ipCeilingKey, 'verify_email_ip', $maxAttempts * 3, $windowSeconds)) {
    Response::tooManyRequests('Too many verification attempts from this network. Please try again in 15 minutes.');
}

// Validate input rules
$rules = [
    'email' => ['required', 'email'],
    'code' => ['required']
];

$input = RequestValidator::validate($rules);

$normalizedEmail = strtolower(trim((string)$input['email']));
$emailHash = hash('sha256', $normalizedEmail);
// Bind rate limit to both client IP and target account context to prevent cross-user denial-of-service
$contextKey = $ip . ':verify_email:' . $emailHash;

if (RateLimiter::isExceeded($contextKey, 'verify_email', $maxAttempts, $windowSeconds)) {
    Response::tooManyRequests('Too many verification attempts for this account. Please try again in 15 minutes.');
}

// Record attempt atomically under concurrency
$currentContextAttempts = RateLimiter::hit($contextKey, 'verify_email', $windowSeconds);
RateLimiter::hit($ipCeilingKey, 'verify_email_ip', $windowSeconds);

if ($currentContextAttempts > $maxAttempts) {
    Response::tooManyRequests('Too many verification attempts for this account. Please try again in 15 minutes.');
}

try {
    AuthService::verifyEmail($normalizedEmail, (string)$input['code']);

    // Successful activation resets the failed attempt counter for this account context
    RateLimiter::clear($contextKey, 'verify_email');

    Response::success(null, 'Account successfully verified and activated. You may now sign in.');
} catch (\RuntimeException $e) {
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
    Response::error($e->getMessage(), $code);
} catch (\Throwable $e) {
    error_log("[Email Verification Error] " . $e->getMessage());
    Response::serverError('Unable to complete account verification.');
}
