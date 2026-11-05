# SMA-MOVE — PHASE 7.5.2: TEST DATABASE ISOLATION & PERMISSIONS BLOCKING REPORT

**Document Version:** 1.0.0  
**Phase:** 7.5.2 — Dedicated Test Database & Fail-Closed Test Safety  
**Date:** November 5, 2026  
**Auditor / Engineering System:** SMA-Move Core Architecture & Security  
**Target Environment:** `C:\xampp\htdocs\sma-move-backend`  
**Current Development Database:** `localhost:3306/sma_move`  
**Proposed Dedicated Test Database:** `localhost:3306/sma_move_test`  
**Status:** **BLOCKED — STOPPED PRIOR TO MODIFICATIONS (Awaiting Authorization)**

---

## 1. Executive Summary & Stop Notification

Per the explicit instructions in Phase 7.5.2:
> *"Before creating the test database, inspect the existing database permissions and migration architecture. If safe creation or isolation is not possible, STOP and report the blocker rather than weakening the safety requirements."*

An in-depth inspection of database permissions, environment configuration, and test dispatch architectures was executed. Two substantive technical blockers and one input communication issue were identified:
1. **Database Grants Limitation:** The application database user (`sma_app_user@localhost`) has permissions restricted strictly to `sma_move.*`. It does not possess permissions to create `sma_move_test` or access tables within it.
2. **Apache HTTP vs. CLI Test Disconnect (Split-Brain Architecture):** HTTP integration tests (specifically `tests/test_sec03_rate_limiting.php`) dispatch HTTP POST requests to Apache (`http://localhost/sma-move-backend/api/auth/...`). Apache runs in an isolated web server process reading `config/database.php` and `.env` (`DB_NAME=sma_move`). If CLI tests switch their connection to `sma_move_test`, Apache will continue executing against `sma_move`, causing test mutations to leak into the development database while test cleanups run against an empty test database.
3. **Prompt Truncation:** The Phase 7.5.2 authorization prompt was truncated in transmission at Section 4 (`## 4. Legacy Test Integration \n Update the following suites to use the dedicated test bootstrap and TestSafetyGuard: \n -`).

In adherence to strict safety rules, **no files were modified and no databases were created**. This report documents the findings and presents clear architectural pathways for resolution.

---

## 2. Investigation 1: Database Permissions Blocker

### 2.1 Current Grants Inspection
A live SQL query (`SHOW GRANTS FOR CURRENT_USER()`) was executed using the configured application credentials (`sma_app_user@localhost`):

```sql
GRANT USAGE ON *.* TO `sma_app_user`@`localhost` IDENTIFIED BY PASSWORD '*32A3791488F52A8445CCEB3A13C497D323941C03';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, REFERENCES, INDEX, ALTER ON `sma_move`.* TO `sma_app_user`@`localhost`;
```

### 2.2 Permissions Findings
1. **Scope Restricted to `sma_move`:** `sma_app_user` has DML and DDL privileges on the `sma_move` database only.
2. **No Global `CREATE` Privilege:** `sma_app_user` cannot execute `CREATE DATABASE sma_move_test`.
3. **No Grants on `sma_move_test`:** Even if `sma_move_test` is created by another user, `sma_app_user` cannot connect, create tables, or execute queries against it without explicit grants.

### 2.3 Required Administrative Prerequisite
To establish `sma_move_test`, an administrative MariaDB user (such as `root@localhost`) must execute:

```sql
CREATE DATABASE IF NOT EXISTS `sma_move_test` 
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, REFERENCES, INDEX, ALTER 
ON `sma_move_test`.* TO `sma_app_user`@`localhost`;

FLUSH PRIVILEGES;
```

---

## 3. Investigation 2: Apache HTTP vs. CLI Database Routing Disconnect

### 3.1 Test Architecture Breakdown
The backend test suite is composed of two fundamentally different testing patterns:

#### A. Direct CLI Test Suites (8 Suites)
- `tests/test_foundation.php`
- `tests/test_auth.php`
- `tests/test_roles_rbac.php`
- `tests/test_application_modules.php`
- `tests/test_phase_5_2_corrections.php`
- `tests/test_phase_5_4_meeting_link_security.php`
- `tests/test_sec02_client_ip_security.php`
- `tests/test_safety_guard.php`

*Execution Pattern:* These run entirely within a single PHP CLI process. They instantiate services and models directly (e.g. `AuthService::login()`, `RateLimiter::hit()`, `CarePlanService::findTasksForPatient()`).  
*Impact:* If a dedicated test connection (e.g. `TestDatabase::getConnection()`) is provided pointing to `sma_move_test`, all 8 CLI test suites execute directly and seamlessly against `sma_move_test`.

#### B. HTTP Integration Test Suites (2 Suites)
- `tests/test_sec01_directory_protection.php` (Read-only Apache rewrite checks; no DB writes).
- `tests/test_sec03_rate_limiting.php` (Dispatches HTTP POST requests via cURL to Apache endpoints: `/api/auth/signup.php`, `/api/auth/verify_email.php`, `/api/auth/login.php`).

### 3.2 The Split-Brain Routing Problem
When `test_sec03_rate_limiting.php` sends an HTTP POST request to `http://localhost/sma-move-backend/api/auth/signup.php`:
1. Apache spawns a separate worker thread (`httpd.exe`).
2. `api/auth/signup.php` boots `config/config.php` and calls `Database::getConnection()`.
3. `Database::getConnection()` reads `.env` from the project root (`DB_NAME=sma_move`).
4. Apache connects to **`sma_move`**.
5. `signup.php` inserts user records and rate-limit hits into **`sma_move`**.
6. Concurrently, `test_sec03_rate_limiting.php` in CLI is connected to **`sma_move_test`**.
7. When `cleanupFixtures()` runs in the CLI test, it executes deletions against **`sma_move_test`**, leaving the newly created test records lingering in **`sma_move`**!
8. Furthermore, rate-limit threshold assertions in `test_sec03` fail because the CLI verification queries an empty `sma_move_test` while the HTTP endpoints incremented counters in `sma_move`.

---

## 4. Proposed Resolution Pathways

To solve the split-brain disconnect while honoring Phase 7.5.2 safety requirements, three architectural pathways are available:

### Pathway 1: Dual-Mode Environment Switching in `Database::getConnection()` (Recommended)
Allow the application to dynamically honor `TEST_DB_NAME` when `APP_ENV=test`:
1. Update `.env` to define:
   ```ini
   APP_ENV=development
   DB_NAME=sma_move
   TEST_DB_NAME=sma_move_test
   ```
2. When automated tests run, they set an environment variable or dispatch HTTP requests with a cryptographically verified internal header (e.g. `X-SMA-Test-Mode: <HMAC(APP_SECRET)>`).
3. In `config/database.php`, if `APP_ENV === 'test'` or the verified test header is present on localhost requests, `Database::getConnection()` selects `TEST_DB_NAME`. Otherwise, it strictly defaults to `DB_NAME`.

### Pathway 2: Environment File Toggle (`.env.test`)
1. Create a dedicated `.env.test` file defining `APP_ENV=test` and `DB_NAME=sma_move_test`.
2. A test runner script toggles Apache to use `.env.test` during the test run and restores `.env` immediately upon completion.
3. *Limitation:* If a test crashes mid-execution, Apache could remain pointed to `.env.test`.

### Pathway 3: Hybrid Database Isolation
1. All 8 direct CLI test suites migrate immediately to `TestDatabase::getConnection()` pointing to `sma_move_test`.
2. HTTP-based tests (`test_sec03_rate_limiting.php`) remain on `sma_move` using the hardened `TestSafetyGuard` scoped key cleanup (already verified and passing).

---

## 5. Phase 7.5.1 Guard Deficiencies to be Addressed Once Unblocked

Once the database and routing architecture is authorized, `TestSafetyGuard::assertSafeEnvironment()` will be refactored to enforce strict fail-closed validation:

```php
// Target Fail-Closed Refactor:
public static function assertSafeEnvironment(PDO $pdo, ?string $envOverride = null): void
{
    // 1. Explicitly require APP_ENV=test
    $appEnv = $envOverride ?? env('APP_ENV');
    if ($appEnv !== 'test') {
        throw new RuntimeException("SAFETY GUARD ABORT: Destructive cleanup only permitted when APP_ENV='test'. Current: '{$appEnv}'");
    }

    // 2. Explicitly require TEST_DB_NAME
    $expectedTestDb = env('TEST_DB_NAME');
    if (empty($expectedTestDb)) {
        throw new RuntimeException("SAFETY GUARD ABORT: TEST_DB_NAME is not configured.");
    }

    // 3. Verify runtime connection identity via SELECT DATABASE()
    $actualDb = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
    if (empty($actualDb)) {
        throw new RuntimeException("SAFETY GUARD ABORT: Database identity could not be verified (empty).");
    }

    // 4. Exact match against explicitly approved test database allowlist
    $approvedDatabases = ['sma_move_test'];
    if (!in_array($actualDb, $approvedDatabases, true) || $actualDb !== $expectedTestDb) {
        throw new RuntimeException("SAFETY GUARD ABORT: Connected to unapproved database '{$actualDb}'. Expected '{$expectedTestDb}'");
    }
}
```

This refactor directly resolves all 4 deficiencies highlighted in Phase 7.5.1:
- Missing `APP_ENV` fails closed (throws exception).
- `APP_ENV=development` is rejected for destructive actions.
- Empty database names are rejected.
- Fragment blacklists are replaced with an exact string allowlist (`['sma_move_test']`).

---

## 6. Stop Condition & Next Steps

In compliance with Phase 7.5.2 instructions, all implementation has been paused. 

### Awaiting User Authorization On:
1. Creating `sma_move_test` and granting permissions to `sma_app_user@localhost` via administrative root.
2. Selection of the HTTP test routing pathway (Pathway 1 Recommended).
3. The complete, untruncated text of Section 4 (`## 4. Legacy Test Integration`) to ensure all intended test suites are addressed.
