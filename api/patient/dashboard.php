<?php
declare(strict_types=1);

/**
 * SMA-Move — Patient Aggregated Dashboard Endpoint
 *
 * GET /api/patient/dashboard.php
 *
 * Returns live database-backed metrics for the Android PatientDashboardScreen:
 * - Patient profile and emergency contacts
 * - Today's care plan progress (completed tasks, total tasks, progress %, preview tasks)
 * - Next scheduled appointment
 * - Wellbeing check-in status for today
 * - MoveTrack session metadata and consent status
 *
 * Zero hardcoded values or mock data.
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/middleware/cors.php';
require_once dirname(dirname(__DIR__)) . '/middleware/auth_middleware.php';
require_once dirname(dirname(__DIR__)) . '/services/PatientService.php';
require_once dirname(dirname(__DIR__)) . '/services/CaregiverService.php';
require_once dirname(dirname(__DIR__)) . '/models/CaregiverRelationship.php';
require_once dirname(dirname(__DIR__)) . '/utils/response.php';

use SmaMove\Middleware\CorsMiddleware;
use SmaMove\Middleware\AuthMiddleware;
use SmaMove\Services\PatientService;
use SmaMove\Services\CaregiverService;
use SmaMove\Models\CaregiverRelationship;
use SmaMove\Utils\Response;

CorsMiddleware::handle();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Response::methodNotAllowed('Only GET requests are allowed.');
}

$user = AuthMiddleware::authenticate();
$patientUserId = (int)$user['id'];

// If a caregiver is requesting, they must provide ?patient_id and have approved access
if ($user['role'] === 'caregiver') {
    $requestedPatientId = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
    if ($requestedPatientId <= 0) {
        Response::badRequest('patient_id query parameter is required for caregiver dashboard views.');
    }

    $rel = CaregiverRelationship::findByPatientAndCaregiver($requestedPatientId, $user['id']);
    if (!$rel || $rel['access_status'] !== 'approved') {
        Response::forbidden('Access denied. You are not an approved caregiver for this patient.');
    }

    if (empty($rel['can_receive_summaries'])) {
        Response::forbidden('Access denied. Permission can_receive_summaries is not granted.');
    }

    $patientUserId = $requestedPatientId;
} elseif ($user['role'] !== 'patient') {
    Response::forbidden('Only patients and authorized caregivers can access dashboard information.');
}

try {
    $dashboardData = PatientService::getDashboardData($patientUserId);
    Response::success($dashboardData, 'Dashboard data retrieved successfully.');
} catch (\RuntimeException $e) {
    Response::error($e->getMessage(), $e->getCode() ?: 400);
} catch (\Throwable $e) {
    error_log("[Patient Dashboard Error] " . $e->getMessage());
    Response::serverError('Unable to load patient dashboard.');
}
