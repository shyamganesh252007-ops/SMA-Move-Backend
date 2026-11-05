<?php
declare(strict_types=1);

/**
 * SMA-Move — Caregiver Access Request Endpoint
 *
 * POST /api/caregiver/request_access.php
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

// Enforce Caregiver Role
$caregiverUser = RoleMiddleware::requireCaregiver();

// Validate input rules
$input = RequestValidator::validate([
    'invitation_code' => ['required']
]);

try {
    $relationType = $input['relationship_type'] ?? 'Primary Family Caregiver';
    $result = CaregiverService::requestAccessByCode(
        $caregiverUser['id'],
        $input['invitation_code'],
        $relationType
    );
    Response::success($result, $result['notice']);
} catch (\RuntimeException $e) {
    Response::error($e->getMessage(), $e->getCode() ?: 400);
} catch (\Throwable $e) {
    error_log("[Caregiver Request Access Error] " . $e->getMessage());
    Response::serverError('Unable to process access request.');
}
