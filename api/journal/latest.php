<?php
declare(strict_types=1);

/**
 * SMA-Move — Latest Wellbeing Check-in Endpoint
 *
 * GET /api/journal/latest.php[?patient_id=X]
 *
 * Returns today's check-in status and the most recent entry.
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/middleware/cors.php';
require_once dirname(dirname(__DIR__)) . '/middleware/auth_middleware.php';
require_once dirname(dirname(__DIR__)) . '/models/WellbeingJournal.php';
require_once dirname(dirname(__DIR__)) . '/models/CaregiverRelationship.php';
require_once dirname(dirname(__DIR__)) . '/models/Consent.php';
require_once dirname(dirname(__DIR__)) . '/utils/response.php';

use SmaMove\Middleware\CorsMiddleware;
use SmaMove\Middleware\AuthMiddleware;
use SmaMove\Models\WellbeingJournal;
use SmaMove\Models\CaregiverRelationship;
use SmaMove\Models\Consent;
use SmaMove\Utils\Response;

CorsMiddleware::handle();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Response::methodNotAllowed('Only GET requests are allowed.');
}

$user = AuthMiddleware::authenticate();
$patientUserId = (int)$user['id'];

if ($user['role'] === 'caregiver') {
    $requestedPatientId = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
    if ($requestedPatientId <= 0) {
        Response::badRequest('patient_id parameter is required for caregiver access.');
    }

    $rel = CaregiverRelationship::findByPatientAndCaregiver($requestedPatientId, $user['id']);
    if (!$rel || $rel['access_status'] !== 'approved' || empty($rel['can_receive_summaries'])) {
        Response::forbidden('Access denied. You are not authorized to receive summaries for this patient.');
    }

    if (!Consent::isGranted($requestedPatientId, Consent::TYPE_CAREGIVER)) {
        Response::forbidden('Access denied. Patient caregiver sharing consent is not active.');
    }

    $patientUserId = $requestedPatientId;
} elseif ($user['role'] !== 'patient') {
    Response::forbidden('Unauthorized role.');
}

try {
    $hasCheckedInToday = WellbeingJournal::hasCheckedInToday($patientUserId);
    $latest = WellbeingJournal::getLatest($patientUserId);

    Response::success([
        'has_checked_in_today' => $hasCheckedInToday,
        'latest_entry' => $latest
    ], 'Latest wellbeing check-in retrieved successfully.');
} catch (\Throwable $e) {
    error_log("[Latest Wellbeing Error] " . $e->getMessage());
    Response::serverError('Unable to retrieve latest wellbeing check-in.');
}
