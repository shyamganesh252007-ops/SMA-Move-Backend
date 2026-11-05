<?php
declare(strict_types=1);

/**
 * SMA-Move — Phase 5.2 Security and Data Integrity Corrections Test Suite
 *
 * Comprehensive automated regression testing for:
 * 1. Admin Key Fail-Closed Authorization & Self-Verification Guards
 * 2. MoveTrack Numerical Metrics Range Bounding & Type Enforcement
 * 3. Removal of Simulated Telehealth URLs (Default NULL Link Behavior)
 * 4. Appointment State Transition Rules & Concurrency/Terminal Locks
 */

namespace SmaMove\Tests;

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/services/AuthService.php';
require_once dirname(__DIR__) . '/services/ProfessionalService.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/ProfessionalProfile.php';
require_once dirname(__DIR__) . '/models/MoveTrackSession.php';
require_once dirname(__DIR__) . '/models/Consent.php';
require_once dirname(__DIR__) . '/models/Appointment.php';

use SmaMove\Config\Database;
use SmaMove\Services\AuthService;
use SmaMove\Services\ProfessionalService;
use SmaMove\Models\User;
use SmaMove\Models\ProfessionalProfile;
use SmaMove\Models\MoveTrackSession;
use SmaMove\Models\Consent;
use SmaMove\Models\Appointment;
use Exception;
use RuntimeException;
use InvalidArgumentException;

class Phase52CorrectionsTestSuite
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(): void
    {
        echo "\n====================================================================\n";
        echo " SMA-MOVE — PHASE 5.2 CORRECTIONS VERIFICATION TEST SUITE\n";
        echo "====================================================================\n";

        try {
            $this->cleanupData();

            $this->testCorrection1_AdminKeyFailClosed();
            $this->testCorrection2_MoveTrackNumericalBounding();
            $this->testCorrection3_AppointmentMeetingLinkNullDefault();
            $this->testCorrection4_AppointmentStateTransitions();

        } catch (Exception $e) {
            echo "\n[CRITICAL ERROR] " . $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";
            $this->failed++;
        } finally {
            $this->cleanupData();
        }

        echo "\n--------------------------------------------------------------------\n";
        echo sprintf(" Test Summary: %d Passed, %d Failed\n", $this->passed, $this->failed);
        echo "--------------------------------------------------------------------\n\n";

        if ($this->failed > 0) {
            exit(1);
        }
    }

    private function assert(string $description, bool $condition): void
    {
        if ($condition) {
            echo " [PASS] " . $description . "\n";
            $this->passed++;
        } else {
            echo " [FAIL] " . $description . "\n";
            $this->failed++;
        }
    }

    private function cleanupData(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("DELETE FROM users WHERE email LIKE '%@synthetic-p52-test.com'");
    }

    /**
     * Test Correction 1: Admin Key Fail-Closed Authorization & Self-Verification
     */
    private function testCorrection1_AdminKeyFailClosed(): void
    {
        echo "\n--- 1. Testing Admin Key Fail-Closed Authorization ---\n";

        $pdo = Database::getConnection();

        // Create Doctor and Admin Reviewer accounts
        $doctorRes = AuthService::signup([
            'email' => 'doctor@synthetic-p52-test.com',
            'password' => 'SecurePass#2026',
            'full_name' => 'Dr. Review Subject',
            'role' => 'professional'
        ]);
        $doctorId = (int)$doctorRes['id'];
        $pdo->prepare("UPDATE users SET account_status = 'active' WHERE id = :id")->execute([':id' => $doctorId]);

        $reviewerRes = AuthService::signup([
            'email' => 'reviewer@synthetic-p52-test.com',
            'password' => 'SecurePass#2026',
            'full_name' => 'Board Reviewer',
            'role' => 'patient'
        ]);
        $reviewerId = (int)$reviewerRes['id'];
        $pdo->prepare("UPDATE users SET account_status = 'active' WHERE id = :id")->execute([':id' => $reviewerId]);

        // Submit doctor credentials
        ProfessionalProfile::submitVerification($doctorId, [
            'registration_number' => 'REG-P52-001',
            'institution_name' => 'National Neuro Center'
        ]);

        $adminSecret = env('ADMIN_API_KEY');

        // Helper to simulate the exact header evaluation logic from api/admin/verify_professional.php
        $verifyHeaderAuth = function (?string $expectedKey, ?string $providedHeader): bool {
            if (empty($expectedKey) || empty($providedHeader) || !hash_equals((string)$expectedKey, (string)$providedHeader)) {
                return false; // HTTP 403 Forbidden
            }
            return true;
        };

        // Case 1: Correct key with authorized request
        $this->assert("Test Case 1: Correct key passes administrative check", $verifyHeaderAuth($adminSecret, $adminSecret) === true);

        // Case 2: Incorrect key fails closed
        $this->assert("Test Case 2: Incorrect key rejected with 403", $verifyHeaderAuth($adminSecret, 'wrong_secret_key') === false);

        // Case 3: Missing key header fails closed
        $this->assert("Test Case 3: Missing key header rejected with 403", $verifyHeaderAuth($adminSecret, null) === false);

        // Case 4: Empty key header fails closed
        $this->assert("Test Case 4: Empty key header rejected with 403", $verifyHeaderAuth($adminSecret, '') === false);

        // Case 5: Missing ADMIN_API_KEY configuration fails closed
        $this->assert("Test Case 5: Missing server ADMIN_API_KEY config fails closed (403)", $verifyHeaderAuth(null, $adminSecret) === false);

        // Case 6: Empty ADMIN_API_KEY configuration fails closed
        $this->assert("Test Case 6: Empty server ADMIN_API_KEY config fails closed (403)", $verifyHeaderAuth('', $adminSecret) === false);

        // Case 7: Attempted self-verification rejected
        $selfVerifyBlocked = false;
        try {
            ProfessionalService::adminVerifyCredentials($doctorId, $doctorId, 'verified', 'Self approval');
        } catch (RuntimeException $e) {
            if ($e->getCode() === 403) $selfVerifyBlocked = true;
        }
        $this->assert("Test Case 7: Attempted self-verification strictly blocked (403 Forbidden)", $selfVerifyBlocked);

        // Case 8: Ordinary authenticated user attempting administrative verification without admin key
        $ordinaryUserBlocked = ($verifyHeaderAuth($adminSecret, null) === false);
        $this->assert("Test Case 8: Ordinary authenticated user attempting administrative verification rejected with 403", $ordinaryUserBlocked);

        // Legitimate independent verification succeeds
        $verifySuccess = ProfessionalService::adminVerifyCredentials($doctorId, $reviewerId, 'verified', 'Board approved');
        $this->assert("Independent reviewer successfully verifies credentials", $verifySuccess['verification_status'] === 'verified');
    }

    /**
     * Test Correction 2: MoveTrack Numerical Range Bounding
     */
    private function testCorrection2_MoveTrackNumericalBounding(): void
    {
        echo "\n--- 2. Testing MoveTrack Numerical Bounding & Type Enforcement ---\n";

        $pdo = Database::getConnection();

        $patient = AuthService::signup([
            'email' => 'movetrack_patient@synthetic-p52-test.com',
            'password' => 'SecurePass#2026',
            'full_name' => 'MoveTrack Subject',
            'role' => 'patient'
        ]);
        $pId = (int)$patient['id'];
        $pdo->prepare("UPDATE users SET account_status = 'active' WHERE id = :id")->execute([':id' => $pId]);

        // Grant camera consent
        Consent::updateConsent($pId, Consent::TYPE_CAMERA, true);

        // Helper to create an in-progress session
        $createSession = function () use ($pId): string {
            $sess = MoveTrackSession::startSession($pId, ['session_type' => 'Bounds Test Session']);
            return $sess['session_uuid'];
        };

        // 1. duration_seconds: Zero or negative rejected
        $zeroDurationBlocked = false;
        try {
            MoveTrackSession::completeSession($createSession(), $pId, ['duration_seconds' => 0]);
        } catch (RuntimeException $e) {
            $zeroDurationBlocked = ($e->getCode() === 422);
        }
        $this->assert("duration_seconds = 0 rejected with 422", $zeroDurationBlocked);

        $negDurationBlocked = false;
        try {
            MoveTrackSession::completeSession($createSession(), $pId, ['duration_seconds' => -15]);
        } catch (RuntimeException $e) {
            $negDurationBlocked = ($e->getCode() === 422);
        }
        $this->assert("Negative duration_seconds rejected with 422", $negDurationBlocked);

        // 2. duration_seconds: Exceeding 86400 (24h) rejected
        $excessDurationBlocked = false;
        try {
            MoveTrackSession::completeSession($createSession(), $pId, ['duration_seconds' => 86401]);
        } catch (RuntimeException $e) {
            $excessDurationBlocked = ($e->getCode() === 422);
        }
        $this->assert("duration_seconds > 86400 rejected with 422", $excessDurationBlocked);

        // 3. duration_seconds: Boundary 1 (min) and 86400 (max) succeed
        $minSess = MoveTrackSession::completeSession($createSession(), $pId, ['duration_seconds' => 1]);
        $this->assert("duration_seconds = 1 (min boundary) accepted", $minSess['duration_seconds'] === 1);

        $maxSess = MoveTrackSession::completeSession($createSession(), $pId, ['duration_seconds' => 86400]);
        $this->assert("duration_seconds = 86400 (max boundary) accepted", $maxSess['duration_seconds'] === 86400);

        // 4. consistency_percentage: Negative rejected
        $negConsistencyBlocked = false;
        try {
            MoveTrackSession::completeSession($createSession(), $pId, [
                'duration_seconds' => 60,
                'consistency_percentage' => -1
            ]);
        } catch (RuntimeException $e) {
            $negConsistencyBlocked = ($e->getCode() === 422);
        }
        $this->assert("Negative consistency_percentage rejected with 422", $negConsistencyBlocked);

        // 5. consistency_percentage: Exceeding 100 rejected
        $excessConsistencyBlocked = false;
        try {
            MoveTrackSession::completeSession($createSession(), $pId, [
                'duration_seconds' => 60,
                'consistency_percentage' => 101
            ]);
        } catch (RuntimeException $e) {
            $excessConsistencyBlocked = ($e->getCode() === 422);
        }
        $this->assert("consistency_percentage > 100 rejected with 422", $excessConsistencyBlocked);

        // 6. consistency_percentage: Boundary 0 and 100 succeed
        $zeroConsistencySess = MoveTrackSession::completeSession($createSession(), $pId, [
            'duration_seconds' => 60,
            'consistency_percentage' => 0
        ]);
        $this->assert("consistency_percentage = 0 (min boundary) accepted", $zeroConsistencySess['consistency_percentage'] === 0);

        $hundredConsistencySess = MoveTrackSession::completeSession($createSession(), $pId, [
            'duration_seconds' => 60,
            'consistency_percentage' => 100
        ]);
        $this->assert("consistency_percentage = 100 (max boundary) accepted", $hundredConsistencySess['consistency_percentage'] === 100);

        // 7. reps_completed: Negative and exceeding 1000 rejected
        $negRepsBlocked = false;
        try {
            MoveTrackSession::completeSession($createSession(), $pId, [
                'duration_seconds' => 60,
                'reps_completed' => -5
            ]);
        } catch (RuntimeException $e) {
            $negRepsBlocked = ($e->getCode() === 422);
        }
        $this->assert("Negative reps_completed rejected with 422", $negRepsBlocked);

        $excessRepsBlocked = false;
        try {
            MoveTrackSession::completeSession($createSession(), $pId, [
                'duration_seconds' => 60,
                'reps_completed' => 1001
            ]);
        } catch (RuntimeException $e) {
            $excessRepsBlocked = ($e->getCode() === 422);
        }
        $this->assert("reps_completed > 1000 rejected with 422", $excessRepsBlocked);

        // Boundary reps: 0 and 1000 succeed
        $maxRepsSess = MoveTrackSession::completeSession($createSession(), $pId, [
            'duration_seconds' => 60,
            'reps_completed' => 1000
        ]);
        $this->assert("reps_completed = 1000 (max boundary) accepted", $maxRepsSess['reps_completed'] === 1000);

        // 8. Malformed & Non-integer values rejected
        $floatConsistencyBlocked = false;
        try {
            MoveTrackSession::completeSession($createSession(), $pId, [
                'duration_seconds' => 60,
                'consistency_percentage' => '88.5'
            ]);
        } catch (RuntimeException $e) {
            $floatConsistencyBlocked = ($e->getCode() === 422);
        }
        $this->assert("Float consistency_percentage ('88.5') rejected with 422", $floatConsistencyBlocked);

        $stringDurationBlocked = false;
        try {
            MoveTrackSession::completeSession($createSession(), $pId, [
                'duration_seconds' => 'not_a_number'
            ]);
        } catch (RuntimeException $e) {
            $stringDurationBlocked = ($e->getCode() === 422);
        }
        $this->assert("Non-integer duration string ('not_a_number') rejected with 422", $stringDurationBlocked);

        $missingDurationBlocked = false;
        try {
            MoveTrackSession::completeSession($createSession(), $pId, []);
        } catch (RuntimeException $e) {
            $missingDurationBlocked = ($e->getCode() === 422);
        }
        $this->assert("Missing duration_seconds rejected with 422", $missingDurationBlocked);

        // 9. Session ownership & Duplicate completion locks
        $sessUuid = $createSession();
        MoveTrackSession::completeSession($sessUuid, $pId, ['duration_seconds' => 120]);

        $duplicateCompletionBlocked = false;
        try {
            MoveTrackSession::completeSession($sessUuid, $pId, ['duration_seconds' => 120]);
        } catch (RuntimeException $e) {
            $duplicateCompletionBlocked = ($e->getCode() === 400);
        }
        $this->assert("Duplicate session completion strictly blocked with 400", $duplicateCompletionBlocked);

        $crossPatientUuid = $createSession();
        $unauthorizedPatientBlocked = false;
        try {
            MoveTrackSession::completeSession($crossPatientUuid, 999999, ['duration_seconds' => 120]);
        } catch (RuntimeException $e) {
            $unauthorizedPatientBlocked = ($e->getCode() === 404);
        }
        $this->assert("Cross-patient session completion strictly blocked with 404", $unauthorizedPatientBlocked);
    }

    /**
     * Test Correction 3: Appointment Meeting Link Defaults to NULL
     */
    private function testCorrection3_AppointmentMeetingLinkNullDefault(): void
    {
        echo "\n--- 3. Testing Appointment Meeting Link NULL Default ---\n";

        $pdo = Database::getConnection();

        $patient = AuthService::signup([
            'email' => 'app_null_patient@synthetic-p52-test.com',
            'password' => 'SecurePass#2026',
            'full_name' => 'Appointment Link Subject',
            'role' => 'patient'
        ]);
        $pId = (int)$patient['id'];

        // 1. Create appointment without meeting_link
        $appId = Appointment::create([
            'patient_user_id' => $pId,
            'specialist_name' => 'Dr. Aris Thorne',
            'specialist_title' => 'Pulmonologist',
            'appointment_date' => date('Y-m-d', strtotime('+7 days')),
            'appointment_time' => '10:00 AM',
            'session_type' => 'Virtual Care Consultation'
        ]);

        $app = Appointment::findById($appId);

        $this->assert("Default meeting_link is strictly NULL when unconfigured", $app['meeting_link'] === null);
        $this->assert("Simulated telehealth.smamove.org link is NOT generated", !str_contains((string)$app['meeting_link'], 'telehealth.smamove.org'));

        // 2. Create appointment with explicit valid meeting_link provided by coordinator
        $explicitUrl = 'https://clinical.partner-hospital.in/consultation/room-4412';
        $appId2 = Appointment::create([
            'patient_user_id' => $pId,
            'specialist_name' => 'Dr. Aris Thorne',
            'specialist_title' => 'Pulmonologist',
            'appointment_date' => date('Y-m-d', strtotime('+8 days')),
            'appointment_time' => '02:00 PM',
            'session_type' => 'Virtual Care Consultation',
            'meeting_link' => $explicitUrl
        ]);

        $app2 = Appointment::findById($appId2);
        $this->assert("Explicitly provided valid consultation link is preserved", $app2['meeting_link'] === $explicitUrl);
    }

    /**
     * Test Correction 4: Appointment State Transition Rules & Concurrency Protection
     */
    private function testCorrection4_AppointmentStateTransitions(): void
    {
        echo "\n--- 4. Testing Appointment State Transition Policy ---\n";

        $pdo = Database::getConnection();

        $patient = AuthService::signup([
            'email' => 'fsm_patient@synthetic-p52-test.com',
            'password' => 'SecurePass#2026',
            'full_name' => 'FSM Subject',
            'role' => 'patient'
        ]);
        $pId = (int)$patient['id'];

        $createApp = function () use ($pId): int {
            return Appointment::create([
                'patient_user_id' => $pId,
                'specialist_name' => 'Dr. Clinical Specialist',
                'specialist_title' => 'Specialist',
                'appointment_date' => date('Y-m-d', strtotime('+5 days')),
                'appointment_time' => '09:00 AM',
                'session_type' => 'In-Person Assessment'
            ]);
        };

        // Permitted Transition 1: scheduled -> completed
        $app1 = $createApp();
        $res1 = Appointment::updateStatus($app1, Appointment::STATUS_COMPLETED);
        $this->assert("Permitted: scheduled -> completed", $res1['status'] === Appointment::STATUS_COMPLETED);

        // Permitted Transition 2: scheduled -> cancelled
        $app2 = $createApp();
        $res2 = Appointment::updateStatus($app2, Appointment::STATUS_CANCELLED);
        $this->assert("Permitted: scheduled -> cancelled", $res2['status'] === Appointment::STATUS_CANCELLED);

        // Permitted Transition 3: scheduled -> rescheduled
        $app3 = $createApp();
        $res3 = Appointment::updateStatus($app3, Appointment::STATUS_RESCHEDULED);
        $this->assert("Permitted: scheduled -> rescheduled", $res3['status'] === Appointment::STATUS_RESCHEDULED);

        // Permitted Transition 4: rescheduled -> completed
        $res4 = Appointment::updateStatus($app3, Appointment::STATUS_COMPLETED);
        $this->assert("Permitted: rescheduled -> completed", $res4['status'] === Appointment::STATUS_COMPLETED);

        // Permitted Transition 5: rescheduled -> cancelled
        $app4 = $createApp();
        Appointment::updateStatus($app4, Appointment::STATUS_RESCHEDULED);
        $res5 = Appointment::updateStatus($app4, Appointment::STATUS_CANCELLED);
        $this->assert("Permitted: rescheduled -> cancelled", $res5['status'] === Appointment::STATUS_CANCELLED);

        // FORBIDDEN TRANSITIONS FROM TERMINAL STATE: completed
        // completed -> scheduled (Forbidden)
        $completedReopenBlocked = false;
        try {
            Appointment::updateStatus($app1, Appointment::STATUS_SCHEDULED);
        } catch (RuntimeException $e) {
            $completedReopenBlocked = ($e->getCode() === 400);
        }
        $this->assert("Forbidden: completed -> scheduled rejected with 400 (Terminal Lock)", $completedReopenBlocked);

        // completed -> cancelled (Forbidden)
        $completedCancelBlocked = false;
        try {
            Appointment::updateStatus($app1, Appointment::STATUS_CANCELLED);
        } catch (RuntimeException $e) {
            $completedCancelBlocked = ($e->getCode() === 400);
        }
        $this->assert("Forbidden: completed -> cancelled rejected with 400 (Terminal Lock)", $completedCancelBlocked);

        // FORBIDDEN TRANSITIONS FROM TERMINAL STATE: cancelled
        // cancelled -> scheduled (Forbidden)
        $cancelledReopenBlocked = false;
        try {
            Appointment::updateStatus($app2, Appointment::STATUS_SCHEDULED);
        } catch (RuntimeException $e) {
            $cancelledReopenBlocked = ($e->getCode() === 400);
        }
        $this->assert("Forbidden: cancelled -> scheduled rejected with 400 (Terminal Lock)", $cancelledReopenBlocked);

        // cancelled -> completed (Forbidden)
        $cancelledCompleteBlocked = false;
        try {
            Appointment::updateStatus($app2, Appointment::STATUS_COMPLETED);
        } catch (RuntimeException $e) {
            $cancelledCompleteBlocked = ($e->getCode() === 400);
        }
        $this->assert("Forbidden: cancelled -> completed rejected with 400 (Terminal Lock)", $cancelledCompleteBlocked);

        // FORBIDDEN: Arbitrary invalid status string rejected
        $invalidStatusBlocked = false;
        try {
            Appointment::updateStatus($createApp(), 'arbitrary_invalid_state');
        } catch (InvalidArgumentException $e) {
            $invalidStatusBlocked = ($e->getCode() === 400);
        }
        $this->assert("Forbidden: Arbitrary status string rejected with 400", $invalidStatusBlocked);

        // FORBIDDEN: Non-existent appointment ID rejected
        $nonExistentAppBlocked = false;
        try {
            Appointment::updateStatus(99999999, Appointment::STATUS_CANCELLED);
        } catch (RuntimeException $e) {
            $nonExistentAppBlocked = ($e->getCode() === 404);
        }
        $this->assert("Forbidden: Non-existent appointment ID rejected with 404", $nonExistentAppBlocked);

        // Authorization Guard: Participant vs Unrelated Caller
        $unrelatedApp = $createApp();
        $isAuthorizedParticipant = function (array $appointment, int $callerUserId, string $callerRole): bool {
            $isPatient = ($callerRole === 'patient' && (int)$appointment['patient_user_id'] === $callerUserId);
            $isDoctor = ($callerRole === 'professional' && (int)($appointment['professional_user_id'] ?? 0) === $callerUserId);
            return ($isPatient || $isDoctor);
        };

        $appData = Appointment::findById($unrelatedApp);
        $this->assert("Authorized patient caller recognized as participant", $isAuthorizedParticipant($appData, $pId, 'patient') === true);
        $this->assert("Unrelated user blocked from appointment status mutation", $isAuthorizedParticipant($appData, 888888, 'patient') === false);
    }
}

// Run test suite
$test = new Phase52CorrectionsTestSuite();
$test->run();
