<?php
declare(strict_types=1);

/**
 * SMA-Move — List MoveTrack Sessions Endpoint
 *
 * GET /api/movetrack/sessions.php[?patient_id=X][&limit=Y]
 *
 * Lists session summaries with observed movement indicators.
 */

require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/middleware/cors.php';
require_once dirname(dirname(__DIR__)) . '/middleware/auth_middleware.php';
require_once dirname(dirname(__DIR__)) . '/models/MoveTrackSession.php';
require_once dirname(dirname(__DIR__)) . '/models/CaregiverRelationship.php';
require_once dirname(dirname(__DIR__)) . '/models/ProfessionalProfile.php';
require_once dirname(dirname(__DIR__)) . '/models/PatientProfessionalAccess.php';
require_once dirname(dirname(__DIR__)) . '/models/Consent.php';
require_once dirname(dirname(__DIR__)) . '/utils/response.php';

use SmaMove\Middleware\CorsMiddleware;
use SmaMove\Middleware\AuthMiddleware;
use SmaMove\Models\MoveTrackSession;
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
$limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 20;

$patientUserId = (int)$user['id'];

if ($user['role'] === 'caregiver') {
    $requestedPatientId = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
    if ($requestedPatientId <= 0) {
        Response::badRequest('patient_id parameter is required for caregiver access.');
    }

    $rel = CaregiverRelationship::findByPatientAndCaregiver($requestedPatientId, $user['id']);
    if (!$rel || $rel['access_status'] !== 'approved' || empty($rel['can_receive_summaries'])) {
        Response::forbidden('Access denied. You are not authorized to view this patient\'s summaries.');
    }

    if (!Consent::isGranted($requestedPatientId, Consent::TYPE_CAREGIVER)) {
        Response::forbidden('Access denied. Patient caregiver sharing consent is not active.');
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
        Response::forbidden('Access denied. Verified clinical authorization is required.');
    }

    $patientUserId = $requestedPatientId;
} elseif ($user['role'] !== 'patient') {
    Response::forbidden('Unauthorized role.');
}

try {
    $sessions = MoveTrackSession::listByPatient($patientUserId, $limit);

    Response::success([
        'total' => count($sessions),
        'sessions' => $sessions,
        'disclaimer' => MoveTrackSession::NON_DIAGNOSTIC_DISCLAIMER
    ], 'MoveTrack sessions retrieved successfully.');
} catch (\Throwable $e) {
    error_log("[List MoveTrack Sessions Error] " . $e->getMessage());
    Response::serverError('Unable to retrieve MoveTrack sessions.');
}
