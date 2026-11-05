<?php
declare(strict_types=1);

/**
 * SMA-Move — Create Wellbeing Check-in Endpoint
 *
 * POST /api/journal/create.php
 *
 * Saves daily wellbeing metrics: mood, comfort, fatigue, and assistance requests.
 * Supplies data from the Android WellbeingJournalScreen and WellbeingJournalSymptomScreen.
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/middleware/cors.php';
require_once dirname(dirname(__DIR__)) . '/middleware/auth_middleware.php';
require_once dirname(dirname(__DIR__)) . '/middleware/request_validation.php';
require_once dirname(dirname(__DIR__)) . '/models/WellbeingJournal.php';
require_once dirname(dirname(__DIR__)) . '/models/CaregiverRelationship.php';
require_once dirname(dirname(__DIR__)) . '/utils/response.php';

use SmaMove\Middleware\CorsMiddleware;
use SmaMove\Middleware\AuthMiddleware;
use SmaMove\Middleware\RequestValidator;
use SmaMove\Models\WellbeingJournal;
use SmaMove\Models\CaregiverRelationship;
use SmaMove\Utils\Response;

CorsMiddleware::handle();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::methodNotAllowed('Only POST requests are allowed.');
}

$user = AuthMiddleware::authenticate();

$input = RequestValidator::validate([
    'mood' => ['required', 'string', 'max:50'],
    'comfort_level' => ['required', 'string', 'max:50']
]);

$patientUserId = (int)$user['id'];

if ($user['role'] === 'caregiver') {
    $requestedPatientId = isset($input['patient_id']) ? (int)$input['patient_id'] : 0;
    if ($requestedPatientId <= 0) {
        Response::badRequest('patient_id is required when submitting a journal check-in as a caregiver.');
    }

    $rel = CaregiverRelationship::findByPatientAndCaregiver($requestedPatientId, $user['id']);
    if (!$rel || $rel['access_status'] !== 'approved' || empty($rel['can_edit_assistance'])) {
        Response::forbidden('Access denied. Permission can_edit_assistance is required to submit notes on behalf of patient.');
    }

    $patientUserId = $requestedPatientId;
} elseif ($user['role'] !== 'patient') {
    Response::forbidden('Only patients and authorized caregivers can submit wellbeing check-ins.');
}

try {
    $entryId = WellbeingJournal::create($patientUserId, $input);
    $latest = WellbeingJournal::getLatest($patientUserId);

    Response::created([
        'entry' => $latest,
        'confirmation' => 'Wellbeing check-in saved successfully.'
    ], 'Wellbeing check-in logged successfully.');
} catch (\Throwable $e) {
    error_log("[Create Wellbeing Error] " . $e->getMessage());
    Response::serverError('Unable to save wellbeing check-in.');
}
