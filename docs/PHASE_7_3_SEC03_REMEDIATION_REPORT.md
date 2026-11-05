# SMA-MOVE — PHASE 7.3: SEC-03 REGISTRATION & EMAIL VERIFICATION RATE LIMITING REMEDIATION REPORT

**Document Version:** 1.0.0  
**Phase:** 7.3 — SEC-03 Registration & Email Verification Rate Limiting  
**Date:** November 5, 2026  
**Auditor / Implementer:** SMA-Move Engineering Team  
**Scope:** Remediation and Verification of SEC-03 in `signup.php` and `verify_email.php`  
**Target Root:** `C:\xampp\htdocs\sma-move-backend`  
**Runtime:** PHP 8.2.12 CLI & Apache 2.4 / MariaDB 10.4.32  

---

## 1. Executive Summary

During the Phase 7.0 backend health audit, Finding **SEC-03** identified that while `api/auth/login.php` and `api/auth/forgot_password.php` enforced brute-force rate limits, `api/auth/signup.php` and `api/auth/verify_email.php` lacked rate-limiting guards. This omission exposed the platform to automated account registration spam and brute-force activation code guessing.

In Phase 7.3, server-side abuse prevention was implemented across both endpoints:
1. **Registration Rate Limiting (`signup.php`):** Implemented a rate limit of approximately 5 registration attempts per hour per client IP (`RATE_LIMIT_SIGNUP_ATTEMPTS=5`, `RATE_LIMIT_SIGNUP_WINDOW=3600`).
2. **Email Verification Rate Limiting (`verify_email.php`):** Implemented a limit of approximately 5 verification attempts per 15 minutes (`RATE_LIMIT_VERIFY_ATTEMPTS=5`, `RATE_LIMIT_VERIFY_WINDOW=900`), keyed against both the client IP and the SHA-256 hash of the target email (`$ip:verify_email:$emailHash`), supplemented by a network IP ceiling key.
3. **Anti-DoS Quota Isolation:** Attacking or brute-forcing a specific account from an external IP only locks out that attacker's IP context; the legitimate user is not locked out from their own network.
4. **Information Disclosure Prevention:** Generic error responses prevent revealing whether an email address exists.
5. **Atomic Concurrency:** `RateLimiter::hit()` was enhanced to use single-statement atomic queries without gap-lock deadlocks, guaranteeing thread-safety under parallel request floods.
6. **HTTP 429 Status:** Rate-limited requests immediately terminate with HTTP 429 Too Many Requests using the standard JSON API error envelope.

---

## 2. Files Modified

| File Path | Component | Summary of Changes |
| :--- | :--- | :--- |
| `api/auth/signup.php` | API Endpoint | Added pre-validation and post-hit rate limiting (5 attempts / hour) keyed on client IP via Phase 7.2 `RateLimiter::getClientIp()`. Returns HTTP 429 when exceeded. |
| `api/auth/verify_email.php` | API Endpoint | Added dual-context rate limiting (5 attempts / 15 minutes) bound to `$ip:verify_email:$emailHash` and network ceiling key. Resets counter on success, increments on failure. Returns HTTP 429 when exceeded. |
| `utils/rate_limiter.php` | Core Utility | Enhanced `hit()` method to perform atomic single-statement updates, avoiding InnoDB gap-lock deadlocks under concurrent parallel dispatch. |
| `tests/test_sec03_rate_limiting.php` | Test Suite | New automated test suite with **36 assertions** across 10 distinct security scenarios. |
| `docs/PHASE_7_SECURITY_FINDINGS.md` | Audit Documentation | Updated Finding SEC-03 from `NEEDS ATTENTION` to `RESOLVED (Phase 7.3)`. |
| `docs/PHASE_7_BACKEND_HEALTH_AUDIT.md` | Audit Documentation | Updated executive summary and readiness checklist to reflect all audit findings resolved. |
| `docs/PHASE_7_TEST_RESULTS.md` | Audit Documentation | Added Suite 8 and Suite 9 results, bringing grand total to **335 passing assertions**. |
| `docs/PHASE_7_3_SEC03_REMEDIATION_REPORT.md` | Phase Deliverable | This comprehensive remediation and verification report. |

---

## 3. Implementation Details

### 3.1 Registration Rate Limiting (`api/auth/signup.php`)
```php
// SEC-03: Rate Limiting for Account Registration
$ip = RateLimiter::getClientIp();
$maxAttempts = (int)env('RATE_LIMIT_SIGNUP_ATTEMPTS', 5);
$windowSeconds = (int)env('RATE_LIMIT_SIGNUP_WINDOW', 3600); // 1 hour

if (RateLimiter::isExceeded($ip, 'signup', $maxAttempts, $windowSeconds)) {
    Response::tooManyRequests('Too many registration attempts from this address. Please try again in 1 hour.');
}

// Record attempt against rate limit quota atomically (ensures race-free throttling under concurrency)
$currentAttempts = RateLimiter::hit($ip, 'signup', $windowSeconds);
if ($currentAttempts > $maxAttempts) {
    Response::tooManyRequests('Too many registration attempts from this address. Please try again in 1 hour.');
}
```
* **Abuse Prevention Policy:** Ingested attempts (including malformed JSON, missing fields, weak passwords, and duplicate emails) consume the rate-limit counter, preventing attackers from fuzzing or probing validation rules indefinitely.

### 3.2 Email Verification Rate Limiting (`api/auth/verify_email.php`)
```php
// SEC-03: Rate Limiting for Email Verification Attempts
$ip = RateLimiter::getClientIp();
$maxAttempts = (int)env('RATE_LIMIT_VERIFY_ATTEMPTS', 5);
$windowSeconds = (int)env('RATE_LIMIT_VERIFY_WINDOW', 900); // 15 minutes
$ipCeilingKey = $ip . ':verify_email_ip';

// Check global IP ceiling (prevents network-level verification code spraying)
if (RateLimiter::isExceeded($ipCeilingKey, 'verify_email_ip', $maxAttempts * 3, $windowSeconds)) {
    Response::tooManyRequests('Too many verification attempts from this network. Please try again in 15 minutes.');
}

// Validate input rules
$rules = [
    'email' => ['required', 'email'],
    'code' => ['required']
];

$input = RequestValidator::validate($rules);

$normalizedEmail = strtolower(trim((string)$input['email']));
$emailHash = hash('sha256', $normalizedEmail);
// Bind rate limit to both client IP and target account context to prevent cross-user denial-of-service
$contextKey = $ip . ':verify_email:' . $emailHash;

if (RateLimiter::isExceeded($contextKey, 'verify_email', $maxAttempts, $windowSeconds)) {
    Response::tooManyRequests('Too many verification attempts for this account. Please try again in 15 minutes.');
}

// Record attempt atomically under concurrency
$currentContextAttempts = RateLimiter::hit($contextKey, 'verify_email', $windowSeconds);
RateLimiter::hit($ipCeilingKey, 'verify_email_ip', $windowSeconds);

if ($currentContextAttempts > $maxAttempts) {
    Response::tooManyRequests('Too many verification attempts for this account. Please try again in 15 minutes.');
}

try {
    AuthService::verifyEmail($normalizedEmail, (string)$input['code']);

    // Successful activation resets the failed attempt counter for this account context
    RateLimiter::clear($contextKey, 'verify_email');

    Response::success(null, 'Account successfully verified and activated. You may now sign in.');
} catch (\RuntimeException $e) {
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 400;
    Response::error($e->getMessage(), $code);
}
```
* **Anti-DoS Quota Isolation:** Attacking user `victim@example.com` from `198.51.100.5` binds the attempts to key `198.51.100.5:verify_email:<hash>`. The legitimate user accessing from `203.0.113.10` has a distinct key and is completely unaffected.
* **Non-Enumerating Error Envelope:** When an unverified email or invalid code is submitted, the endpoint returns HTTP 400 with `"Invalid or expired verification code."` without revealing account presence.

---

## 4. Test Results and Execution Evidence

A dedicated automated security test suite was created in [`tests/test_sec03_rate_limiting.php`](file:///c:/xampp/htdocs/sma-move-backend/tests/test_sec03_rate_limiting.php) and executed via `php.exe`. All 36 test assertions passed:

```text
====================================================================
 SMA-MOVE — PHASE 7.3 SEC-03 REGISTRATION & VERIFY RATE LIMITING
====================================================================

--- 1. Registration Rate Limiting (Normal & Exceeded) ---
 [PASS] Registration attempt 1/5 succeeded (HTTP 201)
 [PASS] Registration attempt 2/5 succeeded (HTTP 201)
 [PASS] Registration attempt 3/5 succeeded (HTTP 201)
 [PASS] Registration attempt 4/5 succeeded (HTTP 201)
 [PASS] Registration attempt 5/5 succeeded (HTTP 201)
 [PASS] Registration attempt 6/5 blocked with HTTP 429
 [PASS] Response envelope success is false on 429
 [PASS] Response message indicates rate limit exceeded

--- 2. Registration Forged Header Bypass Defense ---
 [PASS] Spoofed X-Forwarded-For rejected with HTTP 429 (untrusted connection ignores header)
 [PASS] Spoofed CF-Connecting-IP rejected with HTTP 429

--- 3. Invalid Registration Payloads Counted Against Quota ---
 [PASS] Invalid request 1 returns HTTP 422 or 400
 [PASS] Invalid request 2 returns HTTP 422 or 400
 [PASS] Invalid request 3 returns HTTP 422 or 400
 [PASS] Invalid request 4 returns HTTP 422 or 400
 [PASS] Attempt 5 succeeds (HTTP 201)
 [PASS] Attempt 6 blocked with HTTP 429 (invalid payloads consumed quota)

--- 4. Concurrent Registration Under Parallel Dispatch ---
 Parallel Dispatch Result: 4 Created (201), 3 Throttled (429)
 [PASS] At most 5 registrations allowed and remaining requests throttled with 429

--- 5. Email Verification Rate Limiting (5 Attempts / 15 Min) ---
 [PASS] Wrong verification attempt 1/5 rejected with HTTP 400
 [PASS] Wrong verification attempt 2/5 rejected with HTTP 400
 [PASS] Wrong verification attempt 3/5 rejected with HTTP 400
 [PASS] Wrong verification attempt 4/5 rejected with HTTP 400
 [PASS] Wrong verification attempt 5/5 rejected with HTTP 400
 [PASS] Attempt 6 blocked with HTTP 429 (brute-force threshold reached)
 [PASS] Response envelope indicates verification rate limit exceeded

--- 6. Anti-DoS: Cross-Account Verification Quota Isolation ---
 [PASS] User B can verify successfully; not locked out by Account A attacks (HTTP 200)

--- 7. Email Verification Forged Header Bypass Defense ---
 [PASS] Spoofed X-Forwarded-For cannot bypass verification lockout (HTTP 429)

--- 8. Expired and Consumed Verification Codes ---
 [PASS] Initial verification succeeds with 200 OK
 [PASS] Reused verification code rejected with HTTP 400
 [PASS] Expired verification code rejected with HTTP 400

--- 9. Concurrent Verification Under Parallel Dispatch ---
 Parallel Verification Result: 4 Rejected (400), 4 Throttled (429)
 [PASS] At most 5 verification attempts evaluated and remaining throttled with 429

--- 10. Verification of Existing Login & Forgot-Password Rate Limits ---
 [PASS] Login failure 1/5 returns 401
 [PASS] Login failure 2/5 returns 401
 [PASS] Login failure 3/5 returns 401
 [PASS] Login failure 4/5 returns 401
 [PASS] Login failure 5/5 returns 401
 [PASS] Login attempt 6 returns HTTP 429 (existing login rate limiter preserved)

--------------------------------------------------------------------
 Test Summary: 36 Passed, 0 Failed
--------------------------------------------------------------------
```

---

## 5. Full Backend Regression Test Suite Execution

All 9 automated test suites were executed sequentially with a **100% pass rate**:

1. `tests/test_foundation.php`: **31 / 31 Passed**
2. `tests/test_auth.php`: **47 / 47 Passed**
3. `tests/test_roles_rbac.php`: **33 / 33 Passed**
4. `tests/test_application_modules.php`: **57 / 57 Passed**
5. `tests/test_phase_5_2_corrections.php`: **42 / 42 Passed**
6. `tests/test_phase_5_4_meeting_link_security.php`: **19 / 19 Passed**
7. `tests/test_sec01_directory_protection.php`: **32 / 32 Passed**
8. `tests/test_sec02_client_ip_security.php`: **38 / 38 Passed**
9. `tests/test_sec03_rate_limiting.php`: **36 / 36 Passed**

**Grand Total Automated Assertions:** **335 / 335 Passed (100% Pass Rate)**

Database state was verified: all 20 tables in `sma_move` remain at 0 lingering rows, and all 10 migrations remain cleanly applied.
