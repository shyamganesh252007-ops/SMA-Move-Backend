<?php
declare(strict_types=1);

/**
 * SMA-Move — Submit Healthcare Professional Verification Endpoint
 *
 * POST /api/professional/submit_verification.php
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/middleware/cors.php';
require_once dirname(dirname(__DIR__)) . '/middleware/role_middleware.php';
require_once dirname(dirname(__DIR__)) . '/middleware/request_validation.php';
require_once dirname(dirname(__DIR__)) . '/services/ProfessionalService.php';
require_once dirname(dirname(__DIR__)) . '/utils/response.php';

use SmaMove\Middleware\CorsMiddleware;
use SmaMove\Middleware\RoleMiddleware;
use SmaMove\Middleware\RequestValidator;
use SmaMove\Services\ProfessionalService;
use SmaMove\Utils\Response;

CorsMiddleware::handle();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::methodNotAllowed('Only POST requests are allowed.');
}

// Enforce Professional Role
$profUser = RoleMiddleware::requireProfessional();

// Validate input
$input = RequestValidator::validate([
    'registration_number' => ['required', 'min:3', 'max:100'],
    'institution_name' => ['required', 'min:2', 'max:200']
]);

try {
    $result = ProfessionalService::submitVerification($profUser['id'], $input);
    Response::success($result, $result['message']);
} catch (\RuntimeException $e) {
    Response::error($e->getMessage(), $e->getCode() ?: 400);
} catch (\Throwable $e) {
    error_log("[Professional Verification Submit Error] " . $e->getMessage());
    Response::serverError('Unable to submit verification credentials.');
}
