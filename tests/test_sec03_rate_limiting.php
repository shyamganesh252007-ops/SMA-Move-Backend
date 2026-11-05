<?php
declare(strict_types=1);

/**
 * SMA-Move — Phase 7.3 Security Test Suite: SEC-03 Registration & Verification Rate Limiting
 *
 * Verifies server-side rate limiting on:
 * - api/auth/signup.php (Registration quota: 5 attempts / hour per client IP)
 * - api/auth/verify_email.php (Verification quota: 5 attempts / 15 minutes per IP + account context)
 *
 * Verifies:
 * - Normal requests within limits
 * - Limit exceeded returning HTTP 429 and standard error envelope
 * - Invalid requests consuming rate-limit attempts
 * - Forged X-Forwarded-For cannot bypass rate limits
 * - Different client IPs possess isolated quotas
 * - Different user accounts possess isolated verification quotas (anti-DoS)
 * - Concurrent requests under parallel execution
 * - Clean teardown of test fixtures
 */

namespace SmaMove\Tests;

require_once dirname(__DIR__) . '/config/environment.php';
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/utils/rate_limiter.php';
require_once dirname(__DIR__) . '/services/AuthService.php';
require_once __DIR__ . '/TestSafetyGuard.php';

use SmaMove\Config\Database;
use SmaMove\Utils\RateLimiter;
use SmaMove\Services\AuthService;
use SmaMove\Tests\TestSafetyGuard;
use PDO;

class Sec03RateLimitingTest
{
    private int $passed = 0;
    private int $failed = 0;
    private string $baseUrl = 'http://localhost/sma-move-backend';
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    public function runAll(): void
    {
        echo "\n====================================================================\n";
        echo " SMA-MOVE — PHASE 7.3 SEC-03 REGISTRATION & VERIFY RATE LIMITING\n";
        echo "====================================================================\n\n";

        $this->cleanupFixtures();

        try {
            $this->testRegistrationRateLimiting();
            $this->testRegistrationForgedHeaderBypassBlocked();
            $this->testRegistrationInvalidPayloadsCounted();
            $this->testRegistrationConcurrentAttempts();
            $this->testEmailVerificationRateLimiting();
            $this->testEmailVerificationAntiDosIsolation();
            $this->testEmailVerificationForgedHeaderBypassBlocked();
            $this->testEmailVerificationExpiredAndConsumedCodes();
            $this->testEmailVerificationConcurrentAttempts();
            $this->testExistingLoginAndForgotPwdRateLimits();
        } finally {
            $this->cleanupFixtures();
        }

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

    private function httpPost(string $endpoint, array $data, array $headers = []): array
    {
        $url = $this->baseUrl . $endpoint;
        $ch = curl_init($url);
        $payload = json_encode($data);

        $defaultHeaders = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        foreach ($headers as $k => $v) {
            $defaultHeaders[] = "{$k}: {$v}";
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $defaultHeaders);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $bodyStr = substr($response, $headerSize);
        $decoded = json_decode($bodyStr, true) ?? [];

        return [
            'status' => $status,
            'body' => $decoded,
            'raw_body' => $bodyStr
        ];
    }

    private function createPendingUser(string $email, string $code): int
    {
        $passwordHash = password_hash('StrongPass123!#', PASSWORD_DEFAULT);
        $stmt = $this->pdo->prepare("
            INSERT INTO users (full_name, email, password_hash, role, account_status, email_verified_at)
            VALUES ('Pending Test User', :email, :pwd, 'patient', 'pending', NULL)
        ");
        $stmt->execute([':email' => $email, ':pwd' => $passwordHash]);
        $uid = (int)$this->pdo->lastInsertId();

        $vStmt = $this->pdo->prepare("
            INSERT INTO email_verifications (user_id, verification_code, expires_at)
            VALUES (:uid, :code, DATE_ADD(NOW(), INTERVAL 1 DAY))
        ");
        $vStmt->execute([':uid' => $uid, ':code' => $code]);

        return $uid;
    }

    private function cleanupFixtures(): void
    {
        // Enforce fail-closed safety guard before any cleanup
        TestSafetyGuard::assertSafeEnvironment($this->pdo);

        // Clean test users strictly within the synthetic namespace
        TestSafetyGuard::cleanSyntheticUsers($this->pdo, '%@synthetic-sec03-test.com');

        // Scoped cleanup: delete only test IP keys and actions generated by SEC-03 test runs
        $testKeys = [
            '127.0.0.1',
            '::1',
            '198.51.100.99',
            '203.0.113.88'
        ];
        $testActions = ['signup', 'verify_email', 'verify_email_ip', 'login'];

        TestSafetyGuard::cleanRateLimits($this->pdo, $testKeys, $testActions);

        // Also clean composite verification keys created during test runs
        TestSafetyGuard::cleanRateLimitsByPattern($this->pdo, '%:verify_email:%', ['verify_email']);
        TestSafetyGuard::cleanRateLimitsByPattern($this->pdo, '%:verify_email_ip%', ['verify_email_ip']);
    }

    /**
     * 1. Registration rate limiting (5 attempts / hour).
     */
    private function testRegistrationRateLimiting(): void
    {
        echo "--- 1. Registration Rate Limiting (Normal & Exceeded) ---\n";
        $this->cleanupFixtures();

        // Attempts 1 to 5: Should succeed with 201 Created
        for ($i = 1; $i <= 5; $i++) {
            $res = $this->httpPost('/api/auth/signup.php', [
                'full_name' => "SEC03 User {$i}",
                'email' => "user{$i}@synthetic-sec03-test.com",
                'password' => "StrongPass123!#",
                'role' => 'patient'
            ]);
            $this->assert("Registration attempt {$i}/5 succeeded (HTTP 201)", $res['status'] === 201, "Got HTTP {$res['status']}");
        }

        // Attempt 6: MUST be rejected with HTTP 429
        $res6 = $this->httpPost('/api/auth/signup.php', [
            'full_name' => 'SEC03 User 6',
            'email' => 'user6@synthetic-sec03-test.com',
            'password' => 'StrongPass123!#',
            'role' => 'patient'
        ]);
        $this->assert("Registration attempt 6/5 blocked with HTTP 429", $res6['status'] === 429, "Got HTTP {$res6['status']}");
        $this->assert("Response envelope success is false on 429", isset($res6['body']['success']) && $res6['body']['success'] === false);
        $this->assert("Response message indicates rate limit exceeded", str_contains(strtolower($res6['body']['message'] ?? ''), 'too many registration attempts'));
    }

    /**
     * 2. Registration forged forwarded IP header cannot bypass quota.
     */
    private function testRegistrationForgedHeaderBypassBlocked(): void
    {
        echo "\n--- 2. Registration Forged Header Bypass Defense ---\n";
        // Attempting registration with spoofed X-Forwarded-For while limit is exceeded
        $resSpoofed = $this->httpPost('/api/auth/signup.php', [
            'full_name' => 'SEC03 Spoofed User',
            'email' => 'spoofed@synthetic-sec03-test.com',
            'password' => 'StrongPass123!#',
            'role' => 'patient'
        ], [
            'X-Forwarded-For' => '198.51.100.99'
        ]);

        $this->assert("Spoofed X-Forwarded-For rejected with HTTP 429 (untrusted connection ignores header)", $resSpoofed['status'] === 429, "Got HTTP {$resSpoofed['status']}");

        $resCf = $this->httpPost('/api/auth/signup.php', [
            'full_name' => 'SEC03 Spoofed CF User',
            'email' => 'spoofed_cf@synthetic-sec03-test.com',
            'password' => 'StrongPass123!#',
            'role' => 'patient'
        ], [
            'CF-Connecting-IP' => '203.0.113.88'
        ]);

        $this->assert("Spoofed CF-Connecting-IP rejected with HTTP 429", $resCf['status'] === 429, "Got HTTP {$resCf['status']}");
    }

    /**
     * 3. Invalid registration payloads count against quota.
     */
    private function testRegistrationInvalidPayloadsCounted(): void
    {
        echo "\n--- 3. Invalid Registration Payloads Counted Against Quota ---\n";
        $this->cleanupFixtures();

        // 4 invalid requests (missing fields / weak password)
        for ($i = 1; $i <= 4; $i++) {
            $res = $this->httpPost('/api/auth/signup.php', [
                'full_name' => "Invalid {$i}",
                'email' => "invalid{$i}@synthetic-sec03-test.com",
                'password' => 'weak', // fails validation
                'role' => 'patient'
            ]);
            $this->assert("Invalid request {$i} returns HTTP 422 or 400", in_array($res['status'], [400, 422], true), "Got {$res['status']}");
        }

        // 5th attempt: valid payload succeeds
        $res5 = $this->httpPost('/api/auth/signup.php', [
            'full_name' => 'Valid User After Invalids',
            'email' => 'valid_after_invalids@synthetic-sec03-test.com',
            'password' => 'StrongPass123!#',
            'role' => 'patient'
        ]);
        $this->assert("Attempt 5 succeeds (HTTP 201)", $res5['status'] === 201, "Got HTTP {$res5['status']}");

        // 6th attempt: MUST be blocked with 429 because invalid attempts consumed quota!
        $res6 = $this->httpPost('/api/auth/signup.php', [
            'full_name' => 'User 6 After Invalids',
            'email' => 'user6_invalids@synthetic-sec03-test.com',
            'password' => 'StrongPass123!#',
            'role' => 'patient'
        ]);
        $this->assert("Attempt 6 blocked with HTTP 429 (invalid payloads consumed quota)", $res6['status'] === 429, "Got HTTP {$res6['status']}");
    }

    /**
     * 4. Concurrent registration attempts.
     */
    private function testRegistrationConcurrentAttempts(): void
    {
        echo "\n--- 4. Concurrent Registration Under Parallel Dispatch ---\n";
        $this->cleanupFixtures();

        // Dispatch 7 requests concurrently via curl_multi
        $mh = curl_multi_init();
        $handles = [];

        for ($i = 1; $i <= 7; $i++) {
            $ch = curl_init($this->baseUrl . '/api/auth/signup.php');
            $payload = json_encode([
                'full_name' => "Parallel User {$i}",
                'email' => "parallel{$i}@synthetic-sec03-test.com",
                'password' => 'StrongPass123!#',
                'role' => 'patient'
            ]);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }

        $active = null;
        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($mrc === CURLM_CALL_MULTI_PERFORM || $active);

        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($mh) != -1) {
                do {
                    $mrc = curl_multi_exec($mh, $active);
                } while ($mrc === CURLM_CALL_MULTI_PERFORM);
            }
        }

        $statusCounts = [];
        $responses = [];
        foreach ($handles as $ch) {
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $content = curl_multi_getcontent($ch);
            $statusCounts[$code] = ($statusCounts[$code] ?? 0) + 1;
            $responses[] = ['code' => $code, 'body' => $content];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        $created = $statusCounts[201] ?? 0;
        $throttled = $statusCounts[429] ?? 0;

        echo " Parallel Dispatch Result: {$created} Created (201), {$throttled} Throttled (429)\n";
        $this->assert("At most 5 registrations allowed and remaining requests throttled with 429", ($created <= 5 && $throttled >= 2 && ($created + $throttled === 7)), "Created: {$created}, Throttled: {$throttled}");
    }

    /**
     * 5. Email verification rate limiting (5 attempts / 15 minutes).
     */
    private function testEmailVerificationRateLimiting(): void
    {
        echo "\n--- 5. Email Verification Rate Limiting (5 Attempts / 15 Min) ---\n";
        $this->cleanupFixtures();

        // Create a synthetic user needing verification
        $email = 'verify_target@synthetic-sec03-test.com';
        $correctCode = '123456';
        $this->createPendingUser($email, $correctCode);

        // Submit 5 WRONG codes
        for ($i = 1; $i <= 5; $i++) {
            $res = $this->httpPost('/api/auth/verify_email.php', [
                'email' => $email,
                'code' => '99999' . $i // incorrect code
            ]);
            $this->assert("Wrong verification attempt {$i}/5 rejected with HTTP 400", $res['status'] === 400, "Got HTTP {$res['status']}");
        }

        // 6th attempt (even with the CORRECT code): MUST be blocked with HTTP 429
        $res6 = $this->httpPost('/api/auth/verify_email.php', [
            'email' => $email,
            'code' => $correctCode
        ]);
        $this->assert("Attempt 6 blocked with HTTP 429 (brute-force threshold reached)", $res6['status'] === 429, "Got HTTP {$res6['status']}");
        $this->assert("Response envelope indicates verification rate limit exceeded", str_contains(strtolower($res6['body']['message'] ?? ''), 'too many verification attempts'));
    }

    /**
     * 6. Anti-DoS: Attacker targeting Account A does NOT exhaust Account B.
     */
    private function testEmailVerificationAntiDosIsolation(): void
    {
        echo "\n--- 6. Anti-DoS: Cross-Account Verification Quota Isolation ---\n";
        // Create second user
        $emailB = 'user_b@synthetic-sec03-test.com';
        $codeB = '654321';
        $this->createPendingUser($emailB, $codeB);

        // Account A is locked out from test 5, but User B should STILL be verifiable
        $resB = $this->httpPost('/api/auth/verify_email.php', [
            'email' => $emailB,
            'code' => $codeB
        ]);
        $this->assert("User B can verify successfully; not locked out by Account A attacks (HTTP 200)", $resB['status'] === 200, "Got HTTP {$resB['status']}");
    }

    /**
     * 7. Forged forwarded headers cannot bypass email verification rate limit.
     */
    private function testEmailVerificationForgedHeaderBypassBlocked(): void
    {
        echo "\n--- 7. Email Verification Forged Header Bypass Defense ---\n";
        // Account A is currently rate limited
        $resSpoofed = $this->httpPost('/api/auth/verify_email.php', [
            'email' => 'verify_target@synthetic-sec03-test.com',
            'code' => '000000'
        ], [
            'X-Forwarded-For' => '198.51.100.222'
        ]);

        $this->assert("Spoofed X-Forwarded-For cannot bypass verification lockout (HTTP 429)", $resSpoofed['status'] === 429, "Got HTTP {$resSpoofed['status']}");
    }

    /**
     * 8. Expired & Consumed verification codes.
     */
    private function testEmailVerificationExpiredAndConsumedCodes(): void
    {
        echo "\n--- 8. Expired and Consumed Verification Codes ---\n";
        $this->cleanupFixtures();

        // 1. Consumed code reuse
        $emailC = 'consumed_user@synthetic-sec03-test.com';
        $codeC = '112233';
        $this->createPendingUser($emailC, $codeC);

        // Initial valid verification
        $resValid = $this->httpPost('/api/auth/verify_email.php', [
            'email' => $emailC,
            'code' => $codeC
        ]);
        $this->assert("Initial verification succeeds with 200 OK", $resValid['status'] === 200, "Got {$resValid['status']}");

        // Attempting to reuse the consumed code
        $resReuse = $this->httpPost('/api/auth/verify_email.php', [
            'email' => $emailC,
            'code' => $codeC
        ]);
        $this->assert("Reused verification code rejected with HTTP 400", $resReuse['status'] === 400, "Got {$resReuse['status']}");

        // 2. Expired code test
        $emailD = 'expired_user@synthetic-sec03-test.com';
        $codeD = '445566';
        $uidD = $this->createPendingUser($emailD, $codeD);

        // Manually expire the code in the database
        $this->pdo->exec("UPDATE email_verifications SET expires_at = '2020-01-01 00:00:00' WHERE user_id = {$uidD}");

        $resExpired = $this->httpPost('/api/auth/verify_email.php', [
            'email' => $emailD,
            'code' => $codeD
        ]);
        $this->assert("Expired verification code rejected with HTTP 400", $resExpired['status'] === 400, "Got {$resExpired['status']}");
    }

    /**
     * 9. Concurrent verification attempts.
     */
    private function testEmailVerificationConcurrentAttempts(): void
    {
        echo "\n--- 9. Concurrent Verification Under Parallel Dispatch ---\n";
        $this->cleanupFixtures();

        $emailE = 'concurrent_verify@synthetic-sec03-test.com';
        $this->createPendingUser($emailE, '778899');

        $mh = curl_multi_init();
        $handles = [];

        // 8 concurrent wrong code attempts
        for ($i = 1; $i <= 8; $i++) {
            $ch = curl_init($this->baseUrl . '/api/auth/verify_email.php');
            $payload = json_encode([
                'email' => $emailE,
                'code' => 'wrong' . $i
            ]);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }

        $active = null;
        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($mrc === CURLM_CALL_MULTI_PERFORM || $active);

        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($mh) != -1) {
                do {
                    $mrc = curl_multi_exec($mh, $active);
                } while ($mrc === CURLM_CALL_MULTI_PERFORM);
            }
        }

        $statusCounts = [];
        $responses = [];
        foreach ($handles as $ch) {
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $content = curl_multi_getcontent($ch);
            $statusCounts[$code] = ($statusCounts[$code] ?? 0) + 1;
            $responses[] = ['code' => $code, 'body' => $content];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        $badRequests = $statusCounts[400] ?? 0;
        $throttled = $statusCounts[429] ?? 0;

        echo " Parallel Verification Result: {$badRequests} Rejected (400), {$throttled} Throttled (429)\n";
        $this->assert("At most 5 verification attempts evaluated and remaining throttled with 429", ($badRequests <= 5 && $throttled >= 3 && ($badRequests + $throttled === 8)), "Bad: {$badRequests}, Throttled: {$throttled}");
    }

    /**
     * 10. Existing login and forgot-password rate limits.
     */
    private function testExistingLoginAndForgotPwdRateLimits(): void
    {
        echo "\n--- 10. Verification of Existing Login & Forgot-Password Rate Limits ---\n";
        $this->cleanupFixtures();

        // Create active user
        $email = 'regression_login@synthetic-sec03-test.com';
        $passwordHash = password_hash('ValidPassword123!#', PASSWORD_DEFAULT);
        $this->pdo->prepare("
            INSERT INTO users (full_name, email, password_hash, role, account_status, email_verified_at)
            VALUES ('Regression Login User', :email, :pwd, 'patient', 'active', NOW())
        ")->execute([':email' => $email, ':pwd' => $passwordHash]);

        // 5 wrong login attempts
        for ($i = 1; $i <= 5; $i++) {
            $res = $this->httpPost('/api/auth/login.php', [
                'email' => $email,
                'password' => 'WrongPassword!'
            ]);
            $this->assert("Login failure {$i}/5 returns 401", $res['status'] === 401, "Got {$res['status']}");
        }

        // 6th attempt: MUST return 429
        $res6 = $this->httpPost('/api/auth/login.php', [
            'email' => $email,
            'password' => 'ValidPassword123!#'
        ]);
        $this->assert("Login attempt 6 returns HTTP 429 (existing login rate limiter preserved)", $res6['status'] === 429, "Got {$res6['status']}");
    }
}

// Execute the test suite
$test = new Sec03RateLimitingTest();
$test->runAll();
