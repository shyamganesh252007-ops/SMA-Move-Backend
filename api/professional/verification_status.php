<?php
declare(strict_types=1);

/**
 * SMA-Move — Query Healthcare Professional Verification Status Endpoint
 *
 * GET /api/professional/verification_status.php
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/middleware/cors.php';
require_once dirname(dirname(__DIR__)) . '/middleware/role_middleware.php';
require_once dirname(dirname(__DIR__)) . '/services/ProfessionalService.php';
require_once dirname(dirname(__DIR__)) . '/utils/response.php';

use SmaMove\Middleware\CorsMiddleware;
use SmaMove\Middleware\RoleMiddleware;
use SmaMove\Services\ProfessionalService;
use SmaMove\Utils\Response;

CorsMiddleware::handle();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Response::methodNotAllowed('Only GET requests are allowed.');
}

// Enforce Professional Role
$profUser = RoleMiddleware::requireProfessional();

try {
    $statusData = ProfessionalService::getVerificationStatus($profUser['id']);
    Response::success($statusData, 'Verification status retrieved.');
} catch (\RuntimeException $e) {
    Response::error($e->getMessage(), $e->getCode() ?: 400);
} catch (\Throwable $e) {
    error_log("[Professional Verification Status Error] " . $e->getMessage());
    Response::serverError('Unable to retrieve verification status.');
}
