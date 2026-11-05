<?php
declare(strict_types=1);

/**
 * SMA-Move — Patient Caregiver Invitation Endpoint
 *
 * POST /api/caregiver/invite.php
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/middleware/cors.php';
require_once dirname(dirname(__DIR__)) . '/middleware/role_middleware.php';
require_once dirname(dirname(__DIR__)) . '/middleware/request_validation.php';
require_once dirname(dirname(__DIR__)) . '/services/CaregiverService.php';
require_once dirname(dirname(__DIR__)) . '/utils/response.php';

use SmaMove\Middleware\CorsMiddleware;
use SmaMove\Middleware\RoleMiddleware;
use SmaMove\Middleware\RequestValidator;
use SmaMove\Services\CaregiverService;
use SmaMove\Utils\Response;

CorsMiddleware::handle();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::methodNotAllowed('Only POST requests are allowed.');
}

// Enforce Patient Role
$patientUser = RoleMiddleware::requirePatient();

// Validate input
$input = RequestValidator::validate([
    'relationship_type' => ['required'],
], false);

try {
    $invitation = CaregiverService::createInvitation($patientUser['id'], $input);
    Response::created($invitation, 'Caregiver invitation code generated successfully.');
} catch (\RuntimeException $e) {
    Response::error($e->getMessage(), $e->getCode() ?: 400);
} catch (\Throwable $e) {
    error_log("[Invite Caregiver Error] " . $e->getMessage());
    Response::serverError('Unable to generate caregiver invitation.');
}
