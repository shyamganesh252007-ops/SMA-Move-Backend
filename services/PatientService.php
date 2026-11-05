<?php
declare(strict_types=1);

/**
 * SMA-Move — Patient Account & Profile Service
 *
 * Implements patient profile management, consent updates,
 * and clinical specialist authorization.
 */

namespace SmaMove\Services;

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/PatientProfile.php';
require_once dirname(__DIR__) . '/models/Consent.php';
require_once dirname(__DIR__) . '/models/PatientProfessionalAccess.php';
require_once dirname(__DIR__) . '/models/CareTask.php';
require_once dirname(__DIR__) . '/models/Appointment.php';
require_once dirname(__DIR__) . '/models/WellbeingJournal.php';
require_once dirname(__DIR__) . '/models/MoveTrackSession.php';

use SmaMove\Models\User;
use SmaMove\Models\PatientProfile;
use SmaMove\Models\Consent;
use SmaMove\Models\PatientProfessionalAccess;
use SmaMove\Models\CareTask;
use SmaMove\Models\Appointment;
use SmaMove\Models\WellbeingJournal;
use SmaMove\Models\MoveTrackSession;
use RuntimeException;

class PatientService
{
    /**
     * Retrieve patient profile by user ID.
     */
    public static function getProfile(int $patientUserId): array
    {
        $profile = PatientProfile::getProfileWithUser($patientUserId);

        if (!$profile) {
            throw new RuntimeException("Patient profile not found.", 404);
        }

        return $profile;
    }

    /**
     * Update patient profile information.
     */
    public static function updateProfile(int $patientUserId, array $data): array
    {
        $success = PatientProfile::updateProfile($patientUserId, $data);

        if (!$success) {
            throw new RuntimeException("Failed to update patient profile.", 500);
        }

        return self::getProfile($patientUserId);
    }

    /**
     * Authorize a healthcare professional for clinical access.
     */
    public static function authorizeProfessional(
        int $patientUserId,
        int $professionalUserId,
        string $scope = 'Physical Therapy & Respiratory Monitoring'
    ): bool {
        $doctor = User::findById($professionalUserId);

        if (!$doctor || $doctor['role'] !== 'professional') {
            throw new RuntimeException("Target user is not a registered healthcare professional.", 422);
        }

        return PatientProfessionalAccess::authorize($patientUserId, $professionalUserId, $scope);
    }

    /**
     * Revoke clinical access for a healthcare professional.
     */
    public static function revokeProfessional(int $patientUserId, int $professionalUserId): bool
    {
        return PatientProfessionalAccess::revoke($patientUserId, $professionalUserId);
    }

    /**
     * Get all active and revoked consents for patient.
     */
    public static function getConsents(int $patientUserId): array
    {
        return Consent::getAllConsents($patientUserId);
    }

    /**
     * Update or grant patient consent.
     */
    public static function updateConsent(
        int $patientUserId,
        string $consentType,
        bool $isGranted,
        string $consentVersion = '1.0.0',
        ?string $ipAddress = null
    ): array {
        if (!in_array($consentType, [Consent::TYPE_CAMERA, Consent::TYPE_CAREGIVER, Consent::TYPE_CLINICAL], true)) {
            throw new RuntimeException("Invalid consent type: {$consentType}", 422);
        }

        Consent::updateConsent($patientUserId, $consentType, $isGranted, $consentVersion, $ipAddress);
        return Consent::getAllConsents($patientUserId);
    }

    /**
     * Retrieve aggregated dashboard information for a patient.
     * All metrics dynamically computed from the database.
     */
    public static function getDashboardData(int $patientUserId): array
    {
        $patient = self::getProfile($patientUserId);
        $today = date('Y-m-d');
        $todayFormatted = date('l, d M Y');

        // 1. Care plan summary & tasks preview for today
        $allTasksToday = CareTask::findTasksForPatient($patientUserId, $today);
        $carePlanSummary = CareTask::getSummaryForDate($patientUserId, $today);
        $carePlanSummary['tasks_preview'] = array_slice($allTasksToday, 0, 3);

        // 2. Next upcoming appointment
        $nextAppointment = Appointment::getNextUpcoming($patientUserId);

        // 3. Wellbeing status
        $hasCheckedInToday = WellbeingJournal::hasCheckedInToday($patientUserId);
        $latestJournal = WellbeingJournal::getLatest($patientUserId);

        // 4. MoveTrack session status
        $cameraConsent = Consent::isGranted($patientUserId, Consent::TYPE_CAMERA);
        $latestMoveTrack = MoveTrackSession::getLatest($patientUserId);
        $movetrackSessions = MoveTrackSession::listByPatient($patientUserId, 50);

        return [
            'patient' => [
                'id' => (int)$patient['user_id'],
                'full_name' => $patient['full_name'],
                'email' => $patient['email'],
                'preferred_language' => $patient['preferred_language'] ?? 'English',
                'emergency_contact' => [
                    'name' => $patient['emergency_contact_name'] ?? null,
                    'phone' => $patient['emergency_contact_phone'] ?? null
                ]
            ],
            'current_date' => $todayFormatted,
            'care_plan' => [
                'total_tasks' => $carePlanSummary['total_tasks'],
                'completed_tasks' => $carePlanSummary['completed_tasks'],
                'progress_percent' => $carePlanSummary['progress_percent'],
                'date' => $today,
                'tasks_preview' => $carePlanSummary['tasks_preview'],
                'remaining_count' => max(0, $carePlanSummary['total_tasks'] - $carePlanSummary['completed_tasks'])
            ],
            'next_appointment' => $nextAppointment,
            'wellbeing' => [
                'has_checked_in_today' => $hasCheckedInToday,
                'latest_entry' => $latestJournal
            ],
            'movetrack' => [
                'camera_consent_granted' => $cameraConsent,
                'total_sessions_completed' => count($movetrackSessions),
                'latest_session' => $latestMoveTrack
            ]
        ];
    }
}
