<?php
declare(strict_types=1);

/**
 * SMA-Move — Care Task Details Endpoint
 *
 * GET /api/careplan/task_details.php?task_id=X[&date=YYYY-MM-DD][&patient_id=Y]
 *
 * Supplies data for the Android CareTaskDetailsScreen.
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/middleware/cors.php';
require_once dirname(dirname(__DIR__)) . '/middleware/auth_middleware.php';
require_once dirname(dirname(__DIR__)) . '/models/CareTask.php';
require_once dirname(dirname(__DIR__)) . '/models/CaregiverRelationship.php';
require_once dirname(dirname(__DIR__)) . '/models/ProfessionalProfile.php';
require_once dirname(dirname(__DIR__)) . '/models/PatientProfessionalAccess.php';
require_once dirname(dirname(__DIR__)) . '/models/Consent.php';
require_once dirname(dirname(__DIR__)) . '/utils/response.php';

use SmaMove\Middleware\CorsMiddleware;
use SmaMove\Middleware\AuthMiddleware;
use SmaMove\Models\CareTask;
use SmaMove\Models\CaregiverRelationship;
use SmaMove\Models\ProfessionalProfile;
use SmaMove\Models\PatientProfessionalAccess;
use SmaMove\Models\Consent;
use SmaMove\Utils\Response;

CorsMiddleware::handle();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Response::methodNotAllowed('Only GET requests are allowed.');
}

$user = AuthMiddleware::authenticate();

$taskId = isset($_GET['task_id']) ? (int)$_GET['task_id'] : 0;
if ($taskId <= 0) {
    Response::badRequest('Valid task_id parameter is required.');
}

$date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) 
    ? $_GET['date'] 
    : date('Y-m-d');

$patientUserId = (int)$user['id'];

if ($user['role'] === 'caregiver') {
    $requestedPatientId = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
    if ($requestedPatientId <= 0) {
        Response::badRequest('patient_id parameter is required for caregiver access.');
    }

    $rel = CaregiverRelationship::findByPatientAndCaregiver($requestedPatientId, $user['id']);
    if (!$rel || $rel['access_status'] !== 'approved' || empty($rel['can_view_care_plan'])) {
        Response::forbidden('Access denied. You are not authorized to view this patient\'s care plan.');
    }

    $patientUserId = $requestedPatientId;
} elseif ($user['role'] === 'professional') {
    $requestedPatientId = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
    if ($requestedPatientId <= 0) {
        Response::badRequest('patient_id parameter is required for professional access.');
    }

    if (!ProfessionalProfile::isVerified($user['id']) || 
        !PatientProfessionalAccess::isAuthorized($user['id'], $requestedPatientId) || 
        !Consent::isGranted($requestedPatientId, Consent::TYPE_CLINICAL)) {
        Response::forbidden('Access denied. Clinical authorization is required.');
    }

    $patientUserId = $requestedPatientId;
}

try {
    $task = CareTask::findById($taskId, $patientUserId, $date);

    if (!$task) {
        Response::notFound('Care task not found or unauthorized.');
    }

    Response::success($task, 'Care task details retrieved successfully.');
} catch (\Throwable $e) {
    error_log("[Care Task Details Error] " . $e->getMessage());
    Response::serverError('Unable to retrieve task details.');
}
