<?php
declare(strict_types=1);

/**
 * SMA-Move — Caregiver Authorized Patients Endpoint
 *
 * GET /api/caregiver/patients.php
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

// Enforce Caregiver Role
$caregiverUser = RoleMiddleware::requireCaregiver();

try {
    $approvedOnly = !isset($_GET['all']) || $_GET['all'] !== 'true';
    $patients = CaregiverService::getCaregiverPatients($caregiverUser['id'], $approvedOnly);

    Response::success([
        'total' => count($patients),
        'patients' => $patients
    ], 'Authorized patients retrieved successfully.');
} catch (\Throwable $e) {
    error_log("[Caregiver Patients Error] " . $e->getMessage());
    Response::serverError('Unable to retrieve authorized patients.');
}
