<?php
declare(strict_types=1);

/**
 * SMA-Move — Care Plan Tasks Endpoint
 *
 * GET /api/careplan/tasks.php?date=YYYY-MM-DD[&patient_id=X]
 *
 * Retrieves active care tasks, step instructions, and completion state for a date.
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
    if (!$rel || $rel['access_status'] !== 'approved') {
        Response::forbidden('Access denied. You are not an approved caregiver for this patient.');
    }

    if (empty($rel['can_view_care_plan'])) {
        Response::forbidden('Access denied. Permission can_view_care_plan is not granted.');
    }

    $patientUserId = $requestedPatientId;
} elseif ($user['role'] === 'professional') {
    $requestedPatientId = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
    if ($requestedPatientId <= 0) {
        Response::badRequest('patient_id parameter is required for professional access.');
    }

    if (!ProfessionalProfile::isVerified($user['id'])) {
        Response::forbidden('Access denied. Professional profile is not verified.');
    }

    if (!PatientProfessionalAccess::isAuthorized($user['id'], $requestedPatientId)) {
        Response::forbidden('Access denied. You have not been authorized by this patient.');
    }

    if (!Consent::isGranted($requestedPatientId, Consent::TYPE_CLINICAL)) {
        Response::forbidden('Access denied. Patient clinical sharing consent is not active.');
    }

    $patientUserId = $requestedPatientId;
}

try {
    $tasks = CareTask::findTasksForPatient($patientUserId, $date);
    $summary = CareTask::getSummaryForDate($patientUserId, $date);

    Response::success([
        'date' => $date,
        'summary' => $summary,
        'tasks' => $tasks
    ], 'Care tasks retrieved successfully.');
} catch (\Throwable $e) {
    error_log("[Care Tasks Error] " . $e->getMessage());
    Response::serverError('Unable to retrieve care tasks.');
}
