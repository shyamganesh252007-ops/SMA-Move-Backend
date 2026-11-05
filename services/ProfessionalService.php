<?php
declare(strict_types=1);

/**
 * SMA-Move — Healthcare Professional Verification Service
 *
 * Manages clinical licensing submission, verification status query,
 * and clinical record access safeguards.
 */

namespace SmaMove\Services;

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/models/ProfessionalProfile.php';
require_once dirname(__DIR__) . '/models/PatientProfile.php';
require_once dirname(__DIR__) . '/models/PatientProfessionalAccess.php';
require_once dirname(__DIR__) . '/models/Consent.php';
require_once dirname(__DIR__) . '/models/AuditLog.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/CareTask.php';
require_once dirname(__DIR__) . '/models/WellbeingJournal.php';
require_once dirname(__DIR__) . '/models/MoveTrackSession.php';

use SmaMove\Models\ProfessionalProfile;
use SmaMove\Models\PatientProfile;
use SmaMove\Models\PatientProfessionalAccess;
use SmaMove\Models\Consent;
use SmaMove\Models\AuditLog;
use SmaMove\Models\User;
use SmaMove\Models\CareTask;
use SmaMove\Models\WellbeingJournal;
use SmaMove\Models\MoveTrackSession;
use RuntimeException;

class ProfessionalService
{
    /**
     * Submit professional credentials for verification review.
     */
    public static function submitVerification(int $userId, array $data): array
    {
        $user = User::findById($userId);
        if (!$user || $user['role'] !== 'professional') {
            throw new RuntimeException("Only healthcare professional accounts can submit clinical credentials.", 403);
        }

        $regNumber = trim($data['registration_number'] ?? '');
        $institution = trim($data['institution_name'] ?? '');

        if ($regNumber === '' || $institution === '') {
            throw new RuntimeException("Registration number and institution name are required for clinical verification.", 422);
        }

        $success = ProfessionalProfile::submitVerification($userId, $data);

        if (!$success) {
            throw new RuntimeException("Failed to submit professional credentials.", 500);
        }

        $updatedProfile = ProfessionalProfile::findByUserId($userId);

        return [
            'verification_status' => 'pending',
            'submitted_at' => date('Y-m-d H:i:s'),
            'profile' => $updatedProfile,
            'message' => 'Credentials submitted for review. Clinical access will be unlocked once verified by board administrators.'
        ];
    }

    /**
     * Get verification status and profile for professional.
     */
    public static function getVerificationStatus(int $userId): array
    {
        $profile = ProfessionalProfile::findByUserId($userId);

        if (!$profile) {
            throw new RuntimeException("Professional profile not found.", 404);
        }

        return [
            'verification_status' => $profile['verification_status'],
            'registration_number' => $profile['registration_number'],
            'institution_name' => $profile['institution_name'],
            'professional_title' => $profile['professional_title'],
            'qualification' => $profile['qualification'],
            'can_access_clinical_records' => $profile['verification_status'] === 'verified'
        ];
    }

    /**
     * Administrative verification of healthcare professional credentials.
     * Prevents self-verification.
     */
    public static function adminVerifyCredentials(
        int $professionalUserId,
        int $adminUserId,
        string $status,
        ?string $notes = null
    ): array {
        if ($professionalUserId === $adminUserId) {
            throw new RuntimeException("Healthcare professionals cannot review or verify their own credentials.", 403);
        }

        if (!in_array($status, ['verified', 'rejected', 'pending'], true)) {
            throw new RuntimeException("Invalid verification status: {$status}", 422);
        }

        $prof = User::findById($professionalUserId);
        if (!$prof || $prof['role'] !== 'professional') {
            throw new RuntimeException("Target user is not a healthcare professional.", 404);
        }

        $success = ProfessionalProfile::adminVerify($professionalUserId, $adminUserId, $status, $notes);

        if (!$success) {
            throw new RuntimeException("Failed to update credential verification status.", 500);
        }

        AuditLog::record(
            $adminUserId,
            'professional_verification',
            $professionalUserId,
            "status_{$status}",
            "Status changed to {$status}. Notes: {$notes}"
        );

        return self::getVerificationStatus($professionalUserId);
    }

    /**
     * Professional retrieves authorized patient clinical data.
     * Enforces: verified credentials + explicit patient authorization + patient clinical consent.
     */
    public static function getAuthorizedPatientData(int $professionalUserId, int $patientUserId): array
    {
        // 1. Verify credentials
        if (!ProfessionalProfile::isVerified($professionalUserId)) {
            throw new RuntimeException(
                "Access restricted. You must have verified credentials to access patient clinical information.",
                403
            );
        }

        // 2. Verify explicit patient-specific authorization
        if (!PatientProfessionalAccess::isAuthorized($professionalUserId, $patientUserId)) {
            throw new RuntimeException(
                "Access denied. You have not been authorized by this patient for clinical monitoring.",
                403
            );
        }

        // 3. Verify patient clinical consent
        if (!Consent::isGranted($patientUserId, Consent::TYPE_CLINICAL)) {
            throw new RuntimeException(
                "Access denied. Patient has paused or revoked clinical information sharing consent.",
                403
            );
        }

        $patient = PatientProfile::getProfileWithUser($patientUserId);
        $today = date('Y-m-d');
        $carePlanSummary = CareTask::getSummaryForDate($patientUserId, $today);
        $recentWellbeing = WellbeingJournal::listByPatient($patientUserId, 5);
        $recentMoveTrack = MoveTrackSession::listByPatient($patientUserId, 5);

        AuditLog::record(
            $professionalUserId,
            'clinical_records',
            $patientUserId,
            'view_records',
            "Verified professional viewed authorized clinical monitoring records."
        );

        return [
            'patient_id' => $patientUserId,
            'patient_name' => $patient['full_name'],
            'date_of_birth' => $patient['date_of_birth'],
            'preferred_language' => $patient['preferred_language'],
            'emergency_contact' => [
                'name' => $patient['emergency_contact_name'],
                'phone' => $patient['emergency_contact_phone']
            ],
            'clinical_monitoring_status' => 'authorized',
            'care_plan_today' => $carePlanSummary,
            'recent_wellbeing_checkins' => $recentWellbeing,
            'recent_movetrack_sessions' => $recentMoveTrack,
            'data_scope_notice' => 'Authorized clinical monitoring view. All telemetry was captured under informed patient consent.'
        ];
    }

    /**
     * Set verified status (e.g. for testing or clinical review verification).
     */
    public static function setVerificationStatus(int $userId, string $status): bool
    {
        return ProfessionalProfile::setVerificationStatus($userId, $status);
    }
}
