<?php
declare(strict_types=1);

/**
 * SMA-Move — Caregiver Access to Authorized Patient Summary
 *
 * GET /api/caregiver/patient_summary.php?patient_id=X
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

$patientId = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
if ($patientId <= 0) {
    Response::badRequest('A valid patient_id query parameter is required.');
}

try {
    $summary = CaregiverService::getPatientSummary($caregiverUser['id'], $patientId);
    Response::success($summary, 'Authorized patient summary retrieved.');
} catch (\RuntimeException $e) {
    Response::error($e->getMessage(), $e->getCode() ?: 403);
} catch (\Throwable $e) {
    error_log("[Caregiver Patient Summary Error] " . $e->getMessage());
    Response::serverError('Unable to retrieve patient summary.');
}
