# SMA-MOVE — PHASE 7.5.3: DEDICATED TEST ENVIRONMENT ARCHITECTURE DESIGN

**Document Version:** 1.0.0  
**Phase:** 7.5.3 — Dedicated Test Environment Architecture Design (Design & Inspection Only)  
**Date:** November 5, 2026  
**Auditor / Core Architect:** SMA-Move Architecture & Security Team  
**Target Codebase:** `C:\xampp\htdocs\sma-move-backend`  
**Current Development Database:** `sma_move`  
**Proposed Dedicated Test Database:** `sma_move_test`  
**Status:** **DESIGN SPECIFICATION ONLY — NO IMPLEMENTATION OR DESTRUCTIVE ACTIONS TAKEN**

---

## 1. Executive Summary

During Phase 7.5.1 and Phase 7.5.2, an architectural vulnerability was identified in the backend testing model:
- The automated test suites and the live application running under Apache currently share the single development database (`sma_move`).
- Even though Phase 7.5 successfully scoped rate-limit deletions to explicit test keys, true fail-closed data safety requires a **physically isolated test database (`sma_move_test`)** that completely decouples automated tests from development and production databases.
- When introducing a dedicated test database, a critical **split-brain routing challenge** arises: CLI unit/service tests can directly bind to `sma_move_test`, but HTTP-based integration tests (`test_sec03_rate_limiting.php`) dispatch requests to Apache (`httpd.exe`), which connects to the database defined in `.env` (`sma_move`).

This document specifies the complete, end-to-end architectural design for:
1. A dedicated, fail-closed automated test database (`sma_move_test`).
2. An isolated, localhost-only Apache test virtual host (Port 8088) that forces HTTP integration requests into `sma_move_test` without headers and without altering normal development requests.
3. Explicit MariaDB administrative SQL to create `sma_move_test` and grant least-privilege permissions to `sma_app_user@localhost`.
4. A migration initialization strategy for cloning the baseline schema and executing migrations 001–010 on `sma_move_test`.
5. A comprehensive 10-suite routing and cleanup matrix ensuring zero test suites ever touch `sma_move` for destructive cleanup.
6. A fail-closed failure analysis and phased implementation roadmap.

---

## 2. Proposed System Architecture

### 2.1 Component Architecture Overview

```
                                      +---------------------------------------------+
                                      |              DEVELOPMENT CONTEXT            |
                                      |                                             |
[ Mobile App / Browser / Postman ]--->| Apache Port 80 (VirtualHost localhost:80)   |
                                      |   - APP_ENV: development                    |
                                      |   - DB_NAME: sma_move                       |
                                      |   - Database::getConnection()               |
                                      |             |                               |
                                      |             v                               |
                                      |      MariaDB: sma_move                      |
                                      +---------------------------------------------+

                                      +---------------------------------------------+
                                      |         DEDICATED TEST CONTEXT (ISOLATED)   |
                                      |                                             |
[ CLI Test Runner (PHP CLI) ]-------->| TestBootstrap / TestDatabase                |
   - test_auth.php                    |   - APP_ENV: test                           |
   - test_roles_rbac.php              |   - TEST_DB_NAME: sma_move_test             |
   - test_application_modules.php     |   - SELECT DATABASE() == sma_move_test      |
   - test_safety_guard.php            |             |                               |
                                      |             v                               |
[ HTTP Test (test_sec03) ]            |      MariaDB: sma_move_test                 |
   |                                  |             ^                               |
   +--> cURL to 127.0.0.1:8088 ------>| Apache Port 8088 (VirtualHost 127.0.0.1:8088|
                                      |   - APP_ENV: test                           |
                                      |   - DB_NAME: sma_move_test                  |
                                      |   - SetEnv DB_NAME sma_move_test            |
                                      +---------------------------------------------+
```

### 2.2 Dual-Connection Isolation Model
1. **Development Connection (`Database::getConnection()`):**
   - Connects strictly to `DB_NAME` (`sma_move`).
   - Normal application behavior, API controllers, and clinical workflows remain completely untouched.
   - Preserves existing credentials, charset (`utf8mb4`), and PDO attributes (`ATTR_EMULATE_PREPARES = false`).
2. **Dedicated Test Connection (`TestDatabase::getConnection()`):**
   - Resides exclusively in test infrastructure (`tests/TestDatabase.php`).
   - Reads `TEST_DB_NAME` from environment; strictly requires `TEST_DB_NAME === 'sma_move_test'`.
   - Executes `SELECT DATABASE()` immediately after connection. If the returned database is anything other than `sma_move_test`, throws an immediate `RuntimeException` and halts.
   - **Never falls back** to `DB_NAME` or `sma_move`.

---

## 3. Apache HTTP Test Isolation Design

### 3.1 The PHP Environment Loader Override Issue
Inspection of `config/environment.php` revealed a critical detail:
Lines 80–83 of `Environment::load()` currently execute:
```php
putenv("{$key}={$val}");
$_ENV[$key] = $val;
$_SERVER[$key] = $val;
```
Because `Environment::load()` reads `.env` and unconditionally overwrites `putenv`, `$_ENV`, and `$_SERVER`, any Apache directive like `SetEnv DB_NAME sma_move_test` would be **overwritten** by `DB_NAME=sma_move` when `config/config.php` boots.

#### The Architectural Solution:
Update `config/environment.php` to adhere to standard dotenv precedence:
- **Server environment takes precedence over `.env` defaults:** If an environment variable is already set by Apache (`getenv($key) !== false` or `isset($_SERVER[$key])`), `Environment::load()` must preserve the server-provided variable and not overwrite it.
- This allows Apache VirtualHosts to inject environment variables via `SetEnv` cleanly without touching the physical `.env` file.

### 3.2 Apache VirtualHost Configuration
Apache in XAMPP (`C:\xampp\apache\conf\extra\httpd-vhosts.conf`) will be configured with a dedicated, localhost-only listener on **Port 8088**:

```apache
# ==============================================================================
# SMA-MOVE TEST-ONLY VIRTUAL HOST (PORT 8088)
# Isolated Localhost Testing Only — Never Publicly Accessible
# ==============================================================================

Listen 127.0.0.1:8088

<VirtualHost 127.0.0.1:8088>
    ServerName 127.0.0.1
    DocumentRoot "C:/xampp/htdocs"
    
    # Restrict strictly to local loopback connections
    <Directory "C:/xampp/htdocs/sma-move-backend">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require local
    </Directory>

    # Force Test Environment & Database Server-Side
    SetEnv APP_ENV test
    SetEnv DB_NAME sma_move_test
    SetEnv TEST_DB_NAME sma_move_test

    # Security Marker Header (Used by tests to verify vhost routing)
    Header always set X-SMA-Move-Environment "test"
    Header always set X-SMA-Move-Database "sma_move_test"

    ErrorLog "logs/sma_move_test_error.log"
    CustomLog "logs/sma_move_test_access.log" combined
</VirtualHost>
```

#### Key VirtualHost Security Properties:
1. **Loopback Only (`Listen 127.0.0.1:8088` and `Require local`):** Cannot be accessed from external LAN devices, Android emulators outside loopback, or public networks.
2. **Server-Side Activation:** Test environment is set via Apache's `SetEnv`. It is **impossible** for an external HTTP client on Port 80 to activate test mode via HTTP headers.
3. **Dedicated Logs:** Separate access and error logs (`logs/sma_move_test_error.log`) prevent test noise in development logs.

---

## 4. MariaDB Privileges & Administrative SQL

### 4.1 Current Permissions (Audit Ground Truth)
Inspection of `sma_app_user@localhost`:
```sql
GRANT USAGE ON *.* TO `sma_app_user`@`localhost` IDENTIFIED BY PASSWORD '...';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, REFERENCES, INDEX, ALTER ON `sma_move`.* TO `sma_app_user`@`localhost`;
```
`sma_app_user` lacks grants on `sma_move_test` and lacks global `CREATE DATABASE`.

### 4.2 Proposed Administrative SQL
To be executed by MariaDB `root@localhost` (or a database administrator):

```sql
-- 1. Create dedicated test database with identical charset and collation
CREATE DATABASE IF NOT EXISTS `sma_move_test` 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

-- 2. Grant exact least-privilege permissions on test database
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, REFERENCES, INDEX, ALTER 
ON `sma_move_test`.* 
TO `sma_app_user`@`localhost`;

-- 3. Flush privileges to apply grants immediately
FLUSH PRIVILEGES;

-- 4. Verification query to confirm grants
SHOW GRANTS FOR `sma_app_user`@`localhost`;
```

#### Privileges Verification:
The resulting user grants must show:
```sql
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, REFERENCES, INDEX, ALTER ON `sma_move`.* TO `sma_app_user`@`localhost`;
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, REFERENCES, INDEX, ALTER ON `sma_move_test`.* TO `sma_app_user`@`localhost`;
```
No global `SUPER`, `DROP`, or `ALL PRIVILEGES ON *.*` are granted.

---

## 5. Migration & Schema Initialization Strategy

### 5.1 The Baseline Schema Challenge
The SMA-Move database schema originated from 3 baseline tables (`users`, `patient_profiles`, `professional_profiles`) that pre-dated the migration system. The 10 migration files in `database/migrations/` assume these 3 tables already exist.

### 5.2 Two-Step Initialization Sequence
To initialize `sma_move_test` cleanly without altering historical migration files:

#### Step 1: Initialize Baseline Tables
A dedicated initialization utility or CLI command (`database/init_test_db.php`) executes:
```sql
CREATE TABLE IF NOT EXISTS `sma_move_test`.`users` LIKE `sma_move`.`users`;
CREATE TABLE IF NOT EXISTS `sma_move_test`.`patient_profiles` LIKE `sma_move`.`patient_profiles`;
CREATE TABLE IF NOT EXISTS `sma_move_test`.`professional_profiles` LIKE `sma_move`.`professional_profiles`;
```
*(Uses MariaDB `CREATE ... LIKE`, which copies table structures, indexes, and column types without copying any development data).*

#### Step 2: Parameterized Migration Runner
`database/migrate.php` will be updated to support an optional PDO connection parameter:

```php
// In database/migrate.php:
public function __construct(?PDO $pdo = null)
{
    $this->pdo = $pdo ?? Database::getConnection();
    $this->migrationsDir = __DIR__ . DIRECTORY_SEPARATOR . 'migrations';
    $this->ensureMigrationTableExists();
}
```
This allows the test bootstrap to pass `TestDatabase::getConnection()`, running all 10 migrations directly against `sma_move_test`.

---

## 6. Ten-Suite Routing Matrix

Every test suite in `tests/` is mapped to its execution mode, target connection, and cleanup policy:

| # | Test Suite | Primary Target | Connection Factory | Execution Transport | Target Database | Cleanup Method & Guard Policy |
| :-: | :--- | :--- | :--- | :--- | :--- | :--- |
| 1 | `tests/test_foundation.php` | Schema & Core Utils | `TestDatabase::getConnection()` | PHP CLI | `sma_move_test` | Read-only verification; zero deletes. |
| 2 | `tests/test_auth.php` | Auth & Session Life | `TestDatabase::getConnection()` | PHP CLI | `sma_move_test` | `TestSafetyGuard::cleanSyntheticUsers()` on `sma_move_test`. |
| 3 | `tests/test_roles_rbac.php` | Multi-role RBAC | `TestDatabase::getConnection()` | PHP CLI | `sma_move_test` | `TestSafetyGuard::cleanSyntheticUsers()` on `sma_move_test`. |
| 4 | `tests/test_application_modules.php` | Care, Appt, Journal | `TestDatabase::getConnection()` | PHP CLI | `sma_move_test` | `TestSafetyGuard::cleanSyntheticUsers()` on `sma_move_test`. |
| 5 | `tests/test_phase_5_2_corrections.php` | Admin Key & Bounds | `TestDatabase::getConnection()` | PHP CLI | `sma_move_test` | `TestSafetyGuard::cleanSyntheticUsers()` on `sma_move_test`. |
| 6 | `tests/test_phase_5_4_meeting_link_security.php` | Meeting Link Trust | `TestDatabase::getConnection()` | PHP CLI | `sma_move_test` | `TestSafetyGuard::cleanSyntheticUsers()` on `sma_move_test`. |
| 7 | `tests/test_sec01_directory_protection.php` | Apache Rewrite Rules | None (HTTP Only) | cURL $\rightarrow$ Port 80 / 8088 | N/A (Web Server) | Read-only HTTP probes; zero database access. |
| 8 | `tests/test_sec02_client_ip_security.php` | Client IP & Proxies | `TestDatabase::getConnection()` | PHP CLI | `sma_move_test` | `TestSafetyGuard::cleanRateLimits()` on `sma_move_test`. |
| 9 | `tests/test_sec03_rate_limiting.php` | Rate Limiting Endpoints | **Both** (`TestDatabase` + Port 8088) | cURL $\rightarrow$ Port 8088 + CLI | `sma_move_test` | `TestSafetyGuard::cleanRateLimits()` on `sma_move_test`. |
| 10 | `tests/test_safety_guard.php` | Safety Guard Verifier | `TestDatabase::getConnection()` | PHP CLI (Mock + Real) | `sma_move_test` | Scoped fixture cleanup on `sma_move_test`. |

> [!IMPORTANT]
> **Total Elimination of Development Database Deletions:**  
> Under this architecture, **0 out of 10 test suites** execute destructive SQL against `sma_move`. All test cleanups run exclusively inside `sma_move_test`.

---

## 7. Refactored Fail-Closed Guard Specification

`TestSafetyGuard::assertSafeEnvironment()` will be refactored to implement strict allowlist validation:

```php
public static function assertSafeEnvironment(PDO $pdo, ?string $envOverride = null): void
{
    // 1. Fail closed if APP_ENV is missing
    $appEnv = $envOverride ?? env('APP_ENV');
    if ($appEnv === null || $appEnv === '') {
        throw new RuntimeException("SAFETY GUARD ABORT: APP_ENV is undefined. Destructive actions strictly prohibited.");
    }

    // 2. Strict allowlist: Only 'test' is permitted for destructive test cleanup
    $allowedTestEnvironments = ['test', 'testing'];
    if (!in_array(strtolower(trim((string)$appEnv)), $allowedTestEnvironments, true)) {
        throw new RuntimeException(
            "SAFETY GUARD ABORT: Environment '{$appEnv}' is not an authorized test environment. Destructive actions permitted ONLY in 'test'."
        );
    }

    // 3. Require explicit TEST_DB_NAME configuration
    $expectedTestDb = env('TEST_DB_NAME');
    if (empty($expectedTestDb)) {
        throw new RuntimeException("SAFETY GUARD ABORT: TEST_DB_NAME is not configured.");
    }

    // 4. Verify actual runtime database identity
    $actualDb = strtolower(trim((string)$pdo->query("SELECT DATABASE()")->fetchColumn()));
    if ($actualDb === '') {
        throw new RuntimeException("SAFETY GUARD ABORT: Database identity could not be verified (empty).");
    }

    // 5. Strict Allowlist Matching: Exact match against authorized test databases
    $approvedTestDatabases = ['sma_move_test'];
    if (!in_array($actualDb, $approvedTestDatabases, true)) {
        throw new RuntimeException(
            "SAFETY GUARD ABORT: Database '{$actualDb}' is NOT an approved test database. Approved: " . implode(', ', $approvedTestDatabases)
        );
    }

    if ($actualDb !== strtolower(trim((string)$expectedTestDb))) {
        throw new RuntimeException(
            "SAFETY GUARD ABORT: Database mismatch. Connected to '{$actualDb}', but TEST_DB_NAME expects '{$expectedTestDb}'."
        );
    }
}
```

---

## 8. Failure Analysis & Fail-Closed Scenarios

| Failure Scenario | Trigger Condition | System Reaction | Fail-Closed Guarantee |
| :--- | :--- | :--- | :--- |
| **Test DB Missing** | `sma_move_test` dropped or uncreated | `TestDatabase::getConnection()` fails PDO connection (Error 1049). | **Immediate halt.** Never falls back to `sma_move`. |
| **Missing Credentials** | `sma_app_user` lacks grants on `sma_move_test` | PDO throws Error 1045 (Access denied). | **Immediate halt.** Tests cannot start. |
| **Missing `APP_ENV`** | `APP_ENV` unset in CLI or vhost | `TestSafetyGuard` throws `RuntimeException`. | **Immediate halt.** No defaults assumed. |
| **Missing `TEST_DB_NAME`** | `TEST_DB_NAME` omitted from `.env` | `TestSafetyGuard` throws `RuntimeException`. | **Immediate halt.** |
| **Wrong Database Connected** | Tests connected to `sma_move` or `hospital_db` | `SELECT DATABASE()` does not match `sma_move_test`. | `TestSafetyGuard` throws `RuntimeException`. Deletion aborted. |
| **Test Port 8088 Down** | Apache Port 8088 not listening | cURL in `test_sec03` fails with `CURLE_COULDNT_CONNECT`. | Test suite halts with connection error. Zero writes to port 80. |
| **Accidental Request to Port 80** | Integration test URL points to port 80 | Response header `X-SMA-Move-Environment` is missing or `'development'`. | Test suite detects mismatch and aborts before assertion execution. |
| **Production Target Attempt** | Run against `sma_move_production` | Fails both `APP_ENV` allowlist and database allowlist checks. | **Immediate halt.** Zero SQL executed. |

---

## 9. Implementation Sequence (For Subsequent Authorization)

When authorized, implementation will proceed in the following strict order:

1. **Step 1 — Administrative Database Creation:**  
   Execute MariaDB SQL via root to create `sma_move_test` and grant least-privilege permissions to `sma_app_user@localhost`.
2. **Step 2 — Schema Baseline & Migrations:**  
   Create the 3 baseline tables (`users`, `patient_profiles`, `professional_profiles`) in `sma_move_test` and execute all 10 migrations via `database/migrate.php`.
3. **Step 3 — Apache Test VirtualHost Configuration:**  
   Add `<VirtualHost 127.0.0.1:8088>` to `httpd-vhosts.conf`, verify syntax with `httpd -t`, and restart Apache.
4. **Step 4 — Environment Precedence Hardening:**  
   Update `config/environment.php` to respect server-provided `SetEnv` variables.
5. **Step 5 — Test Bootstrap & Connection Factory:**  
   Create `tests/TestDatabase.php` and `tests/test_bootstrap.php` enforcing `APP_ENV=test` and `TEST_DB_NAME=sma_move_test`.
6. **Step 6 — Fail-Closed Safety Guard Refactor:**  
   Refactor `tests/TestSafetyGuard.php` with exact allowlists and zero-fallback logic.
7. **Step 7 — Test Suite Migration & Verification:**  
   Update the 10 test suites to load `test_bootstrap.php` and target Port 8088 for HTTP integration tests.
8. **Step 8 — Full Regression Execution:**  
   Verify that all 354 assertions pass against `sma_move_test` while `sma_move` remains at 0 test records.

---

## 10. Risks & Unresolved Decisions for User Approval

1. **Apache Port Selection:**  
   Port 8088 was selected as the internal test listener. Please confirm whether Port 8088 is free or if an alternative port (e.g. 8081, 8888) is preferred.
2. **Restarting Apache:**  
   Applying the test virtual host configuration in Phase 7.5.4 will require restarting Apache via XAMPP Control Panel.
3. **Preservation of Existing Data:**  
   The proposed plan guarantees 100% preservation of all existing development data in `sma_move`.

---

## 11. Design Conclusion & Stop

This concludes the architectural design phase. **Zero source code, databases, configuration files, or migrations have been modified.**

Awaiting user review and authorization to proceed to implementation.
