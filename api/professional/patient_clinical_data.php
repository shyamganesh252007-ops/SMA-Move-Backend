<?php
declare(strict_types=1);

/**
 * SMA-Move — Professional Access to Authorized Patient Clinical Records
 *
 * GET /api/professional/patient_clinical_data.php?patient_id=X
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

// Enforce Professional Role with Verified Credentials
$profUser = RoleMiddleware::requireProfessional(true);

$patientId = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
if ($patientId <= 0) {
    Response::badRequest('A valid patient_id query parameter is required.');
}

try {
    $clinicalData = ProfessionalService::getAuthorizedPatientData($profUser['id'], $patientId);
    Response::success($clinicalData, 'Authorized patient clinical records retrieved.');
} catch (\RuntimeException $e) {
    Response::error($e->getMessage(), $e->getCode() ?: 403);
} catch (\Throwable $e) {
    error_log("[Patient Clinical Data Error] " . $e->getMessage());
    Response::serverError('Unable to retrieve clinical data.');
}
