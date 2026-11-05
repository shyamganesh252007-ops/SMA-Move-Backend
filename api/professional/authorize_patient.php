<?php
declare(strict_types=1);

/**
 * SMA-Move — Patient Authorize Healthcare Professional Endpoint
 *
 * POST /api/professional/authorize_patient.php
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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::methodNotAllowed('Only POST requests are allowed.');
}

// Enforce Patient Role
$patientUser = RoleMiddleware::requirePatient();

// Validate input
$input = RequestValidator::validate([
    'professional_user_id' => ['required', 'integer']
]);

$profId = (int)$input['professional_user_id'];
$scope = $input['clinical_scope'] ?? 'Physical Therapy & Respiratory Monitoring';

try {
    PatientService::authorizeProfessional($patientUser['id'], $profId, $scope);
    Response::success(null, 'Healthcare professional authorized for clinical monitoring.');
} catch (\RuntimeException $e) {
    Response::error($e->getMessage(), $e->getCode() ?: 400);
} catch (\Throwable $e) {
    error_log("[Authorize Professional Error] " . $e->getMessage());
    Response::serverError('Unable to authorize healthcare professional.');
}
