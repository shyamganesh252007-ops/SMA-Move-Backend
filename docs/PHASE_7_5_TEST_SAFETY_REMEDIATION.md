# SMA-MOVE — PHASE 7.5: TEST DATABASE SAFETY & SECURITY DOCUMENTATION CORRECTIONS

**Document Version:** 1.0.0  
**Phase:** 7.5 — Test Database Safety & Documentation Remediation  
**Date:** November 5, 2026  
**Auditor / Engineer:** SMA-Move Security & Core Engineering  
**Target Backend:** `C:\xampp\htdocs\sma-move-backend`  
**Database:** MariaDB 10.4.32 / `sma_move` on `localhost:3306`  
**Runtime:** PHP 8.2.12 / Apache 2.4.58 (Win64)  
**Safety Status:** PASS — All fail-closed guards verified; all 354 test assertions pass (100%).

---

## 1. Executive Summary

Phase 7.5 resolves the remaining maintenance-safety, test database isolation, and documentation inconsistencies identified in Phase 7.4.2:
1. **Elimination of Destructive Test Cleanups:** Replaced the broad, unqualified `$this->pdo->exec("DELETE FROM rate_limits")` in `tests/test_sec03_rate_limiting.php` with a fail-closed, strictly scoped test cleaner (`TestSafetyGuard`).
2. **Fail-Closed Environment Guards:** Implemented `TestSafetyGuard::assertSafeEnvironment()` to actively block any test execution or test fixture deletion if `APP_ENV` indicates `production` or if the connected database contains `production` / `prod` substrings.
3. **Dedicated Safety Test Suite:** Created `tests/test_safety_guard.php` (19 automated assertions) verifying that simulated production environments refuse destructive cleanup, broad user deletion patterns are rejected, and unrelated rate-limit rows remain completely intact.
4. **Documentation Alignment:** Corrected Section 7.1 and 7.2 of `docs/PHASE_7_4_CONSOLIDATED_SECURITY_AUDIT.md` and Section 2.2 of `docs/PHASE_7_4_1_AUDIT_CLARIFICATIONS.md` with transparent correction notes reflecting actual migration filenames and the complete 20-table database inventory.
5. **Production Rate-Limit Maintenance Strategy:** Created `docs/RATE_LIMIT_MAINTENANCE_PLAN.md` detailing safe chunked eviction of stale rate-limit records without disrupting active client sliding windows.
6. **Full Regression Verification:** Executed all 10 test suites end-to-end. All **354 assertions passed with zero failures (100% pass rate)**.

---

## 2. PART 1 — Test Database Safety Implementation

### 2.1 Problem Analysis
In `tests/test_sec03_rate_limiting.php`, fixture cleanup previously contained:
```php
// Unsafe prior implementation:
private function cleanupFixtures(): void
{
    $this->pdo->exec("DELETE FROM users WHERE email LIKE '%@synthetic-sec03-test.com'");
    $this->pdo->exec("DELETE FROM rate_limits"); // DANGER: Unqualified full-table deletion
}
```
If an engineer or automated CI/CD pipeline ran `test_sec03_rate_limiting.php` against a shared staging or production database, this single unqualified `DELETE` would wipe out active sliding windows for all real users, exposing the application to brute-force attacks during that window.

### 2.2 Test Safety Architecture (`tests/TestSafetyGuard.php`)
A dedicated, reusable safety class was created at `tests/TestSafetyGuard.php`:

```php
namespace SmaMove\Tests;

class TestSafetyGuard
{
    private const ALLOWED_ENVIRONMENTS = ['development', 'dev', 'testing', 'test', 'local'];
    private const FORBIDDEN_DB_FRAGMENTS = ['production', 'prod', 'live'];

    public static function assertSafeEnvironment(PDO $pdo, ?string $envOverride = null): void
    {
        $appEnv = strtolower(trim((string)($envOverride ?? env('APP_ENV', 'development'))));

        if (!in_array($appEnv, self::ALLOWED_ENVIRONMENTS, true)) {
            throw new RuntimeException("SAFETY GUARD ABORT: Test execution blocked in environment '{$appEnv}'...");
        }

        $dbName = strtolower(trim((string)$pdo->query("SELECT DATABASE()")->fetchColumn()));
        foreach (self::FORBIDDEN_DB_FRAGMENTS as $fragment) {
            if (str_contains($dbName, $fragment)) {
                throw new RuntimeException("SAFETY GUARD ABORT: Connected database '{$dbName}' matches protected fragment...");
            }
        }
    }
    ...
}
```

#### Key Safety Guard Features:
1. **Fail-Closed Environment Enforcement:** Only allows execution in explicitly recognized development and testing environments (`development`, `dev`, `testing`, `test`, `local`). Throws `RuntimeException` on any unrecognized or production environment.
2. **Database Name Inspection:** Dynamically inspects `SELECT DATABASE()`. If the connected database name contains `production`, `prod`, or `live`, execution is blocked immediately.
3. **Strict Synthetic User Scoping (`cleanSyntheticUsers`):** Rejects broad SQL wildcards like `%` or `@company.com`. Refuses execution unless the email pattern explicitly contains `synthetic`.
4. **Scoped Rate-Limit Deletion (`cleanRateLimits`):** Requires non-empty arrays of specific test keys and actions. Strictly limits deletions to:
   ```sql
   DELETE FROM rate_limits WHERE rate_key IN (...) AND action IN (...)
   ```
5. **Pattern Scoping for Composite Verification Keys (`cleanRateLimitsByPattern`):** Rejects empty or full-table `%` patterns. Scopes composite email verification cleanup to `%:verify_email:%` and `%:verify_email_ip%`.

### 2.3 Refactored `test_sec03_rate_limiting.php` Cleanup
`cleanupFixtures()` in `tests/test_sec03_rate_limiting.php` was updated to:
```php
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
```

---

## 3. PART 2 — Test Cleanup Verification (`tests/test_safety_guard.php`)

A dedicated automated test suite was developed to verify the safety guard. All 19 assertions execute cleanly:

```
====================================================================
 SMA-MOVE — PHASE 7.5 TEST SAFETY GUARD & CLEANUP VERIFICATION
====================================================================

--- 1. Simulated Production Environment Guard Rejection ---
 [PASS] assertSafeEnvironment blocks 'production'
 [PASS] assertSafeEnvironment blocks 'prod'
 [PASS] assertSafeEnvironment blocks 'staging'
 [PASS] cleanSyntheticUsers strictly refuses execution in production
 [PASS] cleanRateLimits strictly refuses execution in production

--- 2. Unsafe User Deletion Pattern Rejection ---
 [PASS] Unsafe pattern '%' rejected with InvalidArgumentException
 [PASS] Unsafe pattern '' rejected with InvalidArgumentException
 [PASS] Unsafe pattern '@gmail.com' rejected with InvalidArgumentException
 [PASS] Unsafe pattern 'user@domain.org' rejected with InvalidArgumentException
 [PASS] Unsafe pattern 'test' rejected with InvalidArgumentException

--- 3. Empty Rate Limit Parameter Rejection ---
 [PASS] Empty keys array rejected
 [PASS] Empty actions array rejected

--- 4. Scoped Rate Limit Cleanup & Record Preservation ---
 [PASS] Scoped cleanup deleted exactly 1 test record
 [PASS] Test fixture record successfully removed
 [PASS] Unrelated record was NOT deleted and remains intact

--- 5. Synthetic User Cleanup Isolation ---
 [PASS] cleanSyntheticUsers removed synthetic user account
 [PASS] Synthetic user record no longer exists

--- 6. Repeated Teardown Idempotency & Clean Baseline ---
 [PASS] Repeated synthetic user cleanup is idempotent (deleted 0 on second run)
 [PASS] Scoped rate limit cleanup on absent key returns 0 without error

--------------------------------------------------------------------
 Test Summary: 19 Passed, 0 Failed
--------------------------------------------------------------------
```

---

## 4. PART 3 — Documentation Corrections

In accordance with Phase 7.5 requirements, previous audit documentation was updated with transparent, dated correction notes:

### 4.1 Corrections to `docs/PHASE_7_4_CONSOLIDATED_SECURITY_AUDIT.md`
- **Section 7.1:** Corrected the migration list to match actual filesystem files in `database/migrations/`:
  1. `001_create_auth_tokens_table.sql`
  2. `002_create_caregiver_relationships_table.sql`
  3. `003_create_care_plans_table.sql`
  4. `004_create_appointments_table.sql`
  5. `005_create_wellbeing_table.sql`
  6. `006_create_consents_movetrack_table.sql`
  7. `007_create_auth_support_tables.sql`
  8. `008_create_patient_invitations_table.sql`
  9. `009_rbac_clinical_and_audit.sql`
  10. `010_movetrack_session_enhancements.sql`
- **Section 7.2:** Updated the table inventory from 16 to the complete **20-table schema**, explicitly categorizing:
  - 3 original baseline tables (`users`, `patient_profiles`, `professional_profiles`).
  - 1 runner infrastructure table (`schema_migrations`).
  - 16 tables created across migrations 001–009.
  - Confirmed 0 records across all 18 clinical, identity, and audit tables.

### 4.2 Corrections to `docs/PHASE_7_4_1_AUDIT_CLARIFICATIONS.md`
- **Section 2.2:** Added Phase 7.5 clarification note detailing:
  - The distinction between 20 physical tables, 18 clinical/identity/audit tables, 1 infrastructure table (`schema_migrations`), and 1 operational table (`rate_limits`).
  - Corrected arithmetic: 3 original + 1 `schema_migrations` + 11 domain tables (Migrations 001–006) = 15 tables during Phase 2; subsequent migrations 007–009 added 5 tables $\rightarrow$ exactly 20 tables.
  - Documented that expired rate-limit records logically expire and remain until evicted during subsequent requests or periodic maintenance.

---

## 5. PART 4 — Production Rate-Limit Maintenance Strategy

The complete production rate-limit maintenance plan was drafted and saved to:  
[RATE_LIMIT_MAINTENANCE_PLAN.md](file:///c:/xampp/htdocs/sma-move-backend/docs/RATE_LIMIT_MAINTENANCE_PLAN.md)

### Key Strategy Points:
1. **Safe Expiration Policy:** Never deletes records whose sliding window is currently active ($\text{now} - \text{window\_start} \le \text{window\_duration}$).
2. **24-Hour Conservative Buffer:** Purges records where $\text{window\_start} < (\text{UNIX\_TIMESTAMP}() - 86400)$, ensuring records are at least 23 hours beyond the longest operational window (`signup` at 1 hour).
3. **Chunked Deletion:** Enforces `LIMIT 1000` to prevent table locks on active production databases.
4. **Scheduled Frequency:** Recommends daily execution at 03:00 UTC via standalone PHP CLI script (`prune_rate_limits.php`) or MariaDB Event Scheduler.
5. **Monitoring & Alerting:** Recommends alert thresholds if `rate_limits` row count exceeds 50,000.

---

## 6. PART 5 — Complete Regression Verification Results

All 10 automated test suites were executed sequentially via PHP CLI (`C:\xampp\php\php.exe`). All test suites passed with **100% success**:

```
====================================================================================================
 SMA-MOVE CONSOLIDATED BACKEND REGRESSION SUMMARY
====================================================================================================
 #   Test Suite                                       Scope                 Assertions   Status
----------------------------------------------------------------------------------------------------
 1   tests/test_foundation.php                        Database, Env, Schema 31 / 31      PASS
 2   tests/test_auth.php                              Auth, Tokens, RBAC    47 / 47      PASS
 3   tests/test_roles_rbac.php                        Role Permissions      33 / 33      PASS
 4   tests/test_application_modules.php               Care, Appt, Journal   57 / 57      PASS
 5   tests/test_phase_5_2_corrections.php             Admin Key, Bounding   42 / 42      PASS
 6   tests/test_phase_5_4_meeting_link_security.php   Meeting Link Trust    19 / 19      PASS
 7   tests/test_sec01_directory_protection.php        Directory Protection  32 / 32      PASS
 8   tests/test_sec02_client_ip_security.php          Trusted Proxy & IP    38 / 38      PASS
 9   tests/test_sec03_rate_limiting.php               Rate Limiting         36 / 36      PASS
 10  tests/test_safety_guard.php                      Test Safety Guard     19 / 19      PASS
----------------------------------------------------------------------------------------------------
 TOTAL PASSING ASSERTIONS:                           354 / 354 (100% Pass Rate, 0 Failures)
====================================================================================================
```

### Physical Database State Verification:
A full table scan of MariaDB `sma_move` confirmed:
- Total tables: **20**.
- Lingering records in all 18 clinical, identity, and audit tables: **0 (clean baseline)**.
- `schema_migrations`: **10** (tracking migrations 001–010).
- `rate_limits`: **1** (`action = 'forgot_pwd'`, preserved untouched by test execution).

---

## 7. Audit Conclusion & Stop

All requirements of Phase 7.5 are complete:
- Destructive unqualified deletes are eliminated from tests.
- Fail-closed environment guards are enforced.
- Documentation inconsistencies are resolved transparently.
- Production rate-limit maintenance strategy is documented.
- All 354 test assertions pass with zero failures.

**Phase 7 is officially complete. Execution paused per instructions. Awaiting explicit user approval before proceeding to Phase 8.**
