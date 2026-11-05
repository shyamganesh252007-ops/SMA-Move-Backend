<?php
declare(strict_types=1);

/**
 * SMA-Move — Patient Review Connected Caregivers & Invitations Endpoint
 *
 * GET /api/caregiver/review_requests.php
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/middleware/cors.php';
require_once dirname(dirname(__DIR__)) . '/middleware/role_middleware.php';
require_once dirname(dirname(__DIR__)) . '/services/CaregiverService.php';
require_once dirname(dirname(__DIR__)) . '/utils/response.php';

use SmaMove\Middleware\CorsMiddleware;
use SmaMove\Middleware\RoleMiddleware;
use SmaMove\Services\CaregiverService;
use SmaMove\Utils\Response;

CorsMiddleware::handle();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Response::methodNotAllowed('Only GET requests are allowed.');
}

// Enforce Patient Role
$patientUser = RoleMiddleware::requirePatient();

try {
    $careCircle = CaregiverService::getPatientCaregivers($patientUser['id']);
    Response::success($careCircle, 'CareCircle connections and invitations retrieved successfully.');
} catch (\Throwable $e) {
    error_log("[Patient Review Requests Error] " . $e->getMessage());
    Response::serverError('Unable to retrieve caregiver connections.');
}
