<?php
declare(strict_types=1);

/**
 * SMA-Move — Immediate Patient Consent Revocation Endpoint
 *
 * POST /api/consent/revoke_consent.php
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
    'consent_type' => ['required', 'enum:camera_tracking,caregiver_sharing,clinical_sharing']
]);

$ip = $_SERVER['REMOTE_ADDR'] ?? null;

try {
    $consents = PatientService::updateConsent(
        $patientUser['id'],
        $input['consent_type'],
        false,
        '1.0.0',
        $ip
    );
    Response::success($consents, "Consent for '{$input['consent_type']}' revoked immediately.");
} catch (\RuntimeException $e) {
    Response::error($e->getMessage(), $e->getCode() ?: 400);
} catch (\Throwable $e) {
    error_log("[Revoke Consent Error] " . $e->getMessage());
    Response::serverError('Unable to revoke consent.');
}
