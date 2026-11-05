<?php
declare(strict_types=1);

/**
 * Phase 5.3 Verification Script: Appointment Meeting-Link Trust & Concurrency Audit
 */

require_once dirname(__DIR__) . '/config/environment.php';
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/models/Appointment.php';
require_once dirname(__DIR__) . '/services/AuthService.php';

use SmaMove\Config\Database;
use SmaMove\Models\Appointment;
use SmaMove\Services\AuthService;

echo "\n====================================================================\n";
echo " SMA-MOVE — PHASE 5.3 VERIFICATION AUDIT SUITE\n";
echo "====================================================================\n";

$pdo = Database::getConnection();

// Clean up any test users
$pdo->exec("DELETE FROM users WHERE email LIKE '%@synthetic-p53-test.com'");

// Create synthetic patient for testing
$patientRes = AuthService::signup([
    'email' => 'audit_patient@synthetic-p53-test.com',
    'password' => 'SecurePass#2026',
    'full_name' => 'Audit Subject',
    'role' => 'patient'
]);
$patientId = (int)$patientRes['id'];
$pdo->prepare("UPDATE users SET account_status = 'active' WHERE id = :id")->execute([':id' => $patientId]);

echo "\n--- 1. Testing Meeting-Link Trust & Domain Filtering ---\n";

// Case A: Default (no meeting_link provided)
$appAId = Appointment::create([
    'patient_user_id' => $patientId,
    'specialist_name' => 'Dr. Audit Default',
    'appointment_date' => date('Y-m-d', strtotime('+3 days')),
    'appointment_time' => '10:00 AM'
]);
$appA = Appointment::findById($appAId);
echo " [CHECK] Unconfigured meeting link defaults to: " . ($appA['meeting_link'] === null ? "NULL (PASS)" : $appA['meeting_link']) . "\n";

// Case B: Arbitrary untrusted domain supplied
$untrustedUrl = 'https://untrusted-phishing-domain.com/fake-meeting/12345';
$appBId = Appointment::create([
    'patient_user_id' => $patientId,
    'specialist_name' => 'Dr. Untrusted Test',
    'appointment_date' => date('Y-m-d', strtotime('+4 days')),
    'appointment_time' => '11:00 AM',
    'meeting_link' => $untrustedUrl
]);
$appB = Appointment::findById($appBId);
$persistedUntrusted = ($appB['meeting_link'] === $untrustedUrl);
echo " [FINDING] Arbitrary untrusted URL submitted: " . ($persistedUntrusted ? "ACCEPTED AND PERSISTED BY MODEL (VULNERABILITY CONFIRMED)" : "REJECTED") . "\n";

// Case C: Dangerous URL scheme (javascript:)
$xssScheme = 'javascript:alert(document.cookie)';
$appCId = Appointment::create([
    'patient_user_id' => $patientId,
    'specialist_name' => 'Dr. Scheme Test',
    'appointment_date' => date('Y-m-d', strtotime('+5 days')),
    'appointment_time' => '12:00 PM',
    'meeting_link' => $xssScheme
]);
$appC = Appointment::findById($appCId);
$persistedXss = ($appC['meeting_link'] === $xssScheme);
echo " [FINDING] Dangerous URL scheme (javascript:) submitted: " . ($persistedXss ? "ACCEPTED AND PERSISTED BY MODEL (VULNERABILITY CONFIRMED)" : "REJECTED") . "\n";

echo "\n--- 2. Testing Concurrency, Row Locking & Stale Updates ---\n";

// Create appointment in 'scheduled' state
$concurrencyAppId = Appointment::create([
    'patient_user_id' => $patientId,
    'specialist_name' => 'Dr. Concurrency Specialist',
    'appointment_date' => date('Y-m-d', strtotime('+2 days')),
    'appointment_time' => '02:00 PM'
]);

// Test 2.1: Independent PDO connection simulation
// Create second independent PDO connection to MariaDB
$dbHost = env('DB_HOST', '127.0.0.1');
$dbPort = env('DB_PORT', '3306');
$dbName = env('DB_NAME', 'sma_move');
$dbUser = env('DB_USER', 'sma_app_user');
$dbPass = env('DB_PASS', 'SmaMoveLocal#2026!AppSecure');

$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
$pdoConn2 = new PDO($dsn, $dbUser, $dbPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_TIMEOUT => 2 // 2 second timeout
]);

echo " [PASS] Established secondary independent PDO connection (Session B).\n";

// Session A locks the row FOR UPDATE
$pdo->beginTransaction();
$lockStmt = $pdo->prepare("SELECT id, status FROM appointments WHERE id = :id FOR UPDATE");
$lockStmt->execute([':id' => $concurrencyAppId]);
$lockedRow = $lockStmt->fetch();
echo " [PASS] Session A acquired exclusive row lock (FOR UPDATE) on Appointment #{$concurrencyAppId}.\n";

// Session B attempts to read/lock with low timeout or non-blocking check
$sessionBBlocked = false;
try {
    // Set lock wait timeout to 1 second on Session B
    $pdoConn2->exec("SET SESSION innodb_lock_wait_timeout = 1");
    $pdoConn2->beginTransaction();
    $stmtB = $pdoConn2->prepare("SELECT id, status FROM appointments WHERE id = :id FOR UPDATE");
    $stmtB->execute([':id' => $concurrencyAppId]);
    $pdoConn2->commit();
} catch (PDOException $e) {
    // Error 1205 is Lock wait timeout exceeded
    if (strpos($e->getMessage(), '1205') !== false || strpos($e->getMessage(), 'Lock wait timeout') !== false) {
        $sessionBBlocked = true;
    }
}
echo " [CHECK] Session B blocked by Session A's exclusive lock: " . ($sessionBBlocked ? "YES (Lock wait timeout confirmed - PASS)" : "NO") . "\n";

// Session A updates status to 'completed' and commits
$updateStmt = $pdo->prepare("UPDATE appointments SET status = 'completed' WHERE id = :id");
$updateStmt->execute([':id' => $concurrencyAppId]);
$pdo->commit();
echo " [PASS] Session A updated status to 'completed' and committed transaction.\n";

// Test 2.2: Stale update rejection on terminal state
$staleUpdateRejected = false;
$staleErrorMsg = '';
try {
    // Session B (or any caller) now attempts a stale transition: completed -> scheduled
    Appointment::updateStatus($concurrencyAppId, Appointment::STATUS_SCHEDULED);
} catch (RuntimeException $e) {
    if ($e->getCode() === 400 && strpos($e->getMessage(), 'terminal') !== false) {
        $staleUpdateRejected = true;
        $staleErrorMsg = $e->getMessage();
    }
}
echo " [CHECK] Stale update rejected on terminal status: " . ($staleUpdateRejected ? "YES (PASS: {$staleErrorMsg})" : "NO") . "\n";

// Test 2.3: Database Rollback verification on invalid transition
$rollbackTested = false;
$appForRollback = Appointment::create([
    'patient_user_id' => $patientId,
    'specialist_name' => 'Dr. Rollback Test',
    'appointment_date' => date('Y-m-d', strtotime('+6 days')),
    'appointment_time' => '03:00 PM'
]);

try {
    // Attempt invalid transition
    Appointment::updateStatus($appForRollback, 'non_existent_status');
} catch (InvalidArgumentException $e) {
    // Verify appointment in DB remains 'scheduled'
    $freshRow = Appointment::findById($appForRollback);
    $rollbackTested = ($freshRow['status'] === 'scheduled');
}
echo " [CHECK] Database rollback on invalid transition leaves appointment intact: " . ($rollbackTested ? "YES (Status remains 'scheduled' - PASS)" : "NO") . "\n";

// Clean up synthetic records
$pdo->exec("DELETE FROM users WHERE email LIKE '%@synthetic-p53-test.com'");
echo "\n====================================================================\n";
echo " CLEANUP: All synthetic test data removed.\n";
echo "====================================================================\n";
