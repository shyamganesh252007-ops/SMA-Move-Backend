<?php
declare(strict_types=1);

/**
 * SMA-Move — Update Appointment Status Endpoint
 *
 * POST /api/appointments/update_status.php
 *
 * Transitions status between scheduled, completed, cancelled, and rescheduled.
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/middleware/cors.php';
require_once dirname(dirname(__DIR__)) . '/middleware/auth_middleware.php';
require_once dirname(dirname(__DIR__)) . '/middleware/request_validation.php';
require_once dirname(dirname(__DIR__)) . '/models/Appointment.php';
require_once dirname(dirname(__DIR__)) . '/utils/response.php';

use SmaMove\Middleware\CorsMiddleware;
use SmaMove\Middleware\AuthMiddleware;
use SmaMove\Middleware\RequestValidator;
use SmaMove\Models\Appointment;
use SmaMove\Utils\Response;

CorsMiddleware::handle();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::methodNotAllowed('Only POST requests are allowed.');
}

$user = AuthMiddleware::authenticate();

$input = RequestValidator::validate([
    'appointment_id' => ['required', 'integer'],
    'status' => ['required', 'enum:scheduled,completed,cancelled,rescheduled']
]);

$appointmentId = (int)$input['appointment_id'];
$status = (string)$input['status'];
$prepNotes = isset($input['preparation_notes']) ? trim((string)$input['preparation_notes']) : null;

$appointment = Appointment::findById($appointmentId);
if (!$appointment) {
    Response::notFound('Appointment not found.');
}

// Ownership check
$isPatient = ($user['role'] === 'patient' && (int)$appointment['patient_user_id'] === (int)$user['id']);
$isDoctor = ($user['role'] === 'professional' && (int)$appointment['professional_user_id'] === (int)$user['id']);

if (!$isPatient && !$isDoctor) {
    Response::forbidden('Access denied. You are not a participant in this appointment.');
}

try {
    $updated = Appointment::updateStatus($appointmentId, $status, $prepNotes);
    Response::success($updated, "Appointment status updated to '{$status}'.");
} catch (\InvalidArgumentException $e) {
    Response::badRequest($e->getMessage());
} catch (\RuntimeException $e) {
    Response::error($e->getMessage(), $e->getCode() ?: 400);
} catch (\Throwable $e) {
    error_log("[Update Appointment Status Error] " . $e->getMessage());
    Response::serverError('Unable to update appointment status.');
}
