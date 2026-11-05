<?php
declare(strict_types=1);

/**
 * SMA-Move — Healthcare Professional Profile Endpoint
 *
 * GET /api/professional/profile.php
 * PUT /api/professional/profile.php
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/middleware/cors.php';
require_once dirname(dirname(__DIR__)) . '/middleware/role_middleware.php';
require_once dirname(dirname(__DIR__)) . '/middleware/request_validation.php';
require_once dirname(dirname(__DIR__)) . '/models/ProfessionalProfile.php';
require_once dirname(dirname(__DIR__)) . '/utils/response.php';

use SmaMove\Middleware\CorsMiddleware;
use SmaMove\Middleware\RoleMiddleware;
use SmaMove\Middleware\RequestValidator;
use SmaMove\Models\ProfessionalProfile;
use SmaMove\Utils\Response;

CorsMiddleware::handle();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!in_array($method, ['GET', 'PUT'], true)) {
    Response::methodNotAllowed('Only GET and PUT requests are allowed.');
}

// Enforce Professional Role
$profUser = RoleMiddleware::requireProfessional();

try {
    if ($method === 'GET') {
        $profile = ProfessionalProfile::findByUserId($profUser['id']);
        Response::success($profile, 'Professional profile retrieved.');
    } else {
        $input = RequestValidator::getJsonBody(false);
        ProfessionalProfile::submitVerification($profUser['id'], $input);
        $updated = ProfessionalProfile::findByUserId($profUser['id']);
        Response::success($updated, 'Professional profile updated successfully.');
    }
} catch (\Throwable $e) {
    error_log("[Professional Profile Error] " . $e->getMessage());
    Response::serverError('Unable to process professional profile request.');
}
