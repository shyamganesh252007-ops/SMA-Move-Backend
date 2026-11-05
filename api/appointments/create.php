<?php
declare(strict_types=1);

/**
 * SMA-Move — Create Appointment Endpoint
 *
 * POST /api/appointments/create.php
 *
 * Books a clinical consultation session.
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/middleware/cors.php';
require_once dirname(dirname(__DIR__)) . '/middleware/auth_middleware.php';
require_once dirname(dirname(__DIR__)) . '/middleware/request_validation.php';
require_once dirname(dirname(__DIR__)) . '/models/Appointment.php';
require_once dirname(dirname(__DIR__)) . '/models/ProfessionalProfile.php';
require_once dirname(dirname(__DIR__)) . '/models/PatientProfessionalAccess.php';
require_once dirname(dirname(__DIR__)) . '/utils/response.php';

use SmaMove\Middleware\CorsMiddleware;
use SmaMove\Middleware\AuthMiddleware;
use SmaMove\Middleware\RequestValidator;
use SmaMove\Models\Appointment;
use SmaMove\Models\ProfessionalProfile;
use SmaMove\Models\PatientProfessionalAccess;
use SmaMove\Utils\Validation;
use SmaMove\Utils\Response;

CorsMiddleware::handle();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::methodNotAllowed('Only POST requests are allowed.');
}

$user = AuthMiddleware::authenticate();

$input = RequestValidator::validate([
    'specialist_name' => ['required', 'string', 'max:150'],
    'appointment_date' => ['required', 'string'],
    'appointment_time' => ['required', 'string', 'max:50']
]);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$input['appointment_date'])) {
    Response::validationError(['appointment_date' => 'Date must be formatted as YYYY-MM-DD.']);
}

$patientUserId = (int)$user['id'];
$profUserId = isset($input['professional_user_id']) ? (int)$input['professional_user_id'] : null;

if ($user['role'] === 'professional') {
    $requestedPatientId = isset($input['patient_id']) ? (int)$input['patient_id'] : 0;
    if ($requestedPatientId <= 0) {
        Response::badRequest('patient_id is required when creating an appointment as a healthcare professional.');
    }

    if (!ProfessionalProfile::isVerified($user['id']) || 
        !PatientProfessionalAccess::isAuthorized($user['id'], $requestedPatientId)) {
        Response::forbidden('Access denied. You must be verified and authorized by this patient.');
    }

    $patientUserId = $requestedPatientId;
    $profUserId = (int)$user['id'];
} elseif ($user['role'] !== 'patient') {
    Response::forbidden('Only patients and authorized healthcare professionals can book appointments.');
}

try {
    // Determine meeting link governance:
    // - Ordinary patients and caregivers can NEVER supply a meeting link (forced to NULL).
    // - Verified, authorized healthcare professionals may supply a link only if it passes HTTPS & domain allowlist validation.
    $meetingLink = null;

    if ($user['role'] === 'professional') {
        $candidateLink = $input['meeting_link'] 
            ?? $input['virtual_room_url'] 
            ?? $input['meeting_url'] 
            ?? $input['room_url'] 
            ?? null;

        if (!empty($candidateLink)) {
            $valResult = Validation::validateTelehealthUrl((string)$candidateLink);
            if (!$valResult['valid']) {
                Response::unprocessable($valResult['error']);
            }
            $meetingLink = $valResult['url'];
        }
    } else {
        // Patient / Caregiver: Force meeting_link = NULL regardless of payload
        $meetingLink = null;
    }

    $appointmentId = Appointment::create([
        'patient_user_id' => $patientUserId,
        'professional_user_id' => $profUserId,
        'specialist_name' => $input['specialist_name'],
        'specialist_title' => $input['specialist_title'] ?? 'Specialist',
        'appointment_date' => $input['appointment_date'],
        'appointment_time' => $input['appointment_time'],
        'session_type' => $input['session_type'] ?? 'Virtual Care Consultation',
        'status' => 'scheduled',
        'meeting_link' => $meetingLink,
        'preparation_notes' => $input['preparation_notes'] ?? null
    ]);

    $created = Appointment::findById($appointmentId);
    Response::created($created, 'Appointment scheduled successfully.');
} catch (\Throwable $e) {
    error_log("[Create Appointment Error] " . $e->getMessage());
    Response::serverError('Unable to create appointment.');
}
