<?php
declare(strict_types=1);

/**
 * SMA-Move — Appointments List Endpoint
 *
 * GET /api/appointments/list.php[?status=scheduled|completed|cancelled][&patient_id=X]
 *
 * Lists clinical consultations with session types, timestamps, and virtual meeting rooms.
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/middleware/cors.php';
require_once dirname(dirname(__DIR__)) . '/middleware/auth_middleware.php';
require_once dirname(dirname(__DIR__)) . '/models/Appointment.php';
require_once dirname(dirname(__DIR__)) . '/models/CaregiverRelationship.php';
require_once dirname(dirname(__DIR__)) . '/utils/response.php';

use SmaMove\Middleware\CorsMiddleware;
use SmaMove\Middleware\AuthMiddleware;
use SmaMove\Models\Appointment;
use SmaMove\Models\CaregiverRelationship;
use SmaMove\Utils\Response;

CorsMiddleware::handle();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Response::methodNotAllowed('Only GET requests are allowed.');
}

$user = AuthMiddleware::authenticate();
$status = isset($_GET['status']) && in_array($_GET['status'], ['scheduled', 'completed', 'cancelled', 'rescheduled'], true)
    ? $_GET['status']
    : null;

try {
    if ($user['role'] === 'patient') {
        $appointments = Appointment::listByPatient((int)$user['id'], $status);
    } elseif ($user['role'] === 'professional') {
        $appointments = Appointment::listByProfessional((int)$user['id'], $status);
    } elseif ($user['role'] === 'caregiver') {
        $patientId = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
        if ($patientId <= 0) {
            Response::badRequest('patient_id parameter is required for caregiver access.');
        }

        $rel = CaregiverRelationship::findByPatientAndCaregiver($patientId, $user['id']);
        if (!$rel || $rel['access_status'] !== 'approved' || empty($rel['can_receive_summaries'])) {
            Response::forbidden('Access denied. You are not authorized to view this patient\'s appointments.');
        }

        $appointments = Appointment::listByPatient($patientId, $status);
    } else {
        Response::forbidden('Unauthorized role.');
    }

    Response::success([
        'total' => count($appointments),
        'appointments' => $appointments
    ], 'Appointments retrieved successfully.');
} catch (\Throwable $e) {
    error_log("[Appointments List Error] " . $e->getMessage());
    Response::serverError('Unable to retrieve appointments.');
}
