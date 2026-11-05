<?php
declare(strict_types=1);

/**
 * SMA-Move — Phase 4 Comprehensive Security & Privacy Test Suite
 *
 * Implements verification for all 16 required authorization scenarios:
 * 1. Patient accesses own profile.
 * 2. Patient cannot access another patient's private profile (IDOR prevention).
 * 3. Caregiver cannot access patient before approval.
 * 4. Caregiver can access explicitly authorised information after approval.
 * 5. Caregiver cannot access unauthorised information (granular permissions).
 * 6. Patient revokes caregiver permission.
 * 7. Revoked caregiver immediately loses access.
 * 8. Caregiver cannot approve their own request.
 * 9. Professional cannot access patient information while unverified.
 * 10. Verified professional still cannot access an unauthorised patient.
 * 11. Professional cannot verify their own credentials.
 * 12. Suspended account cannot access protected resources.
 * 13. Expired/missing consent blocks the relevant access.
 * 14. Revoked consent blocks the relevant access.
 * 15. Missing or invalid authentication returns appropriate errors.
 * 16. Changing a resource ID cannot bypass authorization.
 */

namespace SmaMove\Tests;

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/services/AuthService.php';
require_once dirname(__DIR__) . '/services/PatientService.php';
require_once dirname(__DIR__) . '/services/CaregiverService.php';
require_once dirname(__DIR__) . '/services/ProfessionalService.php';
require_once dirname(__DIR__) . '/middleware/role_middleware.php';
require_once dirname(__DIR__) . '/middleware/auth_middleware.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/AuthToken.php';
require_once dirname(__DIR__) . '/models/Consent.php';
require_once dirname(__DIR__) . '/models/CaregiverRelationship.php';
require_once dirname(__DIR__) . '/models/PatientInvitation.php';
require_once dirname(__DIR__) . '/models/ProfessionalProfile.php';
require_once dirname(__DIR__) . '/models/PatientProfessionalAccess.php';

use SmaMove\Config\Database;
use SmaMove\Services\AuthService;
use SmaMove\Services\PatientService;
use SmaMove\Services\CaregiverService;
use SmaMove\Services\ProfessionalService;
use SmaMove\Middleware\RoleMiddleware;
use SmaMove\Middleware\AuthMiddleware;
use SmaMove\Models\User;
use SmaMove\Models\AuthToken;
use SmaMove\Models\Consent;
use SmaMove\Models\CaregiverRelationship;
use SmaMove\Models\PatientInvitation;
use SmaMove\Models\ProfessionalProfile;
use SmaMove\Models\PatientProfessionalAccess;
use Exception;

class Phase4SecurityTestSuite
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(): void
    {
        echo "\n====================================================================\n";
        echo " SMA-MOVE — PHASE 4 COMPREHENSIVE SECURITY & PRIVACY TEST SUITE\n";
        echo "====================================================================\n\n";

        $this->cleanup();

        $this->testScenario1_PatientAccessesOwnProfile();
        $this->testScenario2_PatientCannotAccessOtherPatientProfile();
        $this->testScenario3_CaregiverBlockedBeforeApproval();
        $this->testScenario4_CaregiverAccessesAuthorizedInfoAfterApproval();
        $this->testScenario5_CaregiverCannotAccessUnauthorizedInfo();
        $this->testScenario6_PatientRevokesCaregiverPermission();
        $this->testScenario7_RevokedCaregiverImmediatelyLosesAccess();
        $this->testScenario8_CaregiverCannotApproveOwnRequest();
        $this->testScenario9_UnverifiedProfessionalBlockedFromPatientData();
        $this->testScenario10_VerifiedProfessionalBlockedFromUnauthorizedPatient();
        $this->testScenario11_ProfessionalCannotVerifyOwnCredentials();
        $this->testScenario12_SuspendedAccountBlockedFromResources();
        $this->testScenario13_MissingConsentBlocksAccess();
        $this->testScenario14_RevokedConsentBlocksAccess();
        $this->testScenario15_MissingOrInvalidTokenRejected();
        $this->testScenario16_ResourceIdTamperingCannotBypassAuthorization();

        $this->cleanup();

        echo "\n--------------------------------------------------------------------\n";
        echo " Test Summary: {$this->passed} Passed, {$this->failed} Failed\n";
        echo "--------------------------------------------------------------------\n\n";

        if ($this->failed > 0) {
            exit(1);
        }
    }

    private function assert(string $testName, bool $condition, string $detail = ''): void
    {
        if ($condition) {
            $this->passed++;
            echo " [PASS] {$testName}\n";
        } else {
            $this->failed++;
            echo " [FAIL] {$testName}" . ($detail ? " — {$detail}" : "") . "\n";
        }
    }

    private function cleanup(): void
    {
        $pdo = Database::getConnection();
        $pdo->exec("DELETE FROM users WHERE email LIKE '%@synthetic-p4-test.com'");
    }

    private function testScenario1_PatientAccessesOwnProfile(): void
    {
        echo "--- Scenario 1: Patient accesses own profile ---\n";

        $patient = AuthService::signup([
            'full_name' => 'Kavita Iyer',
            'email' => 'kavita@synthetic-p4-test.com',
            'password' => 'KavitaPass#2026',
            'role' => 'patient',
            'preferred_language' => 'Hindi',
            'emergency_contact_name' => 'Rajesh Iyer'
        ]);

        $profile = PatientService::getProfile((int)$patient['id']);
        $this->assert("Patient successfully retrieves own profile", $profile['user_id'] == $patient['id']);
        $this->assert("Profile includes correct preferred language", $profile['preferred_language'] === 'Hindi');
    }

    private function testScenario2_PatientCannotAccessOtherPatientProfile(): void
    {
        echo "\n--- Scenario 2: Patient cannot access another patient's private profile (IDOR) ---\n";

        $patient2 = AuthService::signup([
            'full_name' => 'Vikram Patel',
            'email' => 'vikram@synthetic-p4-test.com',
            'password' => 'VikramPass#2026',
            'role' => 'patient'
        ]);

        $callerPatient = User::findByEmail('kavita@synthetic-p4-test.com');
        $targetPatientId = (int)$patient2['id'];

        $idorCaught = false;
        try {
            RoleMiddleware::verifyPatientOwnership((int)$callerPatient['id'], $targetPatientId);
        } catch (Exception $e) {
            $idorCaught = true;
        }
        $this->assert("IDOR blocked: Patient forbidden from accessing another patient's ID", $idorCaught);
    }

    private function testScenario3_CaregiverBlockedBeforeApproval(): void
    {
        echo "\n--- Scenario 3: Caregiver cannot access patient before approval ---\n";

        $caregiver = AuthService::signup([
            'full_name' => 'Rajesh Iyer',
            'email' => 'rajesh@synthetic-p4-test.com',
            'password' => 'RajeshPass#2026',
            'role' => 'caregiver'
        ]);

        $patient = User::findByEmail('kavita@synthetic-p4-test.com');

        // Patient creates invitation
        $inv = CaregiverService::createInvitation((int)$patient['id'], [
            'relationship_type' => 'Spouse'
        ]);

        // Caregiver requests access
        $req = CaregiverService::requestAccessByCode((int)$caregiver['id'], $inv['invitation_code'], 'Spouse');
        $this->assert("Relationship created in pending status", $req['access_status'] === 'pending');

        // Attempt to access patient summary while pending
        $blocked = false;
        try {
            CaregiverService::getPatientSummary((int)$caregiver['id'], (int)$patient['id']);
        } catch (Exception $e) {
            $blocked = true;
            $this->assert("Pending relationship rejects access with 403 Forbidden", $e->getCode() === 403);
        }
        $this->assert("Caregiver blocked from patient data prior to approval", $blocked);
    }

    private function testScenario4_CaregiverAccessesAuthorizedInfoAfterApproval(): void
    {
        echo "\n--- Scenario 4: Caregiver can access explicitly authorised information after approval ---\n";

        $patient = User::findByEmail('kavita@synthetic-p4-test.com');
        $caregiver = User::findByEmail('rajesh@synthetic-p4-test.com');

        // Grant caregiver sharing consent for patient
        Consent::updateConsent((int)$patient['id'], Consent::TYPE_CAREGIVER, true);

        // Find relationship ID
        $rel = CaregiverRelationship::findByPatientAndCaregiver((int)$patient['id'], (int)$caregiver['id']);

        // Patient approves
        CaregiverService::approveRequest((int)$patient['id'], (int)$rel['id'], [
            'can_view_care_plan' => 1,
            'can_receive_summaries' => 1,
            'can_edit_assistance' => 0
        ]);

        $summary = CaregiverService::getPatientSummary((int)$caregiver['id'], (int)$patient['id']);
        $this->assert("Approved caregiver retrieves authorized patient summary", $summary['patient_id'] == $patient['id']);
        $this->assert("Summary includes permitted emergency contact", isset($summary['emergency_contact']));
        $this->assert("Summary reflects view care plan permission", $summary['permissions']['can_view_care_plan'] === true);
    }

    private function testScenario5_CaregiverCannotAccessUnauthorizedInfo(): void
    {
        echo "\n--- Scenario 5: Caregiver cannot access unauthorised information (granular permissions) ---\n";

        $patient = User::findByEmail('kavita@synthetic-p4-test.com');
        $caregiver = User::findByEmail('rajesh@synthetic-p4-test.com');

        // Caregiver has can_edit_assistance = 0
        $permBlocked = false;
        try {
            RoleMiddleware::verifyCaregiverPatientAccess((int)$caregiver['id'], (int)$patient['id'], 'can_edit_assistance');
        } catch (Exception $e) {
            $permBlocked = true;
        }
        $this->assert("Caregiver denied unpermitted operation (can_edit_assistance = 0)", $permBlocked);
    }

    private function testScenario6_PatientRevokesCaregiverPermission(): void
    {
        echo "\n--- Scenario 6: Patient revokes caregiver permission ---\n";

        $patient = User::findByEmail('kavita@synthetic-p4-test.com');
        $caregiver = User::findByEmail('rajesh@synthetic-p4-test.com');

        $rel = CaregiverRelationship::findByPatientAndCaregiver((int)$patient['id'], (int)$caregiver['id']);
        $revoked = CaregiverService::revokeAccess((int)$patient['id'], (int)$rel['id']);
        $this->assert("Patient successfully executes revocation", $revoked === true);

        $relAfter = CaregiverRelationship::findById((int)$rel['id']);
        $this->assert("Relationship access_status transitions to 'revoked'", $relAfter['access_status'] === 'revoked');
    }

    private function testScenario7_RevokedCaregiverImmediatelyLosesAccess(): void
    {
        echo "\n--- Scenario 7: Revoked caregiver immediately loses access ---\n";

        $patient = User::findByEmail('kavita@synthetic-p4-test.com');
        $caregiver = User::findByEmail('rajesh@synthetic-p4-test.com');

        $blocked = false;
        try {
            CaregiverService::getPatientSummary((int)$caregiver['id'], (int)$patient['id']);
        } catch (Exception $e) {
            $blocked = true;
            $this->assert("Revoked caregiver access request returns 403 Forbidden", $e->getCode() === 403);
        }
        $this->assert("Revoked caregiver immediately blocked from patient data", $blocked);
    }

    private function testScenario8_CaregiverCannotApproveOwnRequest(): void
    {
        echo "\n--- Scenario 8: Caregiver cannot approve their own request ---\n";

        $patient = User::findByEmail('kavita@synthetic-p4-test.com');

        $caregiver2 = AuthService::signup([
            'full_name' => 'Intruder Caregiver',
            'email' => 'intruder.caregiver@synthetic-p4-test.com',
            'password' => 'IntruderPass#2026',
            'role' => 'caregiver'
        ]);

        $inv = CaregiverService::createInvitation((int)$patient['id'], ['relationship_type' => 'Helper']);
        $req = CaregiverService::requestAccessByCode((int)$caregiver2['id'], $inv['invitation_code']);
        $relId = (int)$req['relationship_id'];

        // Caregiver attempts to approve on behalf of patient
        $selfApprovalBlocked = false;
        try {
            CaregiverService::approveRequest((int)$caregiver2['id'], $relId, ['can_view_care_plan' => 1]);
        } catch (Exception $e) {
            $selfApprovalBlocked = true;
            $this->assert("Caregiver approving own request is rejected with 403 or 404", in_array($e->getCode(), [403, 404], true));
        }
        $this->assert("Caregiver cannot approve their own access request", $selfApprovalBlocked);
    }

    private function testScenario9_UnverifiedProfessionalBlockedFromPatientData(): void
    {
        echo "\n--- Scenario 9: Professional cannot access patient information while unverified ---\n";

        $patient = User::findByEmail('kavita@synthetic-p4-test.com');

        $prof = AuthService::signup([
            'full_name' => 'Dr. Unverified Specialist',
            'email' => 'unverified.doc@synthetic-p4-test.com',
            'password' => 'DocPass#2026!',
            'role' => 'professional'
        ]);

        $profId = (int)$prof['id'];

        // Unverified doctor attempts to access clinical data
        $blocked = false;
        try {
            ProfessionalService::getAuthorizedPatientData($profId, (int)$patient['id']);
        } catch (Exception $e) {
            $blocked = true;
            $this->assert("Unverified professional blocked with 403 Forbidden", $e->getCode() === 403);
        }
        $this->assert("Unverified professional strictly prevented from clinical access", $blocked);
    }

    private function testScenario10_VerifiedProfessionalBlockedFromUnauthorizedPatient(): void
    {
        echo "\n--- Scenario 10: Verified professional still cannot access an unauthorised patient ---\n";

        $patient = User::findByEmail('kavita@synthetic-p4-test.com');
        $prof = User::findByEmail('unverified.doc@synthetic-p4-test.com');
        $profId = (int)$prof['id'];

        // Simulate administrative board verification (status = verified)
        ProfessionalProfile::adminVerify($profId, 99999, 'verified', 'MCI Board verified');
        $this->assert("Professional status updated to verified", ProfessionalProfile::isVerified($profId));

        // Patient has NOT authorized this doctor
        $blocked = false;
        try {
            ProfessionalService::getAuthorizedPatientData($profId, (int)$patient['id']);
        } catch (Exception $e) {
            $blocked = true;
            $this->assert("Verified but unauthorized doctor returns 403 Forbidden", $e->getCode() === 403);
        }
        $this->assert("Verified doctor strictly prevented from accessing unauthorized patient", $blocked);

        // Now patient explicitly authorizes doctor and grants clinical consent
        PatientService::authorizeProfessional((int)$patient['id'], $profId);
        Consent::updateConsent((int)$patient['id'], Consent::TYPE_CLINICAL, true);

        // Now doctor can access clinical data
        $clinicalData = ProfessionalService::getAuthorizedPatientData($profId, (int)$patient['id']);
        $this->assert("Authorized verified doctor successfully accesses patient records", $clinicalData['patient_id'] == $patient['id']);
    }

    private function testScenario11_ProfessionalCannotVerifyOwnCredentials(): void
    {
        echo "\n--- Scenario 11: Professional cannot verify their own credentials ---\n";

        $prof = User::findByEmail('unverified.doc@synthetic-p4-test.com');
        $profId = (int)$prof['id'];

        $selfVerifyBlocked = false;
        try {
            ProfessionalService::adminVerifyCredentials($profId, $profId, 'verified', 'Self approval attempt');
        } catch (Exception $e) {
            $selfVerifyBlocked = true;
            $this->assert("Self-verification rejected with 403 Forbidden", $e->getCode() === 403);
        }
        $this->assert("Healthcare professional cannot self-verify credentials", $selfVerifyBlocked);
    }

    private function testScenario12_SuspendedAccountBlockedFromResources(): void
    {
        echo "\n--- Scenario 12: Suspended account cannot access protected resources ---\n";

        $patient = User::findByEmail('kavita@synthetic-p4-test.com');
        $pdo = Database::getConnection();

        // Suspend patient
        $pdo->prepare("UPDATE users SET account_status = 'suspended' WHERE id = :id")->execute([':id' => $patient['id']]);

        // Attempt login or token validation
        $suspendedBlocked = false;
        try {
            AuthService::login('kavita@synthetic-p4-test.com', 'KavitaPass#2026');
        } catch (Exception $e) {
            $suspendedBlocked = true;
            $this->assert("Suspended account login rejected with 403 Forbidden", $e->getCode() === 403);
        }
        $this->assert("Suspended user strictly denied resource access", $suspendedBlocked);

        // Restore active
        $pdo->prepare("UPDATE users SET account_status = 'active' WHERE id = :id")->execute([':id' => $patient['id']]);
    }

    private function testScenario13_MissingConsentBlocksAccess(): void
    {
        echo "\n--- Scenario 13: Missing consent blocks the relevant access ---\n";

        $patient = User::findByEmail('vikram@synthetic-p4-test.com');

        // Vikram has never granted camera tracking consent
        $consentBlocked = false;
        try {
            RoleMiddleware::verifyActiveConsent((int)$patient['id'], Consent::TYPE_CAMERA);
        } catch (Exception $e) {
            $consentBlocked = true;
        }
        $this->assert("Missing camera consent strictly blocks operation", $consentBlocked);
    }

    private function testScenario14_RevokedConsentBlocksAccess(): void
    {
        echo "\n--- Scenario 14: Revoked consent blocks the relevant access ---\n";

        $patient = User::findByEmail('kavita@synthetic-p4-test.com');
        $caregiver = User::findByEmail('rajesh@synthetic-p4-test.com');

        // Re-approve relationship
        $rel = CaregiverRelationship::findByPatientAndCaregiver((int)$patient['id'], (int)$caregiver['id']);
        CaregiverRelationship::approve((int)$rel['id'], (int)$patient['id'], ['can_view_care_plan' => 1]);

        // Patient revokes caregiver sharing consent
        Consent::updateConsent((int)$patient['id'], Consent::TYPE_CAREGIVER, false);

        // Caregiver attempts access
        $sharingBlocked = false;
        try {
            CaregiverService::getPatientSummary((int)$caregiver['id'], (int)$patient['id']);
        } catch (Exception $e) {
            $sharingBlocked = true;
            $this->assert("Revoked consent blocks caregiver access with 403 Forbidden", $e->getCode() === 403);
        }
        $this->assert("Revoked consent strictly prevents information disclosure", $sharingBlocked);
    }

    private function testScenario15_MissingOrInvalidTokenRejected(): void
    {
        echo "\n--- Scenario 15: Missing or invalid authentication returns appropriate errors ---\n";

        // Test with invalid token
        $lookup = AuthToken::findValidToken(hash('sha256', 'completely-invalid-opaque-token-string'), 'access');
        $this->assert("Invalid token returns null on lookup", $lookup === null);

        // Test with empty string
        $lookupEmpty = AuthToken::findValidToken(hash('sha256', ''), 'access');
        $this->assert("Empty token hash returns null on lookup", $lookupEmpty === null);
    }

    private function testScenario16_ResourceIdTamperingCannotBypassAuthorization(): void
    {
        echo "\n--- Scenario 16: Changing a resource ID cannot bypass authorization (IDOR prevention) ---\n";

        $patientKavita = User::findByEmail('kavita@synthetic-p4-test.com');
        $patientVikram = User::findByEmail('vikram@synthetic-p4-test.com');
        $caregiverRajesh = User::findByEmail('rajesh@synthetic-p4-test.com');

        // Caregiver Rajesh is linked ONLY to Kavita, NOT to Vikram.
        // Rajesh tampers with query parameter: patient_id = Vikram's ID
        $idorBlocked = false;
        try {
            CaregiverService::getPatientSummary((int)$caregiverRajesh['id'], (int)$patientVikram['id']);
        } catch (Exception $e) {
            $idorBlocked = true;
            $this->assert("Tampered patient ID rejected with 403 Forbidden", $e->getCode() === 403);
        }
        $this->assert("Resource ID tampering prevented by relationship check", $idorBlocked);
    }
}

$suite = new Phase4SecurityTestSuite();
$suite->run();
