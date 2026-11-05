# SMA-MOVE — PHASE 7.4.1: CLARIFICATION AUDIT REPORT

**Document Version:** 1.0.0  
**Phase:** 7.4.1 — Backend Security Audit Clarifications (Read-Only Independent Review)  
**Date:** November 5, 2026  
**Target Backend:** `C:\xampp\htdocs\sma-move-backend`  
**Database:** MariaDB 10.4.32 / `sma_move` on `localhost:3306`  
**Runtime:** PHP 8.2.12 / Apache 2.4.58 (Win64)  
**Audit Protocol:** STRICTLY READ-ONLY (No modifications to application code, `.htaccess`, schemas, migrations, or tests)

---

## 1. Executive Summary

This clarification audit addresses three specific observations arising from the `PHASE_7_4_CONSOLIDATED_SECURITY_AUDIT.md` report:
1. **Database Inventory Discrepancy:** Complete enumeration of all 20 tables in `sma_move`, explaining why Phase 7.4 listed 14 clinical/auth tables plus 2 infrastructure tables (16 total), and verifying the exact row count of every table.
2. **Apache Access-Control & Rewrite Mechanics:** Inspection of `.htaccess`, explaining how `/DOCS/` is blocked despite the regex lacking the `[NC]` flag, identifying the exact directive that blocks `/.git/config`, and re-testing all specified test paths via live HTTP.
3. **Signup Concurrency & Rate Limiting Dynamics:** Inspection of the 7-request parallel dispatch test in `tests/test_sec03_rate_limiting.php`, verifying the initial counter state, explaining the race-free concurrency mechanics that resulted in 1 Created (201) and 6 Throttled (429), and documenting fresh isolated experiments contrasting concurrent vs. serial execution.

---

## 2. Investigation 1: Database Inventory Discrepancy

### 2.1 Complete Table Enumeration & Row Counts
An automated diagnostic query (`SHOW TABLES`) was executed against the live `sma_move` database. Exactly **20 tables** exist in the schema:

```sql
SELECT table_name, table_rows 
FROM information_schema.tables 
WHERE table_schema = 'sma_move';
```

#### Actual Row Counts per Table:
| # | Table Name | Purpose / Domain | Origin | Row Count |
| :--- | :--- | :--- | :--- | :--- |
| 1 | `appointments` | Telehealth consultation sessions | Migration 004 | **0** |
| 2 | `auth_tokens` | Bearer session tokens (access/refresh) | Migration 001 | **0** |
| 3 | `care_task_steps` | Granular instructions for care tasks | Migration 003 | **0** |
| 4 | `care_tasks` | Clinical rehabilitation plans/tasks | Migration 003 | **0** |
| 5 | `caregiver_profiles` | Caregiver demographic & profile details | Migration 002 | **0** |
| 6 | `caregiver_relationships` | Patient-caregiver links & permissions | Migration 002 | **0** |
| 7 | `consents` | Privacy and MoveTrack camera consents | Migration 006 | **0** |
| 8 | `email_verifications` | Email verification codes & expiry | Migration 007 | **0** |
| 9 | `movetrack_sessions` | Kinematic adherence & sensor metrics | Migration 006 / 010 | **0** |
| 10 | `password_resets` | Password reset tokens & hash | Migration 001 | **0** |
| 11 | `patient_invitations` | Patient-issued caregiver invite tokens | Migration 008 | **0** |
| 12 | `patient_professional_access`| Clinician-patient authorization links | Migration 009 | **0** |
| 13 | `patient_profiles` | Patient clinical profile data | Original schema | **0** |
| 14 | `patient_task_completions` | Daily task completion adherence logs | Migration 003 | **0** |
| 15 | `permission_audit_logs` | Audit trail of sensitive role mutations | Migration 009 | **0** |
| 16 | `professional_profiles` | Clinician credentials and status | Original schema | **0** |
| 17 | `rate_limits` | Sliding window rate limiting counters | Migration 007 | **1** *(transient)* |
| 18 | `schema_migrations` | Migration version tracking (001–010) | Migration runner | **10** |
| 19 | `users` | Core user credentials & role | Original schema | **0** |
| 20 | `wellbeing_journal_entries` | Patient self-reported symptoms/mood | Migration 005 | **0** |

*Note on `rate_limits`: The single row corresponds to the transient sliding window record generated during test execution (`action = 'forgot_pwd'`). All 18 clinical, identity, and audit tables have exactly **0** records.*

### 2.2 Root Cause of the Inventory Discrepancy
Why did earlier verification reports note 20 tables while Section 7.2 of `PHASE_7_4_CONSOLIDATED_SECURITY_AUDIT.md` tabulated 16 (14 clinical/auth + 2 infrastructure)?

> [!NOTE]
> **Phase 7.5 Clarification Note (November 5, 2026):**
> 1. **Schema Categorization Distinction:**
>    - **20 Physical Tables:** Total tables in MariaDB `sma_move`.
>    - **18 Clinical, Identity & Audit Tables:** The core data domain (`users`, `patient_profiles`, `caregiver_profiles`, `professional_profiles`, `auth_tokens`, `password_resets`, `caregiver_relationships`, `care_tasks`, `care_task_steps`, `patient_task_completions`, `appointments`, `wellbeing_journal_entries`, `consents`, `movetrack_sessions`, `email_verifications`, `patient_invitations`, `patient_professional_access`, `permission_audit_logs`). All 18 currently have exactly **0 lingering records**.
>    - **1 Infrastructure Table:** `schema_migrations` (10 rows tracking migration executions). Created by `database/migrate.php`, not by a numbered migration file.
>    - **1 Operational Security Table:** `rate_limits` (transient sliding windows). Contains 1 residual row from testing. Note that expired rate-limit records logically expire and may remain until evicted during subsequent requests or periodic maintenance.
> 2. **Migration Lineage:**
>    Migrations 001–006 created **11 domain tables**. Together with 3 original tables and `schema_migrations`, this totaled 15 tables during Phase 2. Subsequent migrations 007–009 added 5 tables (`email_verifications`, `rate_limits`, `patient_invitations`, `patient_professional_access`, `permission_audit_logs`), bringing the total to exactly 20. Migration 010 modified existing columns in `movetrack_sessions` without creating a table.

1. **Phase 2 Foundation Baseline Heritage:**  
   During Phase 2 (`tests/test_foundation.php`), the schema test verified the **3 original tables** (`users`, `patient_profiles`, `professional_profiles`), the runner infrastructure table `schema_migrations`, plus the first **11 migration tables** created by Migrations 001–006. This totaled **15 tables**.
2. **Subsequent Migrations (007–009):**  
   In later phases (Phases 3 to 5), additional migrations were introduced:
   - Migration 007 added: `email_verifications` and `rate_limits` (+2 tables $\rightarrow$ 17 tables)
   - Migration 008 added: `patient_invitations` (+1 table $\rightarrow$ 18 tables)
   - Migration 009 added: `patient_professional_access` and `permission_audit_logs` (+2 tables $\rightarrow$ 20 tables)
3. **Phase 7.4 Table Summary Omission:**  
   When drafting Section 7.2 of the Phase 7.4 audit report, the author copied the baseline table list from the earlier Foundation verification test suite and appended `rate_limits`. Consequently, **4 valid application tables** created in Migrations 007, 008, and 009 were inadvertently omitted from that specific markdown display table:
   - `email_verifications` (Migration 007)
   - `patient_invitations` (Migration 008)
   - `patient_professional_access` (Migration 009)
   - `permission_audit_logs` (Migration 009)
4. **Current Schema Verification:**  
   All 4 omitted tables exist, are structurally sound with proper foreign keys and indexes, and currently contain **0 records**. The actual total database table count is confirmed at **20 tables**.

---

## 3. Investigation 2: Apache Protection Rules & Case-Sensitivity Mechanics

### 3.1 Complete Inspection of `.htaccess`
The entire contents of `c:\xampp\htdocs\sma-move-backend\.htaccess` were read directly from disk:

```apache
# ==============================================================================
# SMA-MOVE BACKEND — APACHE SERVER CONFIGURATION
# ==============================================================================

# Block direct web access to sensitive environment, config, and database files
<FilesMatch "^\.env|^\.git|composer\.(json|lock)|package\.json|.*\.sql$|.*\.log$">
    Order allow,deny
    Deny from all
</FilesMatch>

# Prevent directory index listing
Options -Indexes

# Security Headers
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>

# URL Rewriting
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /sma-move-backend/

    # Pass Authorization Header to PHP in Apache
    SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    # Block direct access to internal backend directories
    RewriteRule ^(config|database|docs|logs|middleware|models|services|tests|utils)(/.*)?$ - [F,L]
</IfModule>
```

### 3.2 Live HTTP Re-Test of Specified Target Paths
Each path requested in the prompt was tested directly against the running Apache server (`http://localhost/sma-move-backend`):

```bash
# Diagnostic command executed via PHP curl
GET /docs/             => HTTP 403 Forbidden
GET /DOCS/             => HTTP 403 Forbidden
GET /.git/config       => HTTP 403 Forbidden
GET /.env              => HTTP 403 Forbidden
GET /composer.json     => HTTP 403 Forbidden
GET /api/health.php    => HTTP 200 OK
```

### 3.3 Rule Attribution & Mechanism Breakdown

#### A. Why `/DOCS/` is blocked despite the `RewriteRule` lacking `[NC]`
In standard Apache on case-sensitive filesystems (Linux ext4), `RewriteRule ^(docs)(/.*)?$` without the `[NC]` flag matches only lowercase `docs`. However, in this environment, `/DOCS/` returns **HTTP 403 Forbidden** due to two compounding factors:

1. **Windows NTFS Path Canonicalization (Filesystem Mapping):**  
   Windows NTFS is case-insensitive. When Apache's Win32 request mapper evaluates an incoming URI such as `/DOCS/` or `/DOCS/API_DOCUMENTATION.md`, the underlying operating system call (`apr_stat`) resolves `DOCS` to the physical directory `docs`. Before evaluating per-directory `.htaccess` rewrite rules, Apache on Windows canonicalizes the path against the disk casing (`docs`). When `mod_rewrite` receives the path relative to the `.htaccess` directory, it evaluates `docs/...`, which matches `^docs(/.*)?$` and triggers the `[F,L]` (Forbidden) action.
2. **`Options -Indexes` Directive (Line 12):**  
   When a directory URL such as `/DOCS/` is requested, Apache attempts to serve a directory index. Because no index file (`index.php`, `index.html`) resides inside `docs/`, and `Options -Indexes` is explicitly set, Apache immediately returns **HTTP 403 Forbidden** with error code `AH01276: Cannot serve directory ... server-generated directory index forbidden by Options directive`.

> [!WARNING]
> **Cross-Platform / Linux Hosting Caveat:**  
> On a Linux server running ext4, the filesystem is case-sensitive. A request to `/DOCS/` would not map to `/docs/` and would return HTTP 404 (Not Found) rather than 403. While 404 does not expose any files, adding the `[NC]` (No Case) flag to the rewrite rule in `.htaccess` (`RewriteRule ^(config|database|docs|...)(/.*)?$ - [NC,F,L]`) is an industry best practice to guarantee case-insensitive blocking at the regex layer regardless of operating system.

#### B. Which actual rule blocks `/.git/config`
Inspection of Apache's `error.log` during the probe of `/.git/config` shows:
```
[access_compat:error] [pid 7828:tid 1872] AH01797: client denied by server configuration: C:/xampp/htdocs/sma-move-backend/.git
```
The rule responsible is lines 6–9 in `.htaccess`:
```apache
<FilesMatch "^\.env|^\.git|composer\.(json|lock)|package\.json|.*\.sql$|.*\.log$">
    Order allow,deny
    Deny from all
</FilesMatch>
```
The regex pattern `^\.git` matches any request beginning with `.git` (including `.git/`, `.git/config`, and `.gitignore`). Apache's `mod_access_compat` evaluates this directive and denies access with **HTTP 403 Forbidden**.

#### C. Attribution Summary Table:
| Requested URI | Actual Status | Responsible Directive / Module | Mechanism |
| :--- | :--- | :--- | :--- |
| `/docs/` | **HTTP 403** | `RewriteRule` (line 33) & `Options -Indexes` (line 12) | Matches `^docs(/.*)?$` in rewrite rule; directory listing disabled. |
| `/DOCS/` | **HTTP 403** | `Options -Indexes` (line 12) & Windows NTFS canonicalization + `RewriteRule` | Directory listing forbidden by `Options -Indexes`; NTFS resolves `DOCS` to `docs` matching rewrite rule. |
| `/.git/config` | **HTTP 403** | `<FilesMatch>` (line 6) | Matches `^\.git` pattern; `Deny from all` enforced by Apache access control. |
| `/.env` | **HTTP 403** | `<FilesMatch>` (line 6) | Matches `^\.env` pattern; `Deny from all` enforced. |
| `/composer.json` | **HTTP 403** | `<FilesMatch>` (line 6) | Matches `composer\.(json\|lock)` pattern; `Deny from all` enforced. |
| `/api/health.php` | **HTTP 200** | Standard routing | Does not match any deny or rewrite rules; public execution permitted. |

---

## 4. Investigation 3: Signup Concurrency Test Analysis

### 4.1 Test Setup Inspection (`test_sec03_rate_limiting.php`)
Inspection of lines 255–310 in `tests/test_sec03_rate_limiting.php` reveals the exact test harness:

```php
private function testRegistrationConcurrentAttempts(): void
{
    echo "\n--- 4. Concurrent Registration Under Parallel Dispatch ---\n";
    $this->cleanupFixtures(); // Line 258: Empties test users AND executes DELETE FROM rate_limits

    $mh = curl_multi_init();
    $handles = [];
    for ($i = 1; $i <= 7; $i++) {
        $ch = curl_init($this->baseUrl . '/api/auth/signup.php');
        // Prepares 7 unique valid registration payloads
        ...
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    // Dispatches all 7 curl handles in parallel
    ...
}
```

#### Counter State Prior to Dispatch
Line 258 explicitly calls `$this->cleanupFixtures()`, which executes:
```php
$this->pdo->exec("DELETE FROM rate_limits");
```
**Verification:** Prior to dispatching the 7 concurrent requests, the rate-limit counter was confirmed to be **exactly zero (0 rows in `rate_limits`)**.

### 4.2 Why One Request Returned 201 and Six Returned 429
Under a configured limit of 5 attempts per hour (`RATE_LIMIT_SIGNUP_ATTEMPTS = 5`), one might naively expect exactly 5 requests to return HTTP 201 and 2 requests to return HTTP 429. However, the test reported:
`Parallel Dispatch Result: 1 Created (201), 6 Throttled (429)`

#### Concurrency Mechanics Trace:
1. **Endpoint Flow in `api/auth/signup.php`:**
   ```php
   // Check 1: Pre-flight check
   if (RateLimiter::isExceeded($ip, 'signup', $maxAttempts, $windowSeconds)) {
       Response::tooManyRequests(...);
   }

   // Atomic increment
   $currentAttempts = RateLimiter::hit($ip, 'signup', $windowSeconds);
   if ($currentAttempts > $maxAttempts) {
       Response::tooManyRequests(...);
   }

   // Downstream processing: Request validation & DB user creation
   $input = RequestValidator::validate($rules);
   $result = AuthService::signup($input);
   Response::created($result, ...);
   ```
2. **`RateLimiter::hit()` Execution Under Parallel Dispatch:**
   When 7 requests hit Apache simultaneously via `curl_multi`:
   - All 7 PHP worker processes execute `RateLimiter::hit()` in microsecond succession.
   - The first process executes `INSERT INTO rate_limits VALUES (..., attempts = 1)`.
   - The remaining 6 processes execute atomic `UPDATE rate_limits SET attempts = attempts + 1`.
   - Within milliseconds, the shared MariaDB row counter rapidly increments: `1 -> 2 -> 3 -> 4 -> 5 -> 6 -> 7`.
3. **The Post-Increment Value Read:**
   Inside `RateLimiter::hit()` (lines 287–289):
   ```php
   $query = $pdo->prepare("SELECT attempts FROM rate_limits WHERE rate_key = ? AND action = ?");
   $query->execute([$rateKey, $action]);
   return (int)$query->fetchColumn();
   ```
   Because `RateLimiter::hit()` queries the database *after* executing the update, parallel threads that read `attempts` after other threads have already committed their increments receive the latest counter value.
   - By the time threads 2, 3, 4, 5, 6, and 7 query `SELECT attempts`, the counter in MariaDB has already crossed 5 (reaching 6 or 7).
   - In `signup.php`, line 42 evaluates `if ($currentAttempts > 5)`. Because `$currentAttempts` was read as 6 or 7, those threads immediately abort with **HTTP 429 Too Many Requests**.
   - Only the fastest thread (or threads) whose read occurred before the 6th increment was applied obtained `$currentAttempts <= 5` and proceeded to execute `AuthService::signup()` to return **HTTP 201 Created**.
4. **Why This is Secure Behavior:**
   This is the intended protective behavior of an atomic sliding window rate limiter under attack:
   - **No race-condition account bypass:** An attacker attempting to flood the endpoint with concurrent requests *never* creates more than the allowed quota (5 accounts).
   - **Fail-secure:** Under parallel flood, the limiter throttles aggressively rather than permissively.

### 4.3 Isolated Experiments: Concurrent vs. Serial Execution
To verify this behavior, two isolated tests were conducted from a clean state (`attempts = 0`):

#### Experiment A: 7 Concurrent Requests (Simultaneous `curl_multi`)
```
Initial signup rate_limits rows: 0
Request 1 => HTTP 429 (Too many registration attempts)
Request 2 => HTTP 201 (Registration successful. Account created.)
Request 3 => HTTP 429 (Too many registration attempts)
Request 4 => HTTP 429 (Too many registration attempts)
Request 5 => HTTP 429 (Too many registration attempts)
Request 6 => HTTP 429 (Too many registration attempts)
Request 7 => HTTP 201 (Registration successful. Account created.)
Final rate_limits row: attempts = 7
Cleaned up test users and rate limit record.
```
*Result:* Exactly 2 succeeded (201) and 5 were throttled (429). The shared counter reached 7. The test assertion `($created <= 5 && $throttled >= 2 && $created + $throttled === 7)` passed.

#### Experiment B: 7 Serial Requests (Sequential Execution)
```
Initial signup rate_limits rows: 0
Serial Request 1 => HTTP 201 (Created)
Serial Request 2 => HTTP 201 (Created)
Serial Request 3 => HTTP 201 (Created)
Serial Request 4 => HTTP 201 (Created)
Serial Request 5 => HTTP 201 (Created)
Serial Request 6 => HTTP 429 (Too many registration attempts)
Serial Request 7 => HTTP 429 (Too many registration attempts)
Serial Summary: Exactly 5 Created (201), Exactly 2 Throttled (429)
Cleaned up test users and rate limit record.
```
*Result:* When executed sequentially, the counter increments predictably from 1 to 5 for the first 5 requests, and requests 6 and 7 are throttled with HTTP 429.

---

## 5. Unresolved Limitations & Production Recommendations

1. **Explicit Case-Insensitive Flag in `.htaccess`:**  
   While Windows NTFS automatically provides case-insensitive directory resolution, a future non-breaking enhancement before Linux production deployment should add the `[NC]` flag to line 33 of `.htaccess`:
   ```apache
   RewriteRule ^(config|database|docs|logs|middleware|models|services|tests|utils)(/.*)?$ - [NC,F,L]
   ```
2. **Database Clean Baseline Confirmed:**  
   All 20 database tables are verified intact. Zero lingering test records remain across all clinical and user tables.

---

## 6. Audit Conclusion & Stop

All three audit observations have been thoroughly investigated, verified with live reproduction tests, and clarified:
- The database schema comprises **20 tables**, all clean with 0 test rows.
- `/DOCS/` is blocked by a combination of `Options -Indexes` and Windows NTFS canonicalization into `RewriteRule`; `/.git/config` is blocked by `<FilesMatch>`.
- The signup concurrency test starts at zero; under microsecond parallel dispatch, atomic increments push the shared counter to 7 rapidly, safely throttling excess concurrent requests with HTTP 429 while serial execution yields exactly 5 successes and 2 throttled.

Per phase instructions, this concludes Phase 7.4.1. No further action or modifications will be taken without user authorization.
