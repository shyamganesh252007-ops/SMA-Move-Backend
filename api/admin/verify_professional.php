<?php
declare(strict_types=1);

/**
 * SMA-Move — Administrative Professional Verification Endpoint
 *
 * POST /api/admin/verify_professional.php
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/middleware/cors.php';
require_once dirname(dirname(__DIR__)) . '/middleware/auth_middleware.php';
require_once dirname(dirname(__DIR__)) . '/middleware/request_validation.php';
require_once dirname(dirname(__DIR__)) . '/services/ProfessionalService.php';
require_once dirname(dirname(__DIR__)) . '/utils/response.php';

use SmaMove\Middleware\CorsMiddleware;
use SmaMove\Middleware\AuthMiddleware;
use SmaMove\Middleware\RequestValidator;
use SmaMove\Services\ProfessionalService;
use SmaMove\Utils\Response;

CorsMiddleware::handle();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::methodNotAllowed('Only POST requests are allowed.');
}

// Administrative Authorization Guard (Fail-Closed)
$headers = function_exists('apache_request_headers') ? apache_request_headers() : (function_exists('getallheaders') ? getallheaders() : []);
$adminKey = $_SERVER['HTTP_X_ADMIN_KEY'] ?? ($headers['X-Admin-Key'] ?? ($headers['x-admin-key'] ?? ''));
$expectedKey = (string)env('ADMIN_API_KEY', '');

// Reject if ADMIN_API_KEY is missing/empty, if header is missing/empty, or if comparison fails
if ($expectedKey === '' || $adminKey === '' || !hash_equals($expectedKey, (string)$adminKey)) {
    Response::forbidden('Administrative authorization key (X-Admin-Key) is required.');
}

// Authenticate caller (Reviewer)
$reviewerUser = AuthMiddleware::authenticate();

// Validate input
$input = RequestValidator::validate([
    'status' => ['required', 'enum:verified,rejected,pending']
]);

$profId = isset($input['professional_user_id']) 
    ? (int)$input['professional_user_id'] 
    : (isset($input['professional_id']) ? (int)$input['professional_id'] : 0);

if ($profId <= 0) {
    Response::validationError(['professional_user_id' => 'Valid professional_user_id is required.']);
}

$status = $input['status'];
$notes = $input['notes'] ?? 'Board verification reviewed';

// Strictly Prevent Self-Verification
if ($reviewerUser['id'] === $profId) {
    Response::forbidden('Healthcare professionals cannot review or verify their own credentials.');
}

try {
    $result = ProfessionalService::adminVerifyCredentials(
        $profId,
        $reviewerUser['id'],
        $status,
        $notes
    );
    Response::success($result, "Professional verification status updated to '{$status}'.");
} catch (\RuntimeException $e) {
    Response::error($e->getMessage(), $e->getCode() ?: 400);
} catch (\Throwable $e) {
    error_log("[Admin Verify Professional Error] " . $e->getMessage());
    Response::serverError('Unable to process credential verification.');
}
