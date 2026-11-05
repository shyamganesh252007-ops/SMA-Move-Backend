<?php
declare(strict_types=1);

/**
 * SMA-Move — Get Patient Consent Preferences Endpoint
 *
 * GET /api/consent/get_consent.php
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/middleware/cors.php';
require_once dirname(dirname(__DIR__)) . '/middleware/role_middleware.php';
require_once dirname(dirname(__DIR__)) . '/services/PatientService.php';
require_once dirname(dirname(__DIR__)) . '/utils/response.php';

use SmaMove\Middleware\CorsMiddleware;
use SmaMove\Middleware\RoleMiddleware;
use SmaMove\Services\PatientService;
use SmaMove\Utils\Response;

CorsMiddleware::handle();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Response::methodNotAllowed('Only GET requests are allowed.');
}

// Enforce Patient Role
$patientUser = RoleMiddleware::requirePatient();

try {
    $consents = PatientService::getConsents($patientUser['id']);
    Response::success($consents, 'Patient consent preferences retrieved.');
} catch (\Throwable $e) {
    error_log("[Get Consent Error] " . $e->getMessage());
    Response::serverError('Unable to retrieve consent preferences.');
}
