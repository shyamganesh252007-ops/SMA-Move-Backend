<?php
declare(strict_types=1);

/**
 * SMA-Move — Patient Approve / Reject Caregiver Request Endpoint
 *
 * POST /api/caregiver/approve_request.php
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
    'relationship_id' => ['required', 'integer'],
    'action' => ['required', 'enum:approve,reject']
]);

$relationshipId = (int)$input['relationship_id'];
$action = $input['action'];

try {
    if ($action === 'approve') {
        $permissions = [
            'can_view_care_plan' => $input['can_view_care_plan'] ?? 1,
            'can_receive_summaries' => $input['can_receive_summaries'] ?? 1,
            'can_edit_assistance' => $input['can_edit_assistance'] ?? 0,
        ];
        $updated = CaregiverService::approveRequest($patientUser['id'], $relationshipId, $permissions);
        Response::success($updated, 'Caregiver access request approved successfully.');
    } else {
        CaregiverService::rejectRequest($patientUser['id'], $relationshipId);
        Response::success(null, 'Caregiver access request rejected.');
    }
} catch (\RuntimeException $e) {
    Response::error($e->getMessage(), $e->getCode() ?: 400);
} catch (\Throwable $e) {
    error_log("[Approve Request Error] " . $e->getMessage());
    Response::serverError('Unable to process caregiver request.');
}
