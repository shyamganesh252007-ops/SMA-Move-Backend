<?php
declare(strict_types=1);

/**
 * SMA-Move — Phase 3 Comprehensive Authentication & Security Test Suite
 *
 * Validates registration across all 3 roles, token hashing, expiration,
 * revocation, login, account statuses, password recovery, and session invalidation.
 */

namespace SmaMove\Tests;

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/services/AuthService.php';
require_once dirname(__DIR__) . '/middleware/auth_middleware.php';
require_once dirname(__DIR__) . '/utils/token.php';
require_once dirname(__DIR__) . '/utils/validation.php';
require_once dirname(__DIR__) . '/models/User.php';
require_once dirname(__DIR__) . '/models/AuthToken.php';
require_once dirname(__DIR__) . '/models/PasswordReset.php';

use SmaMove\Config\Database;
use SmaMove\Services\AuthService;
use SmaMove\Utils\TokenManager;
use SmaMove\Utils\Validation;
use SmaMove\Models\User;
use SmaMove\Models\AuthToken;
use SmaMove\Models\PasswordReset;
use PDO;
use Exception;

class AuthTestSuite
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(): void
    {
        echo "\n====================================================================\n";
        echo " SMA-MOVE — PHASE 3 AUTHENTICATION & SECURITY TEST SUITE\n";
        echo "====================================================================\n\n";

        // Clean previous synthetic test data
        $this->cleanupSyntheticData();

        $this->testValidationRules();
        $this->testRegistrationAllRoles();
        $this->testDuplicateRegistration();
        $this->testLoginScenarios();
        $this->testAccountStatuses();
        $this->testEmailVerification();
        $this->testTokenLifecycleAndRevocation();
        $this->testPasswordRecoveryWorkflow();

        // Clean up after tests
        $this->cleanupSyntheticData();

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

    private function cleanupSyntheticData(): void
    {
        $pdo = Database::getConnection();
        // Remove synthetic accounts used by this test suite
        $pdo->exec("DELETE FROM users WHERE email LIKE '%@synthetic-smamove-test.com'");
    }

    private function testValidationRules(): void
    {
        echo "--- 1. Testing Input Validation Rules ---\n";

        $rules = [
            'email' => ['required', 'email'],
            'password' => ['required', 'password_strength'],
            'role' => ['required', 'enum:patient,caregiver,professional']
        ];

        // Invalid email
        $resEmail = Validation::validate([
            'email' => 'bad-email-format',
            'password' => 'ValidPass123',
            'role' => 'patient'
        ], $rules);
        $this->assert("Invalid email rejected by validator", !$resEmail['isValid'] && isset($resEmail['errors']['email']));

        // Weak passwords
        $resShort = Validation::validate([
            'email' => 'user@synthetic-smamove-test.com',
            'password' => 'Short1',
            'role' => 'patient'
        ], $rules);
        $this->assert("Short password (<8 chars) rejected", !$resShort['isValid'] && isset($resShort['errors']['password']));

        $resNoUpper = Validation::validate([
            'email' => 'user@synthetic-smamove-test.com',
            'password' => 'nouppercase123',
            'role' => 'patient'
        ], $rules);
        $this->assert("Password without uppercase rejected", !$resNoUpper['isValid']);

        $resNoNumber = Validation::validate([
            'email' => 'user@synthetic-smamove-test.com',
            'password' => 'NoNumberInPassword',
            'role' => 'patient'
        ], $rules);
        $this->assert("Password without number rejected", !$resNoNumber['isValid']);

        // Invalid role
        $resRole = Validation::validate([
            'email' => 'user@synthetic-smamove-test.com',
            'password' => 'ValidPass123',
            'role' => 'administrator'
        ], $rules);
        $this->assert("Arbitrary unauthorized role rejected", !$resRole['isValid'] && isset($resRole['errors']['role']));
    }

    private function testRegistrationAllRoles(): void
    {
        echo "\n--- 2. Testing Registration for All Three Roles ---\n";

        // Patient registration
        $patientEmail = 'patient.test@synthetic-smamove-test.com';
        $patientData = [
            'full_name' => 'Alice Patient',
            'email' => $patientEmail,
            'password' => 'PatientSecure123',
            'role' => 'patient',
            'preferred_language' => 'English',
            'emergency_contact_name' => 'Bob Guardian',
            'emergency_contact_phone' => '+91 9876543210'
        ];
        $resPatient = AuthService::signup($patientData);
        $this->assert("Patient registration succeeded", isset($resPatient['id']) && $resPatient['role'] === 'patient');

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM patient_profiles WHERE user_id = :uid");
        $stmt->execute([':uid' => $resPatient['id']]);
        $patientProfile = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assert("Linked patient_profile record created in transaction", !empty($patientProfile));
        $this->assert("Patient profile preserves emergency contact", $patientProfile['emergency_contact_name'] === 'Bob Guardian');

        // Caregiver registration
        $caregiverEmail = 'caregiver.test@synthetic-smamove-test.com';
        $caregiverData = [
            'full_name' => 'Bob Guardian',
            'email' => $caregiverEmail,
            'password' => 'CaregiverSecure123',
            'role' => 'caregiver',
            'contact_number' => '+91 9876543210',
            'relationship_description' => 'Father / Primary Caregiver'
        ];
        $resCaregiver = AuthService::signup($caregiverData);
        $this->assert("Caregiver registration succeeded", isset($resCaregiver['id']) && $resCaregiver['role'] === 'caregiver');

        $stmt = $pdo->prepare("SELECT * FROM caregiver_profiles WHERE user_id = :uid");
        $stmt->execute([':uid' => $resCaregiver['id']]);
        $caregiverProfile = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assert("Linked caregiver_profile record created in transaction", !empty($caregiverProfile));

        // Professional registration
        $profEmail = 'therapist.test@synthetic-smamove-test.com';
        $profData = [
            'full_name' => 'Dr. Clara Therapist',
            'email' => $profEmail,
            'password' => 'PhysioSecure123',
            'role' => 'professional',
            'professional_title' => 'Senior Neuro-Physiotherapist',
            'qualification' => 'MPT (Neurology), IAP',
            'registration_number' => 'DMC-PT-2026-9988',
            'institution_name' => 'AIIPMR Regional Center'
        ];
        $resProf = AuthService::signup($profData);
        $this->assert("Professional registration succeeded", isset($resProf['id']) && $resProf['role'] === 'professional');

        $stmt = $pdo->prepare("SELECT * FROM professional_profiles WHERE user_id = :uid");
        $stmt->execute([':uid' => $resProf['id']]);
        $profProfile = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assert("Linked professional_profile record created with status not_submitted", $profProfile && $profProfile['verification_status'] === 'not_submitted');
    }

    private function testDuplicateRegistration(): void
    {
        echo "\n--- 3. Testing Duplicate Registration Prevention ---\n";

        $duplicateData = [
            'full_name' => 'Alice Clone',
            'email' => 'PATIENT.TEST@SYNTHETIC-SMAMOVE-TEST.COM', // Uppercase variation
            'password' => 'PatientSecure123',
            'role' => 'patient'
        ];

        $caught = false;
        try {
            AuthService::signup($duplicateData);
        } catch (Exception $e) {
            $caught = true;
            $this->assert("Duplicate registration rejected with 409 Conflict", $e->getCode() === 409);
        }
        $this->assert("Duplicate email correctly blocked by service", $caught);
    }

    private function testLoginScenarios(): void
    {
        echo "\n--- 4. Testing Login Scenarios ---\n";

        $email = 'patient.test@synthetic-smamove-test.com';

        // Successful login
        $loginRes = AuthService::login($email, 'PatientSecure123');
        $this->assert("Login succeeds with valid credentials", isset($loginRes['access_token']));
        $this->assert("Access token is 64 hex characters (256-bit)", strlen($loginRes['access_token']) === 64);
        $this->assert("Refresh token is 64 hex characters", strlen($loginRes['refresh_token']) === 64);
        $this->assert("Response does not contain password_hash", !isset($loginRes['user']['password_hash']));
        $this->assert("Response includes linked patient profile", isset($loginRes['user']['profile']));

        // Incorrect password
        $failedPass = false;
        try {
            AuthService::login($email, 'WrongPassword123');
        } catch (Exception $e) {
            $failedPass = true;
            $this->assert("Incorrect password returns generic 401 error", $e->getCode() === 401 && $e->getMessage() === 'Invalid email or password.');
        }
        $this->assert("Login fails on incorrect password", $failedPass);

        // Non-existent email
        $failedEmail = false;
        try {
            AuthService::login('nonexistent.user@synthetic-smamove-test.com', 'AnyPassword123');
        } catch (Exception $e) {
            $failedEmail = true;
            $this->assert("Non-existent email returns generic 401 (preventing user enumeration)", $e->getCode() === 401 && $e->getMessage() === 'Invalid email or password.');
        }
        $this->assert("Login fails on non-existent email", $failedEmail);
    }

    private function testAccountStatuses(): void
    {
        echo "\n--- 5. Testing Account Status Constraints ---\n";

        $pdo = Database::getConnection();

        // 1. Suspended account
        $user = User::findByEmail('patient.test@synthetic-smamove-test.com');
        $pdo->prepare("UPDATE users SET account_status = 'suspended' WHERE id = :id")->execute([':id' => $user['id']]);

        $suspendedBlocked = false;
        try {
            AuthService::login('patient.test@synthetic-smamove-test.com', 'PatientSecure123');
        } catch (Exception $e) {
            $suspendedBlocked = true;
            $this->assert("Suspended account login blocked with 403 Forbidden", $e->getCode() === 403);
        }
        $this->assert("Suspended account cannot log in", $suspendedBlocked);

        // 2. Pending account
        $pdo->prepare("UPDATE users SET account_status = 'pending' WHERE id = :id")->execute([':id' => $user['id']]);

        $pendingBlocked = false;
        try {
            AuthService::login('patient.test@synthetic-smamove-test.com', 'PatientSecure123');
        } catch (Exception $e) {
            $pendingBlocked = true;
            $this->assert("Pending account login blocked with 403 Forbidden", $e->getCode() === 403);
        }
        $this->assert("Pending account cannot log in", $pendingBlocked);

        // Restore active status
        $pdo->prepare("UPDATE users SET account_status = 'active' WHERE id = :id")->execute([':id' => $user['id']]);
    }

    private function testEmailVerification(): void
    {
        echo "\n--- 6. Testing Email Verification & Activation Workflow ---\n";

        $pdo = Database::getConnection();
        $email = 'pending.test@synthetic-smamove-test.com';

        // Register new user explicitly in pending status
        $pdo->beginTransaction();
        $uid = User::create([
            'full_name' => 'Pending User',
            'email' => $email,
            'password_hash' => password_hash('PendingPass123', PASSWORD_DEFAULT),
            'role' => 'patient',
            'account_status' => 'pending',
            'email_verified_at' => null
        ]);
        $code = '123456';
        $pdo->prepare("INSERT INTO email_verifications (user_id, verification_code, expires_at) VALUES (:uid, :code, DATE_ADD(NOW(), INTERVAL 1 DAY))")
            ->execute([':uid' => $uid, ':code' => $code]);
        $pdo->commit();

        // Verification fails with wrong code
        $wrongCodeFailed = false;
        try {
            AuthService::verifyEmail($email, '999999');
        } catch (Exception $e) {
            $wrongCodeFailed = true;
        }
        $this->assert("Invalid verification code rejected", $wrongCodeFailed);

        // Verification succeeds with correct code
        $verified = AuthService::verifyEmail($email, '123456');
        $this->assert("Account verification succeeded with correct code", $verified === true);

        // Confirm status changed to active in database
        $userUpdated = User::findById($uid);
        $this->assert("Account status transitioned from pending to active", $userUpdated['account_status'] === 'active');
        $this->assert("email_verified_at timestamp populated", !empty($userUpdated['email_verified_at']));

        // Now login succeeds
        $loginRes = AuthService::login($email, 'PendingPass123');
        $this->assert("User can log in after email verification", !empty($loginRes['access_token']));
    }

    private function testTokenLifecycleAndRevocation(): void
    {
        echo "\n--- 7. Testing Token Lifecycle, Expiration & Revocation ---\n";

        $email = 'patient.test@synthetic-smamove-test.com';
        $loginRes = AuthService::login($email, 'PatientSecure123');
        $plainToken = $loginRes['access_token'];
        $tokenHash = TokenManager::hashToken($plainToken);

        // Verify token exists and is valid
        $tokenRecord = AuthToken::findValidToken($tokenHash, 'access');
        $this->assert("Valid token found in database", $tokenRecord !== null);

        // Test authenticated /me
        $meData = AuthService::me((int)$tokenRecord['user_id']);
        $this->assert("/me endpoint returns user identity", $meData !== null && $meData['email'] === $email);
        $this->assert("/me endpoint never exposes password_hash", !isset($meData['password_hash']));

        // Test Logout (Token Revocation)
        $loggedOut = AuthService::logout($plainToken);
        $this->assert("Logout marks token as revoked", $loggedOut === true);

        // Token should now be invalid
        $revokedLookup = AuthToken::findValidToken($tokenHash, 'access');
        $this->assert("Revoked token is rejected on lookup", $revokedLookup === null);

        // Test Expired Token
        $loginRes2 = AuthService::login($email, 'PatientSecure123');
        $token2 = $loginRes2['access_token'];
        $hash2 = TokenManager::hashToken($token2);

        $pdo = Database::getConnection();
        // Artificially expire token
        $pdo->prepare("UPDATE auth_tokens SET expires_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE token_hash = :h")
            ->execute([':h' => $hash2]);

        $expiredLookup = AuthToken::findValidToken($hash2, 'access');
        $this->assert("Expired token is rejected on lookup", $expiredLookup === null);
    }

    private function testPasswordRecoveryWorkflow(): void
    {
        echo "\n--- 8. Testing Password Recovery & Session Invalidation ---\n";

        $email = 'patient.test@synthetic-smamove-test.com';

        // 1. Establish an active session prior to password reset
        $initialSession = AuthService::login($email, 'PatientSecure123');
        $preResetToken = $initialSession['access_token'];
        $preResetHash = TokenManager::hashToken($preResetToken);

        $this->assert("Initial active session established", AuthToken::findValidToken($preResetHash, 'access') !== null);

        // 2. Request password reset
        $resetReq = AuthService::requestPasswordReset($email);
        $this->assert("Forgot password returns generic non-enumerating message", str_contains($resetReq['message'], 'password reset instructions'));
        $this->assert("Dev reset token generated for testing", !empty($resetReq['dev_reset_token']));

        $resetToken = $resetReq['dev_reset_token'];

        // 3. Complete password reset
        $newPassword = 'BrandNewPassword999';
        $resetSuccess = AuthService::resetPassword($resetToken, $newPassword);
        $this->assert("Password reset completed successfully", $resetSuccess === true);

        // 4. Verify that the previous session token was invalidated
        $preResetAfter = AuthToken::findValidToken($preResetHash, 'access');
        $this->assert("Active session tokens invalidated immediately upon password reset", $preResetAfter === null);

        // 5. Verify single-use reset token cannot be reused
        $reuseBlocked = false;
        try {
            AuthService::resetPassword($resetToken, 'AnotherPassword123');
        } catch (Exception $e) {
            $reuseBlocked = true;
            $this->assert("Reset token reuse blocked with 400 error", $e->getCode() === 400);
        }
        $this->assert("Single-use reset token cannot be reused", $reuseBlocked);

        // 6. Old password fails to log in
        $oldPassBlocked = false;
        try {
            AuthService::login($email, 'PatientSecure123');
        } catch (Exception $e) {
            $oldPassBlocked = true;
        }
        $this->assert("Old password cannot log in after reset", $oldPassBlocked);

        // 7. New password logs in successfully
        $newLogin = AuthService::login($email, 'BrandNewPassword999');
        $this->assert("New password logs in successfully and issues new tokens", !empty($newLogin['access_token']));
    }
}

$suite = new AuthTestSuite();
$suite->run();
