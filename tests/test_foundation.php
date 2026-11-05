<?php
declare(strict_types=1);

/**
 * SMA-Move — Phase 2 Foundation Test Suite
 *
 * Validates environment loading, dedicated database connection,
 * response format, validation utilities, and CORS middleware.
 */

namespace SmaMove\Tests;

require_once dirname(__DIR__) . '/config/environment.php';
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/utils/response.php';
require_once dirname(__DIR__) . '/utils/validation.php';
require_once dirname(__DIR__) . '/middleware/cors.php';
require_once dirname(__DIR__) . '/middleware/request_validation.php';

use SmaMove\Config\Environment;
use SmaMove\Config\Database;
use SmaMove\Utils\Validation;
use SmaMove\Utils\Response;
use PDO;

class FoundationTest
{
    private int $passed = 0;
    private int $failed = 0;

    public function runAll(): void
    {
        echo "\n====================================================================\n";
        echo " SMA-MOVE — PHASE 2 FOUNDATION VERIFICATION TEST SUITE\n";
        echo "====================================================================\n\n";

        $this->testEnvironmentLoading();
        $this->testDatabaseConnection();
        $this->testValidationUtility();
        $this->testSanitizationUtility();
        $this->testMigrationIntegrity();

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

    private function testEnvironmentLoading(): void
    {
        echo "--- 1. Testing Environment Loading ---\n";
        $env = env('APP_ENV');
        $this->assert("Environment APP_ENV is loaded", $env === 'development', "Got: " . var_export($env, true));

        $dbUser = env('DB_USER');
        $this->assert("Dedicated DB_USER is configured", $dbUser === 'sma_app_user', "Got: " . var_export($dbUser, true));

        $fallback = env('NON_EXISTENT_KEY_123', 'default_value');
        $this->assert("Fallback default value functions properly", $fallback === 'default_value');

        $boolVal = env('APP_DEBUG');
        $this->assert("Boolean parsing functions properly", is_bool($boolVal) && $boolVal === true);
    }

    private function testDatabaseConnection(): void
    {
        echo "\n--- 2. Testing Dedicated Database Connection ---\n";
        $isAlive = Database::testConnection();
        $this->assert("Database ping succeeds via testConnection()", $isAlive === true);

        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->query("SELECT CURRENT_USER() AS cur_user, DATABASE() AS cur_db");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $this->assert("Connected as dedicated application user", str_starts_with($row['cur_user'], 'sma_app_user@'), "Connected as: " . $row['cur_user']);
            $this->assert("Connected to target database sma_move", $row['cur_db'] === 'sma_move', "Database: " . $row['cur_db']);

            // Verify emulated prepares are false
            $emulatePrepares = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
            $this->assert("Native prepared statements enforced (ATTR_EMULATE_PREPARES = false)", $emulatePrepares === false || $emulatePrepares === 0, "Got: " . var_export($emulatePrepares, true));

            // Verify error mode is exception
            $errorMode = $pdo->getAttribute(PDO::ATTR_ERRMODE);
            $this->assert("Error mode is ATTR_ERRMODE_EXCEPTION", $errorMode === PDO::ERRMODE_EXCEPTION);

        } catch (\Throwable $e) {
            $this->assert("Database queries execute without exception", false, $e->getMessage());
        }
    }

    private function testValidationUtility(): void
    {
        echo "\n--- 3. Testing Request Validation Utility ---\n";

        // Positive test
        $validData = [
            'email' => 'patient@smamove.com',
            'password' => 'SecurePass123',
            'role' => 'patient',
            'full_name' => 'John Doe'
        ];
        $rules = [
            'email' => ['required', 'email'],
            'password' => ['required', 'password_strength'],
            'role' => ['required', 'enum:patient,caregiver,professional'],
            'full_name' => ['required', 'min:2', 'max:150']
        ];
        $res = Validation::validate($validData, $rules);
        $this->assert("Validation succeeds for valid input", $res['isValid'] === true);

        // Negative test: invalid email & weak password
        $invalidData = [
            'email' => 'invalid-email-string',
            'password' => 'weak',
            'role' => 'unauthorized_role',
            'full_name' => 'A'
        ];
        $resInvalid = Validation::validate($invalidData, $rules);
        $this->assert("Validation correctly catches invalid email", isset($resInvalid['errors']['email']));
        $this->assert("Validation correctly catches weak password", isset($resInvalid['errors']['password']));
        $this->assert("Validation correctly catches invalid enum role", isset($resInvalid['errors']['role']));
        $this->assert("Validation correctly catches min length failure", isset($resInvalid['errors']['full_name']));
    }

    private function testSanitizationUtility(): void
    {
        echo "\n--- 4. Testing Sanitization Utility ---\n";

        $dirtyXss = "<script>alert('xss')</script>John";
        $clean = Validation::sanitizeString($dirtyXss);
        $this->assert("HTML/XSS characters sanitized", !str_contains($clean, '<script>') && str_contains($clean, '&lt;script&gt;'));

        $dirtyEmail = "  Test.User+Filter@SmaMove.com  ";
        $cleanEmail = Validation::sanitizeEmail($dirtyEmail);
        $this->assert("Email trimmed and lowercased", $cleanEmail === 'test.user+filter@smamove.com');
    }

    private function testMigrationIntegrity(): void
    {
        echo "\n--- 5. Testing Schema & Migration Integrity ---\n";

        $pdo = Database::getConnection();

        // Check the 3 original tables exist and have 0 records
        $originalTables = ['users', 'patient_profiles', 'professional_profiles'];
        foreach ($originalTables as $table) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM `{$table}`");
            $count = (int)$stmt->fetchColumn();
            $this->assert("Original table `{$table}` preserved with intact zero-record baseline", $count === 0);
        }

        // Check that new tables were added properly by migration system
        $newTables = [
            'schema_migrations',
            'auth_tokens',
            'password_resets',
            'caregiver_profiles',
            'caregiver_relationships',
            'care_tasks',
            'care_task_steps',
            'patient_task_completions',
            'appointments',
            'wellbeing_journal_entries',
            'consents',
            'movetrack_sessions'
        ];

        $stmt = $pdo->query("SHOW TABLES");
        $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($newTables as $tbl) {
            $this->assert("New table `{$tbl}` created via migrations", in_array($tbl, $existingTables, true));
        }
    }
}

$test = new FoundationTest();
$test->runAll();
