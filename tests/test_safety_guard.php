<?php
declare(strict_types=1);

/**
 * SMA-Move — Test Safety Guard Verification Test Suite
 *
 * Verifies that TestSafetyGuard prevents destructive actions in production environments,
 * enforces strict synthetic scoping for user cleanup, and preserves unrelated rate-limit rows.
 */

namespace SmaMove\Tests;

require_once dirname(__DIR__) . '/config/environment.php';
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/TestSafetyGuard.php';

use SmaMove\Config\Database;
use SmaMove\Tests\TestSafetyGuard;
use PDO;
use RuntimeException;
use InvalidArgumentException;

class TestSafetyGuardTest
{
    private int $passed = 0;
    private int $failed = 0;
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
    }

    public function runAll(): void
    {
        echo "\n====================================================================\n";
        echo " SMA-MOVE — PHASE 7.5 TEST SAFETY GUARD & CLEANUP VERIFICATION\n";
        echo "====================================================================\n\n";

        $this->testProductionEnvironmentRejection();
        $this->testUnsafeUserPatternRejection();
        $this->testEmptyRateLimitParametersRejection();
        $this->testScopedRateLimitCleanupPreservesUnrelatedRecords();
        $this->testSyntheticUserCleanupIsolation();
        $this->testIdempotentTeardownNoAccumulation();

        echo "\n--------------------------------------------------------------------\n";
        echo " Test Summary: {$this->passed} Passed, {$this->failed} Failed\n";
        echo "--------------------------------------------------------------------\n\n";

        if ($this->failed > 0) {
            exit(1);
        }
    }

    private function assert(string $name, bool $condition, string $detail = ''): void
    {
        if ($condition) {
            $this->passed++;
            echo " [PASS] {$name}\n";
        } else {
            $this->failed++;
            echo " [FAIL] {$name}" . ($detail ? " — {$detail}" : "") . "\n";
        }
    }

    /**
     * 1. Simulated production environment refuses destructive cleanup.
     */
    private function testProductionEnvironmentRejection(): void
    {
        echo "--- 1. Simulated Production Environment Guard Rejection ---\n";

        // Production simulation
        $blockedProd = false;
        try {
            TestSafetyGuard::assertSafeEnvironment($this->pdo, 'production');
        } catch (RuntimeException $e) {
            $blockedProd = true;
            $this->assert("assertSafeEnvironment blocks 'production'", true);
        }
        if (!$blockedProd) {
            $this->assert("assertSafeEnvironment blocks 'production'", false, "Allowed production environment");
        }

        // Shorthand prod simulation
        $blockedProdShort = false;
        try {
            TestSafetyGuard::assertSafeEnvironment($this->pdo, 'prod');
        } catch (RuntimeException $e) {
            $blockedProdShort = true;
            $this->assert("assertSafeEnvironment blocks 'prod'", true);
        }
        if (!$blockedProdShort) {
            $this->assert("assertSafeEnvironment blocks 'prod'", false, "Allowed prod environment");
        }

        // Staging simulation
        $blockedStaging = false;
        try {
            TestSafetyGuard::assertSafeEnvironment($this->pdo, 'staging');
        } catch (RuntimeException $e) {
            $blockedStaging = true;
            $this->assert("assertSafeEnvironment blocks 'staging'", true);
        }
        if (!$blockedStaging) {
            $this->assert("assertSafeEnvironment blocks 'staging'", false, "Allowed staging environment");
        }

        // Cleanup method in simulated production
        $cleanBlocked = false;
        try {
            TestSafetyGuard::cleanSyntheticUsers($this->pdo, '%@synthetic-safety-test.com', 'production');
        } catch (RuntimeException $e) {
            $cleanBlocked = true;
            $this->assert("cleanSyntheticUsers strictly refuses execution in production", true);
        }
        if (!$cleanBlocked) {
            $this->assert("cleanSyntheticUsers strictly refuses execution in production", false);
        }

        $rateCleanBlocked = false;
        try {
            TestSafetyGuard::cleanRateLimits($this->pdo, ['127.0.0.1'], ['test_action'], 'production');
        } catch (RuntimeException $e) {
            $rateCleanBlocked = true;
            $this->assert("cleanRateLimits strictly refuses execution in production", true);
        }
        if (!$rateCleanBlocked) {
            $this->assert("cleanRateLimits strictly refuses execution in production", false);
        }
    }

    /**
     * 2. Rejection of broad/unsafe user patterns.
     */
    private function testUnsafeUserPatternRejection(): void
    {
        echo "\n--- 2. Unsafe User Deletion Pattern Rejection ---\n";

        $unsafePatterns = ['%', '', '@gmail.com', 'user@domain.org', 'test'];
        foreach ($unsafePatterns as $pat) {
            $rejected = false;
            try {
                TestSafetyGuard::cleanSyntheticUsers($this->pdo, $pat);
            } catch (InvalidArgumentException $e) {
                $rejected = true;
            }
            $this->assert("Unsafe pattern '{$pat}' rejected with InvalidArgumentException", $rejected);
        }
    }

    /**
     * 3. Rejection of empty rate limit parameters.
     */
    private function testEmptyRateLimitParametersRejection(): void
    {
        echo "\n--- 3. Empty Rate Limit Parameter Rejection ---\n";

        $emptyKeys = false;
        try {
            TestSafetyGuard::cleanRateLimits($this->pdo, [], ['signup']);
        } catch (InvalidArgumentException $e) {
            $emptyKeys = true;
        }
        $this->assert("Empty keys array rejected", $emptyKeys);

        $emptyActions = false;
        try {
            TestSafetyGuard::cleanRateLimits($this->pdo, ['127.0.0.1'], []);
        } catch (InvalidArgumentException $e) {
            $emptyActions = true;
        }
        $this->assert("Empty actions array rejected", $emptyActions);
    }

    /**
     * 4. Scoped rate limit cleanup removes test fixtures while preserving unrelated records.
     */
    private function testScopedRateLimitCleanupPreservesUnrelatedRecords(): void
    {
        echo "\n--- 4. Scoped Rate Limit Cleanup & Record Preservation ---\n";

        // Seed 1 unrelated operational fixture
        $unrelatedKey = 'unrelated_ip_' . bin2hex(random_bytes(4));
        $unrelatedAction = 'unrelated_prod_action';
        $now = time();

        $stmt = $this->pdo->prepare("
            INSERT INTO rate_limits (rate_key, action, attempts, window_start)
            VALUES (?, ?, 3, ?)
        ");
        $stmt->execute([$unrelatedKey, $unrelatedAction, $now]);

        // Seed 1 test fixture
        $testKey = 'sec03_test_ip_' . bin2hex(random_bytes(4));
        $testAction = 'signup';
        $stmt->execute([$testKey, $testAction, $now]);

        // Perform scoped cleanup targeting ONLY $testKey and $testAction
        $deleted = TestSafetyGuard::cleanRateLimits($this->pdo, [$testKey], [$testAction]);
        $this->assert("Scoped cleanup deleted exactly 1 test record", $deleted === 1, "Deleted: {$deleted}");

        // Verify test record is gone
        $checkTest = $this->pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE rate_key = ? AND action = ?");
        $checkTest->execute([$testKey, $testAction]);
        $this->assert("Test fixture record successfully removed", (int)$checkTest->fetchColumn() === 0);

        // Verify unrelated record is completely preserved
        $checkUnrelated = $this->pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE rate_key = ? AND action = ?");
        $checkUnrelated->execute([$unrelatedKey, $unrelatedAction]);
        $this->assert("Unrelated record was NOT deleted and remains intact", (int)$checkUnrelated->fetchColumn() === 1);

        // Clean up the temporary unrelated fixture cleanly
        $this->pdo->prepare("DELETE FROM rate_limits WHERE rate_key = ? AND action = ?")->execute([$unrelatedKey, $unrelatedAction]);
    }

    /**
     * 5. Synthetic user cleanup isolates test accounts.
     */
    private function testSyntheticUserCleanupIsolation(): void
    {
        echo "\n--- 5. Synthetic User Cleanup Isolation ---\n";

        $pwd = password_hash('Pass123!#', PASSWORD_DEFAULT);
        $syntheticEmail = 'user_' . bin2hex(random_bytes(3)) . '@synthetic-safety-test.com';

        $ins = $this->pdo->prepare("
            INSERT INTO users (full_name, email, password_hash, role, account_status)
            VALUES ('Safety Test User', :email, :pwd, 'patient', 'active')
        ");
        $ins->execute([':email' => $syntheticEmail, ':pwd' => $pwd]);

        // Clean using synthetic pattern
        $deleted = TestSafetyGuard::cleanSyntheticUsers($this->pdo, '%@synthetic-safety-test.com');
        $this->assert("cleanSyntheticUsers removed synthetic user account", $deleted >= 1);

        // Verify user is gone
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt->execute([$syntheticEmail]);
        $this->assert("Synthetic user record no longer exists", (int)$stmt->fetchColumn() === 0);
    }

    /**
     * 6. Repeated test runs do not leak or accumulate fixture records.
     */
    private function testIdempotentTeardownNoAccumulation(): void
    {
        echo "\n--- 6. Repeated Teardown Idempotency & Clean Baseline ---\n";

        // Double cleanup should return 0 deleted rows and not error
        $del1 = TestSafetyGuard::cleanSyntheticUsers($this->pdo, '%@synthetic-safety-test.com');
        $del2 = TestSafetyGuard::cleanSyntheticUsers($this->pdo, '%@synthetic-safety-test.com');
        $this->assert("Repeated synthetic user cleanup is idempotent (deleted 0 on second run)", $del2 === 0);

        // Double rate limit cleanup
        $delR1 = TestSafetyGuard::cleanRateLimits($this->pdo, ['test_nonexistent_key'], ['signup']);
        $this->assert("Scoped rate limit cleanup on absent key returns 0 without error", $delR1 === 0);
    }
}

// Execute the test suite
$test = new TestSafetyGuardTest();
$test->runAll();
