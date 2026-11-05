<?php
declare(strict_types=1);

/**
 * SMA-Move — Caregiver Profile Endpoint
 *
 * GET /api/caregiver/profile.php
 * PUT /api/caregiver/profile.php
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

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!in_array($method, ['GET', 'PUT'], true)) {
    Response::methodNotAllowed('Only GET and PUT requests are allowed.');
}

// Enforce Caregiver Role
$caregiverUser = RoleMiddleware::requireCaregiver();

try {
    if ($method === 'GET') {
        $profile = CaregiverService::getProfile($caregiverUser['id']);
        Response::success($profile, 'Caregiver profile retrieved.');
    } else {
        $input = RequestValidator::getJsonBody(false);
        $updated = CaregiverService::updateProfile($caregiverUser['id'], $input);
        Response::success($updated, 'Caregiver profile updated successfully.');
    }
} catch (\RuntimeException $e) {
    Response::error($e->getMessage(), $e->getCode() ?: 400);
} catch (\Throwable $e) {
    error_log("[Caregiver Profile Error] " . $e->getMessage());
    Response::serverError('Unable to process caregiver profile request.');
}
