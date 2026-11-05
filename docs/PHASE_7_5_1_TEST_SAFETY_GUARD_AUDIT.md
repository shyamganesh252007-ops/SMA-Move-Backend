# SMA-MOVE — PHASE 7.5.1: TESTSAFETYGUARD FAIL-CLOSED SECURITY AUDIT

**Document Version:** 1.0.0  
**Phase:** 7.5.1 — TestSafetyGuard Fail-Closed Security Audit (Strictly Read-Only Review)  
**Date:** November 5, 2026  
**Auditor:** SMA-Move Independent Security & Architecture Review  
**Target Codebase:** `C:\xampp\htdocs\sma-move-backend`  
**Inspected File:** `tests/TestSafetyGuard.php`  
**Database Identity:** `localhost:3306/sma_move` via `SELECT DATABASE()`  
**Audit Protocol:** STRICTLY READ-ONLY (No modifications to PHP sources, `.htaccess`, databases, schemas, migrations, or tests)

---

## 1. Executive Summary

This targeted security audit evaluates the newly implemented `tests/TestSafetyGuard.php` safety mechanism introduced in Phase 7.5. The guard was designed to prevent destructive database cleanup against production and shared operational databases.

### Summary of Audit Findings
1. **Production Blocking: VERIFIED (PASS).**  
   The guard successfully blocks destructive execution when `APP_ENV` is set to `production`, `prod`, or `staging`, and when the database name contains the substrings `production`, `prod`, or `live`.
2. **Cleanup Scoping: VERIFIED (PASS).**  
   `TestSafetyGuard::cleanRateLimits()` strictly scopes deletions to explicit test keys and actions. It preserves unrelated operational records. `TestSafetyGuard::cleanSyntheticUsers()` strictly requires email patterns to contain the keyword `synthetic`, preventing broad table purges.
3. **Blacklist vs. Allowlist Architecture: DEFICIENT (NEEDS ATTENTION).**  
   The guard currently uses a **blacklist** of forbidden database name fragments (`['production', 'prod', 'live']`) rather than an **explicit allowlist** of approved test database names. If connected to an arbitrary unapproved database (e.g. `customer_data` or `hospital_records`), the guard does not reject execution.
4. **Development Environment Cleanup: DEFICIENT (NEEDS ATTENTION).**  
   `TestSafetyGuard::ALLOWED_ENVIRONMENTS` currently includes `development`, `dev`, and `local`. Consequently, destructive test cleanups are permitted against the primary development database (`sma_move`).
5. **Missing Environment / Database Name Handling: DEFICIENT (NEEDS ATTENTION).**  
   When `APP_ENV` is undefined or empty, `env('APP_ENV', 'development')` falls back to `development` and allows cleanup. Furthermore, if `SELECT DATABASE()` returns an empty string or null, the guard does not fail closed.

---

## 2. Environment Guard Verification Matrix

The behavior of `TestSafetyGuard::assertSafeEnvironment()` was audited across all 12 specified scenarios using simulated configuration and mocked database connections without executing destructive SQL:

| Scenario | Injected Environment (`APP_ENV`) | Simulated Database (`SELECT DATABASE()`) | Expected Security Policy | Actual Guard Result | Compliance Status | Technical Explanation |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **1. Missing `APP_ENV`** | `null` / `''` (Undefined) | `sma_move` | **Reject** | **Allow** | **DEFICIENT** | `env('APP_ENV', 'development')` defaults to `'development'`, which is in `ALLOWED_ENVIRONMENTS`. |
| **2. Production** | `production` | `sma_move` | **Reject** | **Reject** | **PASS** | Blocked with `RuntimeException` (not in `ALLOWED_ENVIRONMENTS`). |
| **3. Shorthand Prod** | `prod` | `sma_move` | **Reject** | **Reject** | **PASS** | Blocked with `RuntimeException` (not in `ALLOWED_ENVIRONMENTS`). |
| **4. Staging** | `staging` | `sma_move` | **Reject** | **Reject** | **PASS** | Blocked with `RuntimeException` (not in `ALLOWED_ENVIRONMENTS`). |
| **5. Development** | `development` | `sma_move` | **Reject destructive cleanup** | **Allow** | **DEFICIENT** | `'development'` is permitted in `ALLOWED_ENVIRONMENTS`. Destructive cleanup is not restricted to test-only mode. |
| **6. Test with Unapproved DB** | `test` | `customer_records` | **Reject** | **Allow** | **DEFICIENT** | Database name does not contain `'production'`, `'prod'`, or `'live'`. Blacklist fails to detect unapproved database. |
| **7. Test with Approved Test DB** | `test` | `sma_move_test` | **Allow** | **Allow** | **PASS** | Allowed; permitted environment and no forbidden fragments. |
| **8. Database Name Missing** | `test` | `''` (Empty/Null) | **Reject** | **Allow** | **DEFICIENT** | When `$dbName === ''`, fragment checks pass. Missing database identity fails open instead of closed. |
| **9. DB Resembling Production** | `test` | `sma_move_production` | **Reject** | **Reject** | **PASS** | Blocked with `RuntimeException` (matches `'production'`). |
| **10. DB with `prod` Fragment** | `test` | `app_prod_db` | **Reject** | **Reject** | **PASS** | Blocked with `RuntimeException` (matches `'prod'`). |
| **11. DB with `live` Fragment** | `test` | `sma_live_data` | **Reject** | **Reject** | **PASS** | Blocked with `RuntimeException` (matches `'live'`). |
| **12. Arbitrary Non-Test DB** | `test` | `financial_db` | **Reject** | **Allow** | **DEFICIENT** | Unapproved database permitted because name lacks forbidden substrings. |

---

## 3. Test Database Connection & Identity Verification

### 3.1 Connection Source & Resolution
Inspection of `config/database.php` and `tests/TestSafetyGuard.php`:
1. **Dynamic Runtime Identity:**  
   `TestSafetyGuard` retrieves the database name directly from the active connection using:
   ```php
   $dbName = strtolower(trim((string)$pdo->query("SELECT DATABASE()")->fetchColumn()));
   ```
   This ensures that the guard inspects the physical database currently bound to the connection, rather than relying on unverified environment variables or client-side strings.
2. **Shared Database Architecture:**  
   Currently, `Database::getConnection()` instantiates a singleton PDO connection using credentials loaded from `.env` (`DB_NAME=sma_move`). Both Apache HTTP requests (e.g. `/api/auth/signup.php`) and CLI test scripts (`tests/test_sec03_rate_limiting.php`) connect to the identical database `sma_move`.
3. **Absence of Dedicated Test Database Configuration:**  
   There is no distinct `TEST_DB_NAME` or `Database::getTestConnection()` method in the backend. Setting up an isolated test database requires configuring a separate database in MariaDB (e.g. `sma_move_test`), applying all 10 migrations, and ensuring that HTTP requests executed via curl during integration tests route to that isolated database context.

---

## 4. Cleanup Method Verification & Scope Review

### 4.1 `TestSafetyGuard::cleanRateLimits()`
```php
public static function cleanRateLimits(PDO $pdo, array $testKeys, array $testActions, ?string $envOverride = null): int
{
    self::assertSafeEnvironment($pdo, $envOverride);

    if (empty($testKeys) || empty($testActions)) {
        throw new InvalidArgumentException("SAFETY GUARD ABORT: cleanRateLimits requires non-empty test keys and actions...");
    }

    $keyPlaceholders = implode(',', array_fill(0, count($testKeys), '?'));
    $actionPlaceholders = implode(',', array_fill(0, count($testActions), '?'));

    $sql = "DELETE FROM rate_limits WHERE rate_key IN ({$keyPlaceholders}) AND action IN ({$actionPlaceholders})";
    $stmt = $pdo->prepare($sql);
    $params = array_merge($testKeys, $testActions);
    $stmt->execute($params);

    return $stmt->rowCount();
}
```
- **Scope Verification:** The SQL statement requires both explicit keys and actions. In `test_sec03_rate_limiting.php`, it targets only:
  - Keys: `['127.0.0.1', '::1', '198.51.100.99', '203.0.113.88']`
  - Actions: `['signup', 'verify_email', 'verify_email_ip', 'login']`
- **Preservation Confirmed:** The residual row in `rate_limits` (`action = 'forgot_pwd'`) was completely preserved across repeated executions of `test_sec03_rate_limiting.php`.

### 4.2 `TestSafetyGuard::cleanSyntheticUsers()`
```php
public static function cleanSyntheticUsers(PDO $pdo, string $emailPattern, ?string $envOverride = null): int
{
    self::assertSafeEnvironment($pdo, $envOverride);

    if (!str_contains(strtolower($emailPattern), 'synthetic')) {
        throw new InvalidArgumentException("SAFETY GUARD ABORT: Email pattern does not contain 'synthetic'...");
    }

    $stmt = $pdo->prepare("DELETE FROM users WHERE email LIKE :pattern");
    $stmt->execute([':pattern' => $emailPattern]);
    return $stmt->rowCount();
}
```
- **Scope Verification:** Blocks broad deletions such as `DELETE FROM users WHERE email LIKE '%'`. Prevents deletion of legitimate patient, caregiver, or clinician records.

### 4.3 Destructive SQL in Legacy Test Suites
The following legacy test suites execute synthetic user deletions directly:
- `tests/test_application_modules.php`: `DELETE FROM users WHERE email LIKE '%@synthetic-sma-test.com'`
- `tests/test_auth.php`: `DELETE FROM users WHERE email LIKE '%@synthetic-smamove-test.com'`
- `tests/test_phase_5_2_corrections.php`: `DELETE FROM users WHERE email LIKE '%@synthetic-phase52-test.com'`
- `tests/test_phase_5_4_meeting_link_security.php`: `DELETE FROM users WHERE email LIKE '%@synthetic-phase54-test.com'`
- `tests/test_roles_rbac.php`: `DELETE FROM users WHERE email LIKE '%@synthetic-rbac-test.com'`

While all of these are strictly scoped to `@synthetic-*` domains and cannot delete real users, they currently execute without calling `TestSafetyGuard::assertSafeEnvironment()`.

---

## 5. Remaining Risks & Required Architectural Hardening

1. **Risk 1: Blacklist of DB Name Fragments (Fragment Bypass):**  
   If a test runs in an environment where the database is named `hospital_records`, `client_db`, or `production_data_2026` (if misspelled or named differently), fragment checking fails open.  
   *Requirement:* Replace `FORBIDDEN_DB_FRAGMENTS` with an explicit **allowlist of approved test databases** (e.g. `ALLOWED_TEST_DATABASES = ['sma_move_test', 'sma_move_dev', 'sma_move']`).
2. **Risk 2: Defaulting to Development when `APP_ENV` is Missing:**  
   If `APP_ENV` is unset in the environment, `env('APP_ENV', 'development')` assumes development.  
   *Requirement:* Fail closed. If `APP_ENV` is not explicitly set, throw `RuntimeException`.
3. **Risk 3: Allowing Destructive Cleanup in `APP_ENV=development`:**  
   Currently, running tests in `development` executes fixture cleanup against the development database.  
   *Requirement:* Require an explicit opt-in flag (e.g. `ALLOW_TEST_CLEANUP=true` or `APP_ENV=testing`) before allowing fixture deletion.
4. **Risk 4: Empty Database Name:**  
   If `SELECT DATABASE()` returns `null` or empty string, the guard does not reject the connection.  
   *Requirement:* Explicitly reject empty database identities (`if ($dbName === '') throw new RuntimeException(...)`).

---

## 6. Findings Classification

| Evaluation Area | Risk Level | Classification | Status & Recommendation |
| :--- | :--- | :--- | :--- |
| **Production Environment Blocking** | Critical | **PASS** | Successfully blocks `production`, `prod`, `staging`, and `production` database fragments. |
| **Scoped Rate-Limit Deletion** | High | **PASS** | Replaced broad `DELETE FROM rate_limits` with strictly keyed deletion. Preserves operational rows. |
| **Scoped Synthetic User Deletion** | High | **PASS** | Strictly enforces `synthetic` keyword in email patterns. Blocks broad wildcard user deletion. |
| **Database Identity Source** | Medium | **PASS** | Identity verified at runtime via `SELECT DATABASE()`. |
| **Missing `APP_ENV` Fallback** | Medium | **NEEDS ATTENTION** | Missing environment defaults to `development` instead of failing closed. |
| **Database Name Blacklist vs. Allowlist**| Medium | **NEEDS ATTENTION** | Unapproved non-production databases are not blocked. An explicit allowlist is required. |
| **Missing Database Name Check** | Low | **NEEDS ATTENTION** | Empty database name from `SELECT DATABASE()` does not trigger an exception. |
| **Legacy Tests Safety Guard Integration**| Low | **NEEDS ATTENTION** | 5 legacy test suites scope deletions to synthetic users, but do not yet call `TestSafetyGuard`. |

---

## 7. Audit Recommendation Regarding Phase 7.5

### Conclusion:
`TestSafetyGuard` represents a substantial security improvement over the previous implementation by successfully eliminating the full-table `DELETE FROM rate_limits` vulnerability and blocking known production environments.

However, to achieve true **fail-closed** maturity:
- **Phase 7.5 can be conditionally approved** as having successfully resolved the immediate critical hazard of full-table rate-limit deletions and verified scoped cleanups.
- Before beginning Phase 8, the 4 specific allowlist enhancements identified in Section 5 (explicit test database allowlist, strict fail-closed on missing `APP_ENV`, empty database rejection, and requiring explicit test authorization) should be authorized for implementation.
