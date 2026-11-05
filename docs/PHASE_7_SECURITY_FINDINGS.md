# SMA-MOVE — PHASE 7: BACKEND SECURITY & ARCHITECTURE FINDINGS

**Document Version:** 1.0.0  
**Phase:** 7.0 — Complete Backend Health Audit  
**Date:** November 5, 2026  
**Auditor:** SMA-Move Engineering Audit System  
**Target:** `C:\xampp\htdocs\sma-move-backend`  
**Database:** MariaDB 10.4.32 / `sma_move`  
**Runtime:** PHP 8.2.12 / Apache 2.4.58  

---

## 1. Executive Summary of Findings

Across the 18 audit areas, the SMA-Move backend demonstrates an exceptionally strong security posture, featuring robust prepared statements, complete absence of SQL injection vulnerabilities, strict RBAC controls, hardened telehealth meeting link sanitization, pessimistic database concurrency controls, and active privacy consent gating.

Three non-breaking security configuration enhancements were identified during the live HTTP and code audit:
1. **Directory exposure in Apache rewrite rules** (`middleware/` and `docs/` omitted from `.htaccess` blocking rule).
2. **Spoofable `X-Forwarded-For` header in `RateLimiter::getClientIp()`** in non-proxied environments.
3. **Missing rate limiting on user registration (`signup.php`) and email verification (`verify_email.php`)**.

---

## 2. Findings Matrix

| Finding ID | Audit Area | Severity | Status | Summary |
| :--- | :--- | :--- | :--- | :--- |
| **SEC-01** | Apache Configuration | **Medium** | **RESOLVED (Phase 7.1)** | `docs/` and `middleware/` directories blocked from direct HTTP access in `.htaccess`. Returns HTTP 403. |
| **SEC-02** | Rate Limiting | **Low** | **RESOLVED (Phase 7.2)** | `RateLimiter::getClientIp()` secure by default; defaults strictly to `REMOTE_ADDR`; ignores forwarded headers unless `REMOTE_ADDR` matches configured trusted proxy; traverses right-to-left. |
| **SEC-03** | Abuse Prevention | **Low** | **RESOLVED (Phase 7.3)** | Registration (5 attempts / hr per client IP) and verification (5 attempts / 15 min per IP + account context) rate limited; atomic under concurrency; returns HTTP 429. |
| **SEC-04** | SQL Injection & Prepared Statements | Informational | **PASS** | 100% prepared statements with parameterized inputs. Zero dynamic SQL string concatenations. Native prepared statements enabled. |
| **SEC-05** | Telehealth Meeting Link Sanitization | Informational | **PASS** | Strict patient link suppression (forced `NULL`), HTTPS enforcement, and domain allowlisting for verified clinicians. |
| **SEC-06** | Appointment Concurrency & State Machine | Informational | **PASS** | Pessimistic `FOR UPDATE` transaction locking prevents double-booking; terminal state transitions strictly locked. |
| **SEC-07** | MoveTrack Privacy & Bounding | Informational | **PASS** | Zero video/frame storage; active camera consent verified; strict numerical kinematic bounding on inputs. |
| **SEC-08** | Role-Based Access Control (RBAC) & IDOR | Informational | **PASS** | Granular multi-role authorization; patient-caregiver relationship and consent verified on all sensitive queries. |
| **SEC-09** | Database Least Privilege | Informational | **PASS** | Application connects as `sma_app_user@localhost` with restricted DML/DDL permissions. No `DROP` or `SUPER` grants. |
| **SEC-10** | Authentication & Token Security | Informational | **PASS** | 64-hex cryptographically random tokens, hashed storage, 24-hour expiration, and complete session revocation on logout. |
| **SEC-11** | Administrative API Key Security | Informational | **PASS** | Constant-time string comparison (`hash_equals`); fails closed with HTTP 500 when `ADMIN_API_KEY` is undefined or default. |
| **SEC-12** | CORS & Network Security | Informational | **PASS / NOTED** | Development CORS wildcard `*` active; production origin restriction recommended for deployment. |

---

## 3. Detailed Audit Findings

### Finding SEC-01: Direct HTTP Access to `middleware/` and `docs/`

* **Status:** **RESOLVED (Phase 7.1)**
* **Severity:** **Medium**
* **File & Line:** `c:\xampp\htdocs\sma-move-backend\.htaccess` (Line 33)
* **Description:**  
  The `.htaccess` file defines URL rewriting to route `/api/*` endpoints to corresponding `.php` scripts while attempting to block direct access to internal backend directories. Initially, the directories `middleware/` and `docs/` were omitted from this list, returning HTTP 200 and exposing markdown documentation and executable middleware.
* **Initial Audit Reproduction (Pre-Fix):**
  1. `GET /docs/API_DOCUMENTATION.md` returned **HTTP 200 OK** (Exposed raw documentation).
  2. `GET /middleware/auth_middleware.php` returned **HTTP 200 OK** (Executed outside router context).
* **Remediation Implemented (Phase 7.1):**
  In `.htaccess`, updated the access-control rewrite rule to:
  ```apache
  # Block direct access to internal backend directories
  RewriteRule ^(config|database|docs|logs|middleware|models|services|tests|utils)(/.*)?$ - [F,L]
  ```
* **Post-Fix Verification:**
  - `GET /docs` $\rightarrow$ **HTTP 403 Forbidden**
  - `GET /docs/` $\rightarrow$ **HTTP 403 Forbidden**
  - `GET /docs/API_DOCUMENTATION.md` $\rightarrow$ **HTTP 403 Forbidden**
  - `GET /middleware` $\rightarrow$ **HTTP 403 Forbidden**
  - `GET /middleware/auth_middleware.php` $\rightarrow$ **HTTP 403 Forbidden**
  - Automated test suite `tests/test_sec01_directory_protection.php` verifies all 32 directory/file paths: **32 / 32 Passed**.
  - All 229 existing unit/integration tests remain **100% passing**.
  - Public API (`/api/health.php`) remains fully accessible (**HTTP 200 OK**).

---

### Finding SEC-02: Client-Spoofable `X-Forwarded-For` in `RateLimiter::getClientIp()`

* **Status:** **RESOLVED (Phase 7.2)**
* **Severity:** **Low**
* **File & Line:** `c:\xampp\htdocs\sma-move-backend\utils\rate_limiter.php` (Lines 24–190)
* **Description:**  
  The previous implementation of `RateLimiter::getClientIp()` unconditionally trusted `HTTP_CF_CONNECTING_IP` and `HTTP_X_FORWARDED_FOR`, extracting the first element (`$ips[0]`) without verifying whether `REMOTE_ADDR` originated from a trusted reverse proxy.
* **Remediation Implemented (Phase 7.2):**
  1. **Secure by Default:** Direct requests strictly return `$_SERVER['REMOTE_ADDR']`. Forwarded headers are completely ignored unless `REMOTE_ADDR` matches an explicitly configured trusted proxy.
  2. **Right-to-Left Traversal:** When behind a trusted proxy, walks backwards through the proxy chain from right to left, traversing trusted hops and selecting the rightmost untrusted, valid client IP (never blindly trusting `$ips[0]`).
  3. **Strict Validation & Parsing:** All candidate IPs are validated using `filter_var(..., FILTER_VALIDATE_IP)`. Malformed, empty, or out-of-range strings are rejected with safe fallback to `REMOTE_ADDR`.
  4. **IPv4, IPv6 & CIDR Support:** Exact binary matching via `inet_pton` and CIDR range matching (e.g. `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `2001:db8::/32`).
  5. **Fail-Safe Design:** Missing or empty `TRUSTED_PROXIES` environment configuration fails safely by defaulting to `REMOTE_ADDR`.
* **Verification:**
  - Automated test suite `tests/test_sec02_client_ip_security.php` verified across 38 distinct test cases (**38 / 38 Passed - 100%**).
  - All 8 test suites (**299 total assertions**) remain **100% passing**.

---

### Finding SEC-03: Missing Rate Limiting on `signup.php` and `verify_email.php`

* **Status:** **RESOLVED (Phase 7.3)**
* **Severity:** **Low**
* **Files & Lines:**  
  - `c:\xampp\htdocs\sma-move-backend\api\auth\signup.php` (Lines 29–41)  
  - `c:\xampp\htdocs\sma-move-backend\api\auth\verify_email.php` (Lines 29–65)  
  - `c:\xampp\htdocs\sma-move-backend\utils\rate_limiter.php` (Lines 253–290)
* **Description:**  
  Registration (`signup.php`) and email verification (`verify_email.php`) previously did not invoke `RateLimiter`, exposing endpoints to automated registration abuse and brute-force activation code enumeration.
* **Remediation Implemented (Phase 7.3):**
  1. **Registration Rate Limiting:** Enforces approximately 5 registration attempts per hour per client IP using Phase 7.2 secure client IP identification. All requests (valid, invalid, or duplicate) consume quota. Limit exceeded returns HTTP 429 (`Response::tooManyRequests()`).
  2. **Email Verification Rate Limiting:** Enforces 5 verification attempts per 15 minutes. Keyed on both client IP and SHA-256 hashed target email (`$ip:verify_email:$emailHash`), preventing attackers from exhausting a victim's verification quota while locking brute-force attempts. Supported by a network IP ceiling key.
  3. **Atomic Concurrency Protection:** Refactored `RateLimiter::hit()` to execute atomic single-statement updates without gap-lock deadlocks, ensuring thread-safe attempt tracking under parallel dispatch.
  4. **Information Disclosure Prevention:** Generic error responses ensure no information is leaked about whether an account or email exists.
* **Verification:**
  - Automated test suite `tests/test_sec03_rate_limiting.php` verified across 10 scenarios (**36 / 36 Passed - 100%**).
  - All 9 test suites (**335 total assertions**) remain **100% passing**.

---

### Finding SEC-04: SQL Injection & Prepared Statement Defense

* **Status:** **PASS**
* **Severity:** Informational
* **Files Inspected:** All models (`models/*.php`), services (`services/*.php`), and API controllers (`api/**/*.php`).
* **Evidence:**
  - Automated static analysis scanned every `query()`, `prepare()`, and `exec()` call across the backend codebase.
  - Zero dynamic SQL string concatenations or variable interpolations were detected.
  - `config/database.php` explicitly sets `PDO::ATTR_EMULATE_PREPARES => false`, guaranteeing native MariaDB binary protocol prepared statements rather than client-side emulation.
  - All input parameters are bound using typed placeholders (`:param`).

---

### Finding SEC-05: Telehealth Meeting Link Sanitization & Domain Allowlisting

* **Status:** **PASS**
* **Severity:** Informational
* **Files Inspected:** `api/appointments/create.php`, `utils/validation.php`, `tests/test_phase_5_4_meeting_link_security.php`
* **Evidence:**
  - In `api/appointments/create.php`, non-professional requests (patients and caregivers) have `meeting_link` forcibly assigned to `NULL`:
    ```php
    if ($userRole !== 'professional') {
        $meetingLink = null;
    }
    ```
  - For professionals, `Validation::validateTelehealthUrl()` enforces:
    1. Valid URL structure using `filter_var(..., FILTER_VALIDATE_URL)`.
    2. Strict `https://` protocol only (plain `http://`, `javascript:`, `data:`, or custom schemes fail validation).
    3. Host domain must match the strict telehealth allowlist (`zoom.us`, `teams.microsoft.com`, `meet.google.com`, `doxy.me`, `webex.com`). Arbitrary or spoofed external hosts are rejected with HTTP 400.
  - All 19 security test cases in `test_phase_5_4_meeting_link_security.php` pass successfully.

---

### Finding SEC-06: Appointment Concurrency & Terminal State Locking

* **Status:** **PASS**
* **Severity:** Informational
* **Files Inspected:** `models/Appointment.php`, `api/appointments/update_status.php`, `tests/test_phase_5_2_corrections.php`
* **Evidence:**
  - Status updates utilize an explicit database transaction with pessimistic row locking:
    ```sql
    SELECT status FROM appointments WHERE id = :id FOR UPDATE
    ```
  - State machine enforcement: If an appointment has reached a terminal state (`completed` or `cancelled`), subsequent update attempts are rejected with `409 Conflict`.
  - Double-booking prevention: Slot availability verification checks for overlapping active consultations before booking confirmation.

---

### Finding SEC-07: MoveTrack Privacy & Sensor Bounding

* **Status:** **PASS**
* **Severity:** Informational
* **Files Inspected:** `api/movetrack/start_session.php`, `api/movetrack/complete_session.php`, `models/MoveTrack.php`
* **Evidence:**
  - **No Media Storage:** The backend accepts strictly aggregate numeric kinematic summaries. It does not possess any file upload endpoints or storage columns for raw video footage, camera frames, or audio streams.
  - **Consent Gate:** `api/movetrack/start_session.php` queries the patient's active consent profile before granting session creation. If `camera_tracking` consent is revoked or missing, the request is rejected with `403 Forbidden`.
  - **Numerical Bounding:**
    - Repetition count bounded to integer `[0, 500]`.
    - Range of motion bounded to float `[0.0, 180.0]` degrees.
    - Symmetry score bounded to float `[0.0, 100.0]%`.
    - Negative or out-of-bounds values are rejected with `400 Bad Request`.

---

### Finding SEC-08: Role-Based Access Control (RBAC) & IDOR Protection

* **Status:** **PASS**
* **Severity:** Informational
* **Files Inspected:** `middleware/role_middleware.php`, all API endpoints, `tests/test_roles_rbac.php`
* **Evidence:**
  - 16 distinct role and privacy scenarios tested across patient, caregiver, and professional actors (33/33 tests passed).
  - Caregivers cannot access patient data unless:
    1. An active relationship exists in `caregiver_patient_relations` with status `approved`.
    2. Specific granular permissions (`can_view_wellbeing`, `can_view_care_plan`, `can_edit_assistance`) are enabled.
    3. The patient has not revoked consent.
  - Professionals cannot access patient clinical records unless they are verified by the administrator AND explicitly authorized by the patient in `patient_doctor_authorizations`.

---

### Finding SEC-09: Database Least Privilege Configuration

* **Status:** **PASS**
* **Severity:** Informational
* **Database User:** `sma_app_user@localhost`
* **Evidence:**
  - Grants verified via `SHOW GRANTS FOR 'sma_app_user'@'localhost'`:
    ```sql
    GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, REFERENCES, INDEX, ALTER ON `sma_move`.* TO 'sma_app_user'@'localhost'
    ```
  - The application user does **NOT** possess administrative privileges (`SUPER`, `GRANT OPTION`, `FILE`, `PROCESS`, `SHUTDOWN`).
  - The user cannot perform `DROP` operations on database tables, preventing catastrophic accidental schema truncation.

---

### Finding SEC-10: Authentication Token Lifecycle & Storage

* **Status:** **PASS**
* **Severity:** Informational
* **Files Inspected:** `models/User.php`, `middleware/auth_middleware.php`, `api/auth/*.php`
* **Evidence:**
  - Tokens are generated using PHP's cryptographically secure pseudo-random generator: `bin2hex(random_bytes(32))` (64 hexadecimal characters, 256 bits of entropy).
  - Tokens are stored hashed in the database (`user_tokens` table) using SHA-256 (`hash('sha256', $token)`). If the database is compromised, plaintext bearer tokens are not exposed.
  - Bearer tokens expire strictly after 24 hours (`expires_at < NOW()`).
  - Calling `api/auth/logout.php` or `api/auth/reset_password.php` immediately revokes tokens in the database, preventing replay attacks.
  - Bearer token comparison utilizes `hash_equals()` to prevent timing side-channel attacks.

---

### Finding SEC-11: Admin Key Fail-Closed Architecture

* **Status:** **PASS**
* **Severity:** Informational
* **Files Inspected:** `api/admin/verify_professional.php`, `tests/test_phase_5_2_corrections.php`
* **Evidence:**
  - The administrative verification endpoint requires an `X-Admin-Key` header matching `ADMIN_API_KEY` in `.env`.
  - If `ADMIN_API_KEY` is undefined, empty string `""`, or left as default placeholders (`change_me_in_production`), the endpoint fails closed with `500 Internal Server Error`, refusing to evaluate requests.
  - Key validation uses `hash_equals()` to prevent timing leaks.

---

### Finding SEC-12: CORS Configuration

* **Status:** **PASS / NOTED**
* **Severity:** Informational
* **File:** `config/config.php` (Line 42)
* **Evidence:**
  - `header('Access-Control-Allow-Origin: *')` is configured to facilitate multi-device local development (enabling the OnePlus physical device to connect over the college LAN without pre-negotiating origin domains).
  - **Production Recommendation:** Before production internet deployment, restrict `Access-Control-Allow-Origin` to the specific trusted web domains and mobile app application IDs.
