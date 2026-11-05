<?php
declare(strict_types=1);

/**
 * SMA-Move — Care Task Toggle / Completion Endpoint
 *
 * POST /api/careplan/toggle_task.php
 *
 * Toggles or records completion status for a care task on a specific date.
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/middleware/cors.php';
require_once dirname(dirname(__DIR__)) . '/middleware/auth_middleware.php';
require_once dirname(dirname(__DIR__)) . '/middleware/request_validation.php';
require_once dirname(dirname(__DIR__)) . '/models/CareTask.php';
require_once dirname(dirname(__DIR__)) . '/models/CaregiverRelationship.php';
require_once dirname(dirname(__DIR__)) . '/utils/response.php';

use SmaMove\Middleware\CorsMiddleware;
use SmaMove\Middleware\AuthMiddleware;
use SmaMove\Middleware\RequestValidator;
use SmaMove\Models\CareTask;
use SmaMove\Models\CaregiverRelationship;
use SmaMove\Utils\Response;

CorsMiddleware::handle();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::methodNotAllowed('Only POST requests are allowed.');
}

$user = AuthMiddleware::authenticate();

$input = RequestValidator::validate([
    'task_id' => ['required', 'integer'],
    'is_completed' => ['required']
]);

$taskId = (int)$input['task_id'];
$isCompleted = filter_var($input['is_completed'], FILTER_VALIDATE_BOOLEAN);
$date = isset($input['completed_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$input['completed_date'])
    ? (string)$input['completed_date']
    : date('Y-m-d');
$notes = isset($input['notes']) ? trim((string)$input['notes']) : null;

$patientUserId = (int)$user['id'];

if ($user['role'] === 'caregiver') {
    $requestedPatientId = isset($input['patient_id']) ? (int)$input['patient_id'] : 0;
    if ($requestedPatientId <= 0) {
        Response::badRequest('patient_id is required when toggling tasks as a caregiver.');
    }

    $rel = CaregiverRelationship::findByPatientAndCaregiver($requestedPatientId, $user['id']);
    if (!$rel || $rel['access_status'] !== 'approved') {
        Response::forbidden('Access denied. You are not an approved caregiver for this patient.');
    }

    if (empty($rel['can_edit_assistance'])) {
        Response::forbidden('Access denied. Permission can_edit_assistance is not granted.');
    }

    $patientUserId = $requestedPatientId;
} elseif ($user['role'] !== 'patient') {
    Response::forbidden('Only patients and authorized caregivers with edit permissions can complete tasks.');
}

// Verify task exists and is accessible
$task = CareTask::findById($taskId, $patientUserId, $date);
if (!$task) {
    Response::notFound('Care task not found or inaccessible.');
}

try {
    $success = CareTask::setCompletion($taskId, $patientUserId, $date, $isCompleted, $notes);

    if (!$success) {
        Response::serverError('Unable to update task completion status.');
    }

    $updatedTask = CareTask::findById($taskId, $patientUserId, $date);
    $summary = CareTask::getSummaryForDate($patientUserId, $date);

    Response::success([
        'task' => $updatedTask,
        'summary' => $summary
    ], $isCompleted ? 'Care task marked completed.' : 'Care task marked incomplete.');
} catch (\Throwable $e) {
    error_log("[Toggle Care Task Error] " . $e->getMessage());
    Response::serverError('Unable to update task completion.');
}
