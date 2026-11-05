# SMA-MOVE — PHASE 7: COMPLETE BACKEND HEALTH AUDIT

**Document Version:** 1.0.0  
**Phase:** 7.0 — Complete Backend Health Audit  
**Date:** November 5, 2026  
**Auditor:** SMA-Move Engineering Audit System  
**Audit Scope:** Read-Only Full Backend Health, Security, Concurrency, and Architecture Audit  
**Target Root:** `C:\xampp\htdocs\sma-move-backend`  
**Database:** MariaDB 10.4.32 / `sma_move`  
**Runtime:** PHP 8.2.12 / Apache 2.4.58 (XAMPP Windows)  

---

## 1. Executive Summary

This comprehensive audit of the SMA-Move PHP + MariaDB backend was performed to assess system health, operational stability, security posture, and readiness for end-to-end integration with the Kotlin/Jetpack Compose Android application.

### Key Audit Findings
1. **Automated Test Coverage & Pass Rate:** All **229 automated unit and integration tests passed (100%)** across six test suites covering foundation, authentication, role-based access control, application data modules, state machines, and telehealth link security.
2. **Database & Migration Integrity:** All **10 database migrations** have been cleanly applied with complete table schema conformity, foreign key constraints, `utf8mb4` character encoding, and zero orphaned records.
3. **Database Privilege Isolation:** The backend connects using a restricted account (`sma_app_user@localhost`) with minimal necessary DML and index permissions; administrative rights (`SUPER`, `GRANT OPTION`, `DROP`, `FILE`) are withheld.
4. **SQL Injection Defense:** Complete absence of dynamic SQL string interpolation. All database interactions leverage native MariaDB prepared statements with `PDO::ATTR_EMULATE_PREPARES => false`.
5. **Biometric & Media Privacy:** MoveTrack strictly ingests aggregate numerical kinematic summaries. The backend maintains zero storage or ingestion endpoints for raw video, audio, or photographic frames.
6. **Telehealth Security:** Meeting links submitted by patients and caregivers are forcibly suppressed to `NULL`. Verified healthcare professionals are restricted to trusted HTTPS domains (`zoom.us`, `teams.microsoft.com`, `meet.google.com`, `doxy.me`, `webex.com`).
7. **Concurrency & Terminal State Locks:** Appointment scheduling and state transitions utilize pessimistic database row locks (`SELECT ... FOR UPDATE`), strictly preventing double-booking and illegal transitions out of terminal states (`completed`, `cancelled`).
8. **Audit Findings & Post-Audit Resolutions:**
   - **[RESOLVED - Phase 7.1]** `docs/` and `middleware/` added to `.htaccess` rewrite restriction rules; direct web exposure of documentation and raw middleware scripts blocked with HTTP 403 Forbidden ([Finding SEC-01](file:///C:/xampp/htdocs/sma-move-backend/docs/PHASE_7_SECURITY_FINDINGS.md#finding-sec-01)).
   - **[RESOLVED - Phase 7.2]** Client IP identification hardened in `RateLimiter::getClientIp()`; defaults strictly to `REMOTE_ADDR`, ignores forwarded headers unless behind trusted proxies, and traverses chains right-to-left ([Finding SEC-02](file:///C:/xampp/htdocs/sma-move-backend/docs/PHASE_7_SECURITY_FINDINGS.md#finding-sec-02)).
   - **[RESOLVED - Phase 7.3]** Rate limiting extended to user registration (`signup.php` - 5/hr) and email verification (`verify_email.php` - 5/15min); atomic under concurrency; anti-DoS quota isolation ([Finding SEC-03](file:///C:/xampp/htdocs/sma-move-backend/docs/PHASE_7_SECURITY_FINDINGS.md#finding-sec-03)).

---

## 2. Architecture & Environment Overview

```
                      +-----------------------------------+
                      |   Android Client (Kotlin/Compose) |
                      |    EncryptedTokenStorage (AES-GCM)|
                      +-----------------+-----------------+
                                        | HTTP / Bearer Auth
                                        v
                      +-----------------+-----------------+
                      |      Apache 2.4 / .htaccess       |
                      |  - Mod_Rewrite to API Controllers |
                      |  - Directory Access Restrictions  |
                      +-----------------+-----------------+
                                        |
       +--------------------------------+--------------------------------+
       |                                |                                |
       v                                v                                v
+---------------+              +-----------------+              +-----------------+
| Public Routes |              | Auth Middleware |              | Role Middleware |
| - Health      |              | - Bearer Token  |              | - RBAC Guard    |
| - Signup/Login|              | - SHA-256 Check |              | - Consent Gate  |
| - Pwd Reset   |              | - Expiry Check  |              | - IDOR Guard    |
+-------+-------+              +--------+--------+              +--------+--------+
        |                               |                                |
        +-------------------------------+--------------------------------+
                                        |
                                        v
                      +-----------------+-----------------+
                      |     PDO Prepared Transactions     |
                      |   - Native Emulation = false      |
                      |   - FOR UPDATE Pessimistic Locks  |
                      +-----------------+-----------------+
                                        |
                                        v
                      +-----------------+-----------------+
                      |      MariaDB 10.4 (sma_move)      |
                      |  - 20 InnoDB Tables (utf8mb4)     |
                      |  - sma_app_user@localhost         |
                      +-----------------------------------+
```

---

## 3. Detailed Audit by Area

### 3.1 Apache and PHP Configuration
* **Runtime:** PHP 8.2.12 (CLI & Apache SAPI), Apache 2.4.58.
* **Modules:** `mod_rewrite`, `mod_headers`, `openssl`, `pdo_mysql` enabled and functional.
* **CORS Settings:** Configured via `config/config.php` with `Access-Control-Allow-Origin: *` to support multi-device LAN testing (OnePlus physical device $\leftrightarrow$ Lab workstation).
* **Directory Protections:** `.htaccess` successfully blocks direct HTTP access to internal backend directories (`config/`, `database/`, `docs/`, `logs/`, `middleware/`, `models/`, `services/`, `tests/`, `utils/`) returning HTTP 403 Forbidden. Files matching sensitive extensions (`.env`, `.log`, `.sql`) are similarly blocked. Resolved in Phase 7.1 ([Finding SEC-01](file:///C:/xampp/htdocs/sma-move-backend/docs/PHASE_7_SECURITY_FINDINGS.md#finding-sec-01)).

### 3.2 Database Connectivity and Application Permissions
* **Database User:** `sma_app_user@localhost`
* **Grants:** `SELECT, INSERT, UPDATE, DELETE, CREATE, REFERENCES, INDEX, ALTER ON sma_move.*`
* **Privilege Restrictions:** No administrative grants (`SUPER`, `GRANT`, `FILE`, `DROP`, `RELOAD`).
* **Connection Attributes:**
  - `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`
  - `PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC`
  - `PDO::ATTR_EMULATE_PREPARES => false`
* **Engine & Character Set:** All 20 tables use the `InnoDB` storage engine with `utf8mb4_unicode_ci` collation.

### 3.3 Migration Integrity and Schema History
* **Migration Runner:** `database/migrate.php`
* **Execution Status:** 10 applied / 10 available migrations. Clean state verified with `php database/migrate.php status`.
* **Migration History:**
  1. `001_create_users_table.sql`: Users, credentials, roles, email verification states.
  2. `002_create_user_tokens_table.sql`: Hashed bearer tokens with device and expiry tracking.
  3. `003_create_patient_profiles_table.sql`: SMA clinical types, mobility indicators, emergency contacts.
  4. `004_create_caregiver_relations_table.sql`: Caregiver invitations, linking, and granular permissions.
  5. `005_create_professional_verification_table.sql`: License verification, specialty, administrative review.
  6. `006_create_patient_consent_table.sql`: Granular patient consent flags and revocation timestamps.
  7. `007_create_care_plan_tables.sql`: Daily care tasks, schedules, steps, and completion records.
  8. `008_create_appointments_table.sql`: Virtual and in-person consultations with status machines.
  9. `009_create_wellbeing_journal_table.sql`: Daily physical/emotional scores and caregiver notes.
  10. `010_create_movetrack_tables.sql`: Biometric kinematic exercise sessions and metric bounding.

### 3.4 API Inventory and HTTP Methods
* **Total Endpoints:** 42 dedicated endpoint files across 11 modules.
* **Method Enforcement:** All endpoints enforce exact HTTP verbs (`GET`, `POST`, `PUT`, `DELETE`). Invalid verbs cleanly return `405 Method Not Allowed`.
* **Response Envelope:** Uniform JSON format across success (`{"status": "success", "data": ...}`) and failure (`{"status": "error", "message": ...}`).

### 3.5 Authentication and Bearer Token Security
* **Token Entropy:** 64-hexadecimal characters generated via `random_bytes(32)`.
* **Database Storage:** Stored as SHA-256 hashes (`hash('sha256', $token)`).
* **Token Expiration:** Hard 24-hour expiration from creation.
* **Token Revocation:** Calling `api/auth/logout.php` or `api/auth/reset_password.php` immediately removes or marks token rows as invalid.
* **Comparison Safety:** Constant-time verification using `hash_equals()`.

### 3.6 Role-Based Access Control (RBAC) & IDOR Protection
* **Three Primary Roles:** `patient`, `caregiver`, `professional`.
* **Role Enforcement:** Handled via `middleware/role_middleware.php`.
* **IDOR Barriers:**
  - Caregivers cannot access patient records without an `approved` status in `caregiver_patient_relations` and active patient consent.
  - Professionals cannot view patient clinical metrics without clinical verification AND explicit patient authorization in `patient_doctor_authorizations`.

### 3.7 Care Plan Management
* **Task Creation:** Clinicians or patients can define structured care tasks with ordered sub-steps.
* **Task Completion:** Patients and authorized caregivers (with `can_edit_assistance = true`) can log completion.
* **Temporal Tracking:** Supports date-specific completion queries with idempotency guards.

### 3.8 Appointment Concurrency & State Machine
* **Concurrency Locking:** Employs `SELECT ... FOR UPDATE` row locks inside database transactions during appointment booking and status modifications.
* **State Machine:**
  - `scheduled` $\rightarrow$ `completed`
  - `scheduled` $\rightarrow$ `cancelled`
  - Any transition attempt from `completed` or `cancelled` returns `409 Conflict`.
* **Slot Collision:** Verifies practitioner and patient schedule conflicts before booking.

### 3.9 Wellbeing Journal
* **Metrics:** Validated numerical bounds for fatigue (1-5), mood (1-5), pain (0-10), and sleep hours (0-24).
* **Caregiver Permissions:** Requires `can_log_wellbeing` permission.
* **Historical Trend:** Paginated access for clinical review.

### 3.10 MoveTrack Biometrics & Video Privacy
* **Zero Video Storage:** No video or audio files are ingested, uploaded, or stored on disk or database.
* **Patient Privacy Gate:** Verifies active patient consent for `camera_tracking` before session initiation.
* **Server-Side Data Bounding:**
  - Repetition Count: $[0, 500]$
  - Duration: $[1, 86400]$ seconds
  - Range of Motion: $[0.0, 180.0]$ degrees
  - Symmetry Score: $[0.0, 100.0]\%$

### 3.11 Telehealth Meeting Link Sanitization
* **Patient/Caregiver Link Suppression:** Meeting links submitted by patients or caregivers are forcibly set to `NULL`.
* **Clinician Domain Allowlist:** Only verified professionals may provide meeting links, restricted to:
  - `zoom.us`
  - `teams.microsoft.com`
  - `meet.google.com`
  - `doxy.me`
  - `webex.com`
* **Protocol Requirement:** Mandatory `https://` protocol. Custom schemes (`javascript:`, `data:`, `file:`) are rejected.

### 3.12 Administrative Key Verification
* **Fail-Closed Design:** `api/admin/verify_professional.php` requires `X-Admin-Key`.
* If `ADMIN_API_KEY` is missing, blank, or set to placeholder values, the endpoint fails closed with `500 Internal Server Error`.
* Key verification uses `hash_equals()` to avoid timing leaks.

### 3.13 SQL Injection & Prepared Statements
* 100% of database queries use PDO prepared statements with bound parameters.
* Native prepared statements enabled via `PDO::ATTR_EMULATE_PREPARES => false`.
* Zero string concatenations or variable interpolations exist in query construction.

### 3.14 Input Sanitization & XSS Prevention
* Centralized sanitization in `utils/validation.php`.
* Input strings stripped of control characters and HTML entities safely encoded.
* JSON request parsing rejects malformed payloads with `400 Bad Request`.

### 3.15 Rate Limiting & Abuse Prevention
* `RateLimiter` enforces windowed request quotas using IP tracking.
* Login endpoint restricted to 5 attempts per 15 minutes.
* Password reset restricted to 3 requests per hour.
* Enhancements noted in Findings SEC-02 and SEC-03.

### 3.16 Sensitive Data Exposure & Logging
* Passwords hashed with `PASSWORD_BCRYPT` (cost factor 12).
* Sensitive config files (`.env`, `config.php`, `error.log`) protected from web access.
* Zero plaintext passwords or session tokens stored in logs.

### 3.17 Error Handling & Information Disclosure
* Production-safe JSON error responses.
* Database exceptions caught and masked from leaking raw SQL or schema traces.

### 3.18 CORS & Network Security
* Development wildcard origin enabled for local multi-device testing.
* Production origin binding recommended prior to cloud deployment.

---

## 4. Production Deployment Readiness Checklist

| Category | Item | Audit Status | Production Requirement |
| :--- | :--- | :---: | :--- |
| **Apache** | URL Rewriting & Routing | **PASS** | `mod_rewrite` enabled and verified. |
| **Apache** | Directory Protections | **PASS** | `docs`, `middleware`, and internal directories blocked with 403 Forbidden (SEC-01 resolved). |
| **PHP** | Error Display | **CONFIG** | Ensure `display_errors = Off` in production `php.ini`. |
| **Database** | Migrations Applied | **PASS** | 10/10 migrations applied in `sma_move`. |
| **Database** | User Privileges | **PASS** | `sma_app_user` has zero administrative or DROP grants. |
| **Security** | SQL Injection Defense | **PASS** | 100% native prepared statements. |
| **Security** | Telehealth Link Gating | **PASS** | HTTPS and domain allowlist active. |
| **Security** | MoveTrack Privacy | **PASS** | Zero media storage; numerical kinematic bounding active. |
| **Security** | Rate Limiting | **PASS** | `RateLimiter` active across login, forgot-password, signup, and verify-email (SEC-02 and SEC-03 resolved). |
| **CORS** | Origin Control | **NOTED** | Restrict wildcard `*` to specific production origins. |

---

## 5. Associated Audit Documents

1. [API Inventory (42 Endpoints)](file:///C:/xampp/htdocs/sma-move-backend/docs/PHASE_7_API_INVENTORY.md)
2. [Security & Architecture Findings](file:///C:/xampp/htdocs/sma-move-backend/docs/PHASE_7_SECURITY_FINDINGS.md)
3. [Test Execution & Verification Results (229 Tests)](file:///C:/xampp/htdocs/sma-move-backend/docs/PHASE_7_TEST_RESULTS.md)
