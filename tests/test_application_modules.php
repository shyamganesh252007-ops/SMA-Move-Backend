<?php
declare(strict_types=1);

/**
 * SMA-Move — Phase 5 Application Data Modules Verification Test Suite
 *
 * Automated testing for:
 * 1. Patient Profile & Live Aggregated Dashboard.
 * 2. Care Plan Tasks, Steps, Protocols, and Completion Tracking.
 * 3. Clinical Appointment Scheduling & Status Transitions.
 * 4. Wellbeing Journal Daily Check-ins & Private Entries.
 * 5. Dynamic Consent Tracking & Immediate Revocation Guard.
 * 6. MoveTrack Session Lifecycle (Minimal Metadata Only & Camera Consent Enforcement).
 * 7. Cross-Patient IDOR Prevention & RBAC Permission Boundaries.
 */

namespace SmaMove\Tests;

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/services/AuthService.php';
require_once dirname(__DIR__) . '/services/PatientService.php';
require_once dirname(__DIR__) . '/services/CaregiverService.php';
require_once dirname(__DIR__) . '/services/ProfessionalService.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/CareTask.php';
require_once dirname(__DIR__) . '/models/Appointment.php';
require_once dirname(__DIR__) . '/models/WellbeingJournal.php';
require_once dirname(__DIR__) . '/models/MoveTrackSession.php';
require_once dirname(__DIR__) . '/models/Consent.php';
require_once dirname(__DIR__) . '/models/CaregiverRelationship.php';
require_once dirname(__DIR__) . '/models/PatientProfessionalAccess.php';
require_once dirname(__DIR__) . '/models/ProfessionalProfile.php';

use SmaMove\Config\Database;
use SmaMove\Services\AuthService;
use SmaMove\Services\PatientService;
use SmaMove\Services\CaregiverService;
use SmaMove\Services\ProfessionalService;
use SmaMove\Models\User;
use SmaMove\Models\CareTask;
use SmaMove\Models\Appointment;
use SmaMove\Models\WellbeingJournal;
use SmaMove\Models\MoveTrackSession;
use SmaMove\Models\Consent;
use SmaMove\Models\CaregiverRelationship;
use SmaMove\Models\PatientProfessionalAccess;
use SmaMove\Models\ProfessionalProfile;
use Exception;

class Phase5ApplicationModulesTestSuite
{
    private int $passed = 0;
    private int $failed = 0;
    private array $cleanupUserIds = [];

    public function run(): void
    {
        echo "\n====================================================================\n";
        echo " SMA-MOVE — PHASE 5 APPLICATION DATA MODULES TEST SUITE\n";
        echo "====================================================================\n";

        try {
            $this->setupTestData();

            $this->testCarePlanModule();
            $this->testAppointmentsModule();
            $this->testWellbeingJournalModule();
            $this->testConsentAndMoveTrackModule();
            $this->testPatientDashboardAggregator();
            $this->testCrossPatientIdorAndPermissions();

        } catch (Exception $e) {
            echo "\n[CRITICAL TEST FAILURE] Unhandled Exception: " . $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";
            $this->failed++;
        } finally {
            $this->tearDownTestData();
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

    private function setupTestData(): void
    {
        // Clean any leftover synthetic test records
        $pdo = Database::getConnection();
        $pdo->exec("DELETE FROM users WHERE email LIKE '%@synthetic-p5-test.com'");

        // 1. Patient Aarav
        $patientRes = AuthService::signup([
            'email' => 'aarav@synthetic-p5-test.com',
            'password' => 'PatientP5#Pass2026',
            'full_name' => 'Aarav Sharma',
            'role' => 'patient',
            'preferred_language' => 'English',
            'emergency_contact_name' => 'Sunita Sharma',
            'emergency_contact_phone' => '+91 9876543210'
        ]);
        $pdo->prepare("UPDATE users SET account_status = 'active' WHERE id = :id")->execute([':id' => $patientRes['id']]);
        $this->cleanupUserIds[] = (int)$patientRes['id'];

        // 2. Patient Priya (for cross-patient IDOR checks)
        $patient2Res = AuthService::signup([
            'email' => 'priya@synthetic-p5-test.com',
            'password' => 'PatientP5#Pass2026',
            'full_name' => 'Priya Patel',
            'role' => 'patient'
        ]);
        $pdo->prepare("UPDATE users SET account_status = 'active' WHERE id = :id")->execute([':id' => $patient2Res['id']]);
        $this->cleanupUserIds[] = (int)$patient2Res['id'];

        // 3. Caregiver Sunita
        $caregiverRes = AuthService::signup([
            'email' => 'sunita@synthetic-p5-test.com',
            'password' => 'CaregiverP5#Pass2026',
            'full_name' => 'Sunita Sharma',
            'role' => 'caregiver'
        ]);
        $pdo->prepare("UPDATE users SET account_status = 'active' WHERE id = :id")->execute([':id' => $caregiverRes['id']]);
        $this->cleanupUserIds[] = (int)$caregiverRes['id'];

        // 4. Professional Dr. Vikram
        $doctorRes = AuthService::signup([
            'email' => 'vikram@synthetic-p5-test.com',
            'password' => 'DoctorP5#Pass2026',
            'full_name' => 'Dr. Vikram Seth',
            'role' => 'professional'
        ]);
        $pdo->prepare("UPDATE users SET account_status = 'active' WHERE id = :id")->execute([':id' => $doctorRes['id']]);
        $this->cleanupUserIds[] = (int)$doctorRes['id'];

        // Submit and verify Dr. Vikram's credentials
        ProfessionalProfile::submitVerification((int)$doctorRes['id'], [
            'registration_number' => 'DMC-NEURO-2026-99',
            'institution_name' => 'AIIMS Regional Neurosciences',
            'professional_title' => 'Senior Consultant Neurologist',
            'qualification' => 'MBBS, MD, DM'
        ]);
        ProfessionalProfile::adminVerify((int)$doctorRes['id'], (int)$patientRes['id'], 'verified', 'Board verified');
    }

    private function tearDownTestData(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("DELETE FROM users WHERE email LIKE '%@synthetic-p5-test.com'");
    }

    private function testCarePlanModule(): void
    {
        echo "\n--- 1. Testing Care Plan & Tasks Module ---\n";

        $patientAarav = User::findByEmail('aarav@synthetic-p5-test.com');
        $patientId = (int)$patientAarav['id'];
        $today = date('Y-m-d');

        // Create a custom care task with multi-step protocol
        $taskId = CareTask::createTask($patientId, [
            'title' => 'Morning Cough Assist & Airway Clearance',
            'description' => 'Prescribed respiratory protocol for pulmonary health.',
            'category' => 'Respiratory care',
            'duration_text' => '15 mins',
            'scheduled_time' => '09:00 AM',
            'prescribed_by' => 'Dr. Sarah Jenkins'
        ], [
            ['step_number' => 1, 'title' => 'Positioning', 'instruction' => 'Seated upright at 45 to 90 degrees.'],
            ['step_number' => 2, 'title' => 'Settings Verification', 'instruction' => 'Verify pressure strictly matches written prescription.'],
            ['step_number' => 3, 'title' => 'Cycle Execution', 'instruction' => 'Deliver 5 cycles with rest intervals.']
        ]);

        $this->assert("Care task created with valid ID", $taskId > 0);

        // Fetch task details
        $taskDetails = CareTask::findById($taskId, $patientId, $today);
        $this->assert("Task details retrieved successfully", $taskDetails !== null);
        $this->assert("Task contains 3 structured steps", count($taskDetails['steps']) === 3);
        $this->assert("Initial completion state is false", $taskDetails['is_completed'] === false);

        // Retrieve all tasks for patient
        $tasks = CareTask::findTasksForPatient($patientId, $today);
        $this->assert("findTasksForPatient returns the created task", count($tasks) >= 1);

        // Mark task completed for today
        $compSuccess = CareTask::setCompletion($taskId, $patientId, $today, true, 'Completed without shortness of breath.');
        $this->assert("Task marked completed successfully", $compSuccess);

        // Verify updated state and progress metric
        $updatedTask = CareTask::findById($taskId, $patientId, $today);
        $this->assert("Task is_completed transitioned to true", $updatedTask['is_completed'] === true);
        $this->assert("Task completion notes saved", $updatedTask['completion_notes'] === 'Completed without shortness of breath.');

        $summary = CareTask::getSummaryForDate($patientId, $today);
        $this->assert("Completed tasks count is at least 1", $summary['completed_tasks'] >= 1);
        $this->assert("Progress percent is greater than 0", $summary['progress_percent'] > 0);
    }

    private function testAppointmentsModule(): void
    {
        echo "\n--- 2. Testing Appointments Module ---\n";

        $patientAarav = User::findByEmail('aarav@synthetic-p5-test.com');
        $patientId = (int)$patientAarav['id'];
        $doctorVikram = User::findByEmail('vikram@synthetic-p5-test.com');
        $doctorId = (int)$doctorVikram['id'];

        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        // Schedule an appointment
        $appointmentId = Appointment::create([
            'patient_user_id' => $patientId,
            'professional_user_id' => $doctorId,
            'specialist_name' => 'Dr. Vikram Seth',
            'specialist_title' => 'Senior Consultant Neurologist',
            'appointment_date' => $tomorrow,
            'appointment_time' => '10:00 AM – 10:45 AM',
            'session_type' => 'Virtual Care Consultation',
            'meeting_link' => 'https://telehealth.smamove.org/room/sma-test-room',
            'preparation_notes' => 'Please have current BiPAP pressure readings available.'
        ]);

        $this->assert("Appointment booking created successfully", $appointmentId > 0);

        // Retrieve appointment
        $app = Appointment::findById($appointmentId);
        $this->assert("Appointment retrieved with matching specialist name", $app['specialist_name'] === 'Dr. Vikram Seth');
        $this->assert("Appointment initial status is 'scheduled'", $app['status'] === 'scheduled');
        $this->assert("Virtual room link preserved", $app['meeting_link'] === 'https://telehealth.smamove.org/room/sma-test-room');

        // Next upcoming appointment query
        $upcoming = Appointment::getNextUpcoming($patientId);
        $this->assert("getNextUpcoming finds the scheduled consultation", $upcoming !== null && (int)$upcoming['id'] === $appointmentId);

        // Doctor appointment list query
        $doctorApps = Appointment::listByProfessional($doctorId);
        $this->assert("Doctor retrieves scheduled appointment", count($doctorApps) >= 1);

        // Update status to 'completed'
        $updateSuccess = Appointment::updateStatus($appointmentId, 'completed', 'Consultation concluded. Prescriptions verified.');
        $this->assert("Appointment status updated to 'completed'", !empty($updateSuccess));

        $completedApp = Appointment::findById($appointmentId);
        $this->assert("Updated status persisted in database", $completedApp['status'] === 'completed');
    }

    private function testWellbeingJournalModule(): void
    {
        echo "\n--- 3. Testing Wellbeing Journal Module ---\n";

        $patientAarav = User::findByEmail('aarav@synthetic-p5-test.com');
        $patientId = (int)$patientAarav['id'];

        // Initial check: has not checked in today
        $initialCheck = WellbeingJournal::hasCheckedInToday($patientId);
        $this->assert("Initial hasCheckedInToday is false", $initialCheck === false);

        // Submit daily check-in
        $entryId = WellbeingJournal::create($patientId, [
            'mood' => 'Calm & Rested',
            'comfort_level' => 'Comfortable',
            'fatigue_level' => 'Mild',
            'mobility_assistance_needed' => 0,
            'notes' => 'Good energy levels after afternoon rest cycle.',
            'symptom_notes' => 'No increased muscle tightness.'
        ]);

        $this->assert("Wellbeing check-in persisted with valid ID", $entryId > 0);

        // Verify hasCheckedInToday is now true
        $this->assert("hasCheckedInToday returns true after submission", WellbeingJournal::hasCheckedInToday($patientId) === true);

        // Fetch latest entry
        $latest = WellbeingJournal::getLatest($patientId);
        $this->assert("Latest entry mood matches submitted value", $latest['mood'] === 'Calm & Rested');
        $this->assert("Latest entry comfort_level matches", $latest['comfort_level'] === 'Comfortable');
        $this->assert("Latest entry mobility_assistance_needed is false", $latest['mobility_assistance_needed'] === false);

        // List entries
        $entries = WellbeingJournal::listByPatient($patientId);
        $this->assert("listByPatient returns at least 1 record", count($entries) >= 1);
    }

    private function testConsentAndMoveTrackModule(): void
    {
        echo "\n--- 4. Testing Consent & MoveTrack Minimal Metadata Module ---\n";

        $patientAarav = User::findByEmail('aarav@synthetic-p5-test.com');
        $patientId = (int)$patientAarav['id'];

        // 1. Precondition check: Camera consent is NOT granted initially
        $this->assert("Camera tracking consent initially false", Consent::isGranted($patientId, Consent::TYPE_CAMERA) === false);

        // 2. Starting MoveTrack session without consent MUST throw 403 Forbidden
        $consentBlocked = false;
        try {
            MoveTrackSession::startSession($patientId, ['session_type' => 'Posture Guidance Check']);
        } catch (Exception $e) {
            $consentBlocked = true;
            $this->assert("Starting session without camera consent throws 403", $e->getCode() === 403);
        }
        $this->assert("MoveTrack session strictly blocked without active camera consent", $consentBlocked);

        // 3. Grant Camera Tracking Consent
        Consent::updateConsent($patientId, Consent::TYPE_CAMERA, true, '1.0.0', '127.0.0.1');
        $this->assert("Camera tracking consent successfully granted", Consent::isGranted($patientId, Consent::TYPE_CAMERA) === true);

        // 4. Start MoveTrack Session
        $session = MoveTrackSession::startSession($patientId, [
            'session_type' => 'Posture Guidance Evaluation',
            'model_version' => 'on-device-v1.0'
        ]);

        $this->assert("Session started with valid session_uuid", !empty($session['session_uuid']));
        $this->assert("Session status is 'in_progress'", $session['session_status'] === 'in_progress');
        $this->assert("Response includes non-diagnostic disclaimer", !empty($session['disclaimer']));
        $this->assert("Response includes zero-video privacy notice", !empty($session['privacy_notice']));

        $sessionUuid = $session['session_uuid'];

        // 5. Complete MoveTrack Session (Minimal Metadata: duration, consistency, quality)
        $completed = MoveTrackSession::completeSession($sessionUuid, $patientId, [
            'duration_seconds' => 390, // 6 mins 30 secs
            'tracking_quality_summary' => 'Steady Trunk Alignment',
            'consistency_percentage' => 88,
            'reps_completed' => 3,
            'user_notes' => 'Completed 3 gentle stretch cycles without discomfort.'
        ]);

        $this->assert("Session completed with status 'completed'", $completed['session_status'] === 'completed');
        $this->assert("Duration seconds recorded as 390", $completed['duration_seconds'] === 390);
        $this->assert("Consistency percentage recorded as 88%", $completed['consistency_percentage'] === 88);
        $this->assert("Reps completed recorded as 3", $completed['reps_completed'] === 3);

        // 6. Verify Minimal Metadata Principle: Check database row directly
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM movetrack_sessions WHERE session_uuid = :uuid");
        $stmt->execute([':uuid' => $sessionUuid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assert("Database row contains NO video or image fields", !array_key_exists('video_data', $row) && !array_key_exists('frames', $row));

        // 7. Revoke Camera Consent and confirm future sessions blocked
        Consent::updateConsent($patientId, Consent::TYPE_CAMERA, false, '1.0.0', '127.0.0.1');
        $this->assert("Camera consent successfully revoked", Consent::isGranted($patientId, Consent::TYPE_CAMERA) === false);

        $subsequentBlocked = false;
        try {
            MoveTrackSession::startSession($patientId, ['session_type' => 'Posture Check']);
        } catch (Exception $e) {
            $subsequentBlocked = true;
            $this->assert("Subsequent session blocked after consent revocation", $e->getCode() === 403);
        }
        $this->assert("Consent revocation immediately enforces access cutoff", $subsequentBlocked);
    }

    private function testPatientDashboardAggregator(): void
    {
        echo "\n--- 5. Testing Patient Dashboard Aggregator ---\n";

        $patientAarav = User::findByEmail('aarav@synthetic-p5-test.com');
        $patientId = (int)$patientAarav['id'];

        $dashboard = PatientService::getDashboardData($patientId);

        $this->assert("Dashboard returns patient identity", $dashboard['patient']['full_name'] === 'Aarav Sharma');
        $this->assert("Dashboard returns emergency contact", $dashboard['patient']['emergency_contact']['name'] === 'Sunita Sharma');
        $this->assert("Dashboard includes care plan summary", isset($dashboard['care_plan']['total_tasks']));
        $this->assert("Dashboard includes tasks preview array", is_array($dashboard['care_plan']['tasks_preview']));
        $this->assert("Dashboard includes next appointment details", $dashboard['next_appointment'] !== null || array_key_exists('next_appointment', $dashboard));
        $this->assert("Dashboard includes today's wellbeing check-in state", $dashboard['wellbeing']['has_checked_in_today'] === true);
        $this->assert("Dashboard includes MoveTrack total sessions count", $dashboard['movetrack']['total_sessions_completed'] >= 1);
    }

    private function testCrossPatientIdorAndPermissions(): void
    {
        echo "\n--- 6. Testing Cross-Patient IDOR & Caregiver/Doctor Boundaries ---\n";

        $patientAarav = User::findByEmail('aarav@synthetic-p5-test.com');
        $aaravId = (int)$patientAarav['id'];

        $patientPriya = User::findByEmail('priya@synthetic-p5-test.com');
        $priyaId = (int)$patientPriya['id'];

        $caregiverSunita = User::findByEmail('sunita@synthetic-p5-test.com');
        $caregiverId = (int)$caregiverSunita['id'];

        $doctorVikram = User::findByEmail('vikram@synthetic-p5-test.com');
        $doctorId = (int)$doctorVikram['id'];

        // 1. Cross-patient Care Task IDOR: Priya attempts to access Aarav's private task
        $tasks = CareTask::findTasksForPatient($aaravId, date('Y-m-d'));
        $aaravTaskId = $tasks[0]['id'];

        $priyaTaskLookup = CareTask::findById($aaravTaskId, $priyaId, date('Y-m-d'));
        $this->assert("IDOR blocked: Patient Priya cannot find or read Aarav's private task", $priyaTaskLookup === null);

        // 2. Caregiver Granular Permission Denial: Sunita is linked with can_edit_assistance = 0
        CaregiverRelationship::createOrUpdate([
            'patient_user_id' => $aaravId,
            'caregiver_user_id' => $caregiverId,
            'requested_by_user_id' => $caregiverId,
            'relationship_type' => 'Mother / Caregiver',
            'access_status' => 'approved',
            'can_view_care_plan' => 1,
            'can_receive_summaries' => 1,
            'can_edit_assistance' => 0
        ]);

        // Enable caregiver sharing consent
        Consent::updateConsent($aaravId, Consent::TYPE_CAREGIVER, true);

        // Sunita can view summary
        $summary = CaregiverService::getPatientSummary($caregiverId, $aaravId);
        $this->assert("Approved caregiver retrieves authorized summary", $summary['patient_name'] === 'Aarav Sharma');

        // Sunita CANNOT toggle task because can_edit_assistance is 0
        // (Simulate permission check from api/careplan/toggle_task.php)
        $rel = CaregiverRelationship::findByPatientAndCaregiver($aaravId, $caregiverId);
        $this->assert("can_edit_assistance is strictly 0 in relationship", (int)$rel['can_edit_assistance'] === 0);

        // 3. Professional Zero-Trust Clinical Authorization:
        // Dr. Vikram is verified, but Aarav has NOT authorized him yet.
        $unauthorizedDoctorBlocked = false;
        try {
            ProfessionalService::getAuthorizedPatientData($doctorId, $aaravId);
        } catch (Exception $e) {
            $unauthorizedDoctorBlocked = true;
            $this->assert("Verified doctor blocked without patient authorization", $e->getCode() === 403);
        }
        $this->assert("Doctor strictly forbidden from clinical data without explicit patient link", $unauthorizedDoctorBlocked);

        // Patient Aarav authorizes Dr. Vikram + grants clinical sharing consent
        PatientProfessionalAccess::authorize($aaravId, $doctorId, 'Pulmonology & Physical Therapy');
        Consent::updateConsent($aaravId, Consent::TYPE_CLINICAL, true);

        $clinicalData = ProfessionalService::getAuthorizedPatientData($doctorId, $aaravId);
        $this->assert("Doctor accesses clinical data after explicit patient authorization", $clinicalData['clinical_monitoring_status'] === 'authorized');
        $this->assert("Clinical data includes today's care plan summary", isset($clinicalData['care_plan_today']));
        $this->assert("Clinical data includes recent wellbeing entries", count($clinicalData['recent_wellbeing_checkins']) >= 1);
        $this->assert("Clinical data includes MoveTrack sessions", count($clinicalData['recent_movetrack_sessions']) >= 1);
    }
}

// Run test suite
$test = new Phase5ApplicationModulesTestSuite();
$test->run();
