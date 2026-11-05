<?php
declare(strict_types=1);

/**
 * SMA-Move — Patient Profile Endpoint
 *
 * GET /api/patient/profile.php
 * PUT /api/patient/profile.php
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/middleware/cors.php';
require_once dirname(dirname(__DIR__)) . '/middleware/role_middleware.php';
require_once dirname(dirname(__DIR__)) . '/middleware/request_validation.php';
require_once dirname(dirname(__DIR__)) . '/services/PatientService.php';
require_once dirname(dirname(__DIR__)) . '/utils/response.php';

use SmaMove\Middleware\CorsMiddleware;
use SmaMove\Middleware\RoleMiddleware;
use SmaMove\Middleware\RequestValidator;
use SmaMove\Services\PatientService;
use SmaMove\Utils\Response;

CorsMiddleware::handle();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!in_array($method, ['GET', 'PUT'], true)) {
    Response::methodNotAllowed('Only GET and PUT requests are allowed.');
}

// Enforce Patient Role (Guarantees caller is an authenticated patient)
$patientUser = RoleMiddleware::requirePatient();

// IDOR Protection: If patient_id param is provided, ensure caller owns it
if (isset($_GET['patient_id'])) {
    $targetId = (int)$_GET['patient_id'];
    RoleMiddleware::verifyPatientOwnership($patientUser['id'], $targetId);
}

try {
    if ($method === 'GET') {
        $profile = PatientService::getProfile($patientUser['id']);
        Response::success($profile, 'Patient profile retrieved.');
    } else {
        $input = RequestValidator::getJsonBody(false);
        $updated = PatientService::updateProfile($patientUser['id'], $input);
        Response::success($updated, 'Patient profile updated successfully.');
    }
} catch (\RuntimeException $e) {
    Response::error($e->getMessage(), $e->getCode() ?: 400);
} catch (\Throwable $e) {
    error_log("[Patient Profile Error] " . $e->getMessage());
    Response::serverError('Unable to process patient profile request.');
}
