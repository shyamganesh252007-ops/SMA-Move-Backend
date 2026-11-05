<?php
declare(strict_types=1);

/**
 * SMA-Move — Revoke Caregiver Access Endpoint
 *
 * POST /api/caregiver/revoke_access.php
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/middleware/cors.php';
require_once dirname(dirname(__DIR__)) . '/middleware/auth_middleware.php';
require_once dirname(dirname(__DIR__)) . '/middleware/request_validation.php';
require_once dirname(dirname(__DIR__)) . '/services/CaregiverService.php';
require_once dirname(dirname(__DIR__)) . '/utils/response.php';

use SmaMove\Middleware\CorsMiddleware;
use SmaMove\Middleware\AuthMiddleware;
use SmaMove\Middleware\RequestValidator;
use SmaMove\Services\CaregiverService;
use SmaMove\Utils\Response;

CorsMiddleware::handle();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::methodNotAllowed('Only POST requests are allowed.');
}

// Authenticate caller
$authUser = AuthMiddleware::authenticate();

// Validate input
$input = RequestValidator::validate([
    'relationship_id' => ['required', 'integer']
]);

$relationshipId = (int)$input['relationship_id'];

try {
    CaregiverService::revokeAccess($authUser['id'], $relationshipId);
    Response::success(null, 'Caregiver access has been successfully revoked.');
} catch (\RuntimeException $e) {
    Response::error($e->getMessage(), $e->getCode() ?: 400);
} catch (\Throwable $e) {
    error_log("[Revoke Access Error] " . $e->getMessage());
    Response::serverError('Unable to revoke caregiver access.');
}
