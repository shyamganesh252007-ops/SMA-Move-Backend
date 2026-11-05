<?php
declare(strict_types=1);

/**
 * SMA-Move — Appointment Details Endpoint
 *
 * GET /api/appointments/details.php?id=X
 *
 * Supplies detailed consultation info for the Android AppointmentDetailsScreen.
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
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    Response::badRequest('Valid appointment id is required.');
}

try {
    $appointment = Appointment::findById($id);

    if (!$appointment) {
        Response::notFound('Appointment not found.');
    }

    // Ownership & Access Control Check (IDOR prevention)
    $hasAccess = false;
    if ($user['role'] === 'patient' && (int)$appointment['patient_user_id'] === (int)$user['id']) {
        $hasAccess = true;
    } elseif ($user['role'] === 'professional' && (int)$appointment['professional_user_id'] === (int)$user['id']) {
        $hasAccess = true;
    } elseif ($user['role'] === 'caregiver') {
        $rel = CaregiverRelationship::findByPatientAndCaregiver((int)$appointment['patient_user_id'], (int)$user['id']);
        if ($rel && $rel['access_status'] === 'approved' && !empty($rel['can_receive_summaries'])) {
            $hasAccess = true;
        }
    }

    if (!$hasAccess) {
        Response::forbidden('Access denied. You are not authorized to view this appointment.');
    }

    Response::success($appointment, 'Appointment details retrieved successfully.');
} catch (\Throwable $e) {
    error_log("[Appointment Details Error] " . $e->getMessage());
    Response::serverError('Unable to retrieve appointment details.');
}
