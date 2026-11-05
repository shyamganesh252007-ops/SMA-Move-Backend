# SMA-MOVE — PHASE 7.2: SEC-02 TRUSTED PROXY & CLIENT IP SECURITY REMEDIATION REPORT

**Document Version:** 1.0.0  
**Phase:** 7.2 — SEC-02 Trusted Proxy & Client IP Security  
**Date:** November 5, 2026  
**Auditor / Implementer:** SMA-Move Engineering Team  
**Scope:** Remediation and Verification of SEC-02 in `RateLimiter::getClientIp()`  
**Target Root:** `C:\xampp\htdocs\sma-move-backend`  
**Runtime:** PHP 8.2.12 CLI & Apache 2.4 / MariaDB 10.4.32  

---

## 1. Executive Summary

During the Phase 7.0 backend health audit, Finding **SEC-02** identified that `RateLimiter::getClientIp()` unconditionally trusted `HTTP_CF_CONNECTING_IP` and `HTTP_X_FORWARDED_FOR` headers, taking the first comma-separated address (`$ips[0]`) without verifying whether `REMOTE_ADDR` originated from a trusted reverse proxy. In non-proxied or direct LAN environments, this allowed clients to forge IP headers and potentially evade IP-based rate limiting on authentication and password reset endpoints.

In Phase 7.2, `RateLimiter::getClientIp()` was refactored to be **secure by default**:
1. In direct connections (standard XAMPP / direct HTTP), it strictly uses `$_SERVER['REMOTE_ADDR']`.
2. Client-supplied headers (`X-Forwarded-For`, `CF-Connecting-IP`) are strictly **ignored** unless `REMOTE_ADDR` matches an explicitly configured trusted reverse proxy.
3. When operating behind a trusted proxy, it traverses the `X-Forwarded-For` chain from **right to left**, safely discarding intermediate trusted proxy hops and selecting the rightmost untrusted, valid client IP.
4. Malformed, empty, or out-of-range IP strings fail safely back to `REMOTE_ADDR`.
5. Full support for IPv4, IPv6, binary equality (`inet_pton`), and CIDR subnet notation (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `2001:db8::/32`).

---

## 2. Files Modified

| File Path | Component | Summary of Changes |
| :--- | :--- | :--- |
| `utils/rate_limiter.php` | Core Utility | Refactored `getClientIp()` to default to `REMOTE_ADDR`, ignore forwarded headers from untrusted connections, traverse right-to-left, and validate via `filter_var`. Added `getConfiguredTrustedProxies()` and `isTrustedProxy()` with IPv4/IPv6 and CIDR matching. |
| `tests/test_sec02_client_ip_security.php` | Test Suite | New comprehensive automated test suite with **38 test cases** covering all required scenarios. |
| `docs/PHASE_7_SECURITY_FINDINGS.md` | Audit Documentation | Updated Finding SEC-02 from `NEEDS ATTENTION` to `RESOLVED (Phase 7.2)`. |
| `docs/PHASE_7_2_SEC02_REMEDIATION_REPORT.md` | Phase Deliverable | This comprehensive remediation and verification report. |

---

## 3. Before vs. After Implementation

### Before (Vulnerable Code in `utils/rate_limiter.php`):
```php
public static function getClientIp(): string
{
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'REMOTE_ADDR'
    ];

    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ips = explode(',', (string)$_SERVER[$header]);
            $ip = trim($ips[0]); // Blindly takes the first address!
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip; // Returns attacker-controlled spoofed IP on direct connection!
            }
        }
    }

    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}
```

### After (Hardened Implementation in `utils/rate_limiter.php`):
```php
public static function getClientIp(?array $server = null, ?array $trustedProxies = null): string
{
    $server ??= $_SERVER;
    $remoteAddr = trim((string)($server['REMOTE_ADDR'] ?? '127.0.0.1'));

    // If REMOTE_ADDR is invalid, fallback safely to loopback
    if (!filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
        return '127.0.0.1';
    }

    // Determine configured trusted proxies
    $trustedList = $trustedProxies ?? self::getConfiguredTrustedProxies();

    // If no trusted proxies are configured, or REMOTE_ADDR is not trusted,
    // strictly return REMOTE_ADDR. Never inspect client-supplied headers.
    if (empty($trustedList) || !self::isTrustedProxy($remoteAddr, $trustedList)) {
        return $remoteAddr;
    }

    // At this point, REMOTE_ADDR is a verified trusted proxy.
    // 1. Inspect X-Forwarded-For (walking backwards from right to left)
    if (!empty($server['HTTP_X_FORWARDED_FOR'])) {
        $rawHeader = (string)$server['HTTP_X_FORWARDED_FOR'];
        $parts = explode(',', $rawHeader);

        // Walk backwards from the most recent proxy hop
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $candidate = trim($parts[$i]);
            if ($candidate === '' || !filter_var($candidate, FILTER_VALIDATE_IP)) {
                continue;
            }

            // If this hop is also a trusted intermediate proxy, keep traversing left
            if (self::isTrustedProxy($candidate, $trustedList)) {
                continue;
            }

            // First non-trusted valid IP from the right is the authenticated client IP
            return $candidate;
        }
    }

    // 2. Inspect Cloudflare header if X-Forwarded-For yielded no untrusted IP
    if (!empty($server['HTTP_CF_CONNECTING_IP'])) {
        $candidate = trim((string)$server['HTTP_CF_CONNECTING_IP']);
        if (filter_var($candidate, FILTER_VALIDATE_IP) && !self::isTrustedProxy($candidate, $trustedList)) {
            return $candidate;
        }
    }

    // Fallback safely to REMOTE_ADDR
    return $remoteAddr;
}
```

---

## 4. Trusted Proxy Configuration Behaviour

### 4.1 Configuration Source
Trusted proxies are configured via the environment variable `TRUSTED_PROXIES` in `.env`:
```ini
# Comma-separated list of trusted reverse proxy IPs or CIDR subnets
TRUSTED_PROXIES=127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,::1
```

### 4.2 Behavior When Unconfigured (Default State)
* When `TRUSTED_PROXIES` is undefined, empty string `""`, or contains only whitespace:
  - `getConfiguredTrustedProxies()` returns an empty array `[]`.
  - `RateLimiter::getClientIp()` strictly and exclusively returns `$_SERVER['REMOTE_ADDR']`.
  - All incoming `X-Forwarded-For` and `CF-Connecting-IP` headers are discarded with zero evaluation.
  - Guarantees fail-safe behavior in default local development and single-server environments.

### 4.3 Subnet Matching Engine
`RateLimiter::isTrustedProxy()` performs multi-layer IP matching:
1. **Direct String Equality:** Fast-path check (`$ip === $trusted`).
2. **Binary Equality (`inet_pton`):** Standardizes IPv4 and IPv6 variations (e.g. `::1` matches `0:0:0:0:0:0:0:1`).
3. **IPv4 CIDR Evaluation:** Evaluates 32-bit subnet masks with bitwise masking (`$ipLong & $maskLong === $subnetLong & $maskLong`).
4. **IPv6 CIDR Evaluation:** Evaluates 128-bit byte- and bit-level masks against raw 16-byte `inet_pton` representations.

---

## 5. Test Results and Actual Execution Evidence

A dedicated automated test suite (`tests/test_sec02_client_ip_security.php`) was created and executed using `php.exe`. All 38 test assertions passed:

```text
====================================================================
 SMA-MOVE — PHASE 7.2 SEC-02 CLIENT IP & TRUSTED PROXY SECURITY TEST
====================================================================

--- 1. Direct Request Without X-Forwarded-For ---
 [PASS] Direct request returns REMOTE_ADDR directly
 [PASS] Local direct connection returns 127.0.0.1

--- 2. Direct Request With Forged X-Forwarded-For ---
 [PASS] Forged X-Forwarded-For is ignored when proxy is untrusted
 [PASS] Spoofed loopback in X-Forwarded-For is rejected; returns actual remote IP

--- 3. Trusted Proxy With Valid Forwarded IP ---
 [PASS] Trusted proxy returns client IP from X-Forwarded-For
 [PASS] Trims whitespace from forwarded client IP

--- 4. Untrusted Proxy With Forged Forwarded IP ---
 [PASS] Untrusted proxy header is ignored; returns REMOTE_ADDR

--- 5. Invalid Forwarded IP ---
 [PASS] Invalid forwarded IP string rejected; safely falls back to REMOTE_ADDR
 [PASS] Out-of-range IP rejected; safely falls back to REMOTE_ADDR

--- 6. Empty Forwarded IP ---
 [PASS] Empty X-Forwarded-For safely returns REMOTE_ADDR
 [PASS] Whitespace/comma-only X-Forwarded-For safely returns REMOTE_ADDR

--- 7. Multiple Forwarded Addresses (Right-to-Left Traversal) ---
 [PASS] Does not trust first address; selects rightmost valid untrusted IP (203.0.113.50)
 [PASS] Chained proxy traversal skips intermediate trusted proxy and returns client (198.51.100.99)
 [PASS] Safely skips malformed entries and extracts valid client IP
 [PASS] When all hops are trusted proxies, safely falls back to REMOTE_ADDR

--- 8. IPv4 and IPv6 Support ---
 [PASS] Direct IPv6 connection returns IPv6 REMOTE_ADDR
 [PASS] Forwarded IPv6 through IPv4 proxy returned accurately
 [PASS] Forwarded IPv4 through IPv6 proxy returned accurately
 [PASS] Normalized IPv6 binary matching recognizes expanded ::1

--- 9. Missing Trusted Proxy Configuration ---
 [PASS] Missing trusted proxy config fails closed to REMOTE_ADDR
 [PASS] Empty TRUSTED_PROXIES in environment fails closed to REMOTE_ADDR

--- 10. Cloudflare Header Security (CF-Connecting-IP) ---
 [PASS] Forged CF-Connecting-IP from untrusted direct connection is strictly ignored
 [PASS] CF-Connecting-IP accepted when coming from verified trusted Cloudflare proxy

--- 11. CIDR Subnet Range Matching ---
 [PASS] 10.5.25.100 correctly recognized as trusted proxy in 10.0.0.0/8
 [PASS] 11.5.25.100 correctly rejected as outside 10.0.0.0/8
 [PASS] 2001:db8:cafe:1234::1 recognized in 2001:db8::/32

--- 12. Malformed REMOTE_ADDR Fallback ---
 [PASS] Malformed REMOTE_ADDR falls back safely to 127.0.0.1
 [PASS] Missing REMOTE_ADDR falls back safely to 127.0.0.1

--- 13. Real RateLimiter State Machine Integration ---
 [PASS] Initial state is not exceeded
 [PASS] Hit 1 returns attempts = 1
 [PASS] Not exceeded after 1 attempt
 [PASS] Hit 2 returns attempts = 2
 [PASS] Not exceeded after 2 attempts
 [PASS] Hit 3 returns attempts = 3
 [PASS] Exceeded after 3 attempts (threshold = 3)
 [PASS] Hit 4 increments to 4
 [PASS] Still exceeded on attempt 4
 [PASS] Clear successfully resets rate limit counter

--------------------------------------------------------------------
 Test Summary: 38 Passed, 0 Failed
--------------------------------------------------------------------
```

---

## 6. Full Regression Test Results

Across all 8 automated test suites, **299 assertions** executed with a **100% pass rate**:

1. `tests/test_foundation.php`: **31 / 31 Passed (100%)**
2. `tests/test_auth.php`: **47 / 47 Passed (100%)**
3. `tests/test_roles_rbac.php`: **33 / 33 Passed (100%)**
4. `tests/test_application_modules.php`: **57 / 57 Passed (100%)**
5. `tests/test_phase_5_2_corrections.php`: **42 / 42 Passed (100%)**
6. `tests/test_phase_5_4_meeting_link_security.php`: **19 / 19 Passed (100%)**
7. `tests/test_sec01_directory_protection.php`: **32 / 32 Passed (100%)**
8. `tests/test_sec02_client_ip_security.php`: **38 / 38 Passed (100%)**

**Grand Total Automated Assertions:** **299 / 299 Passed (100%)**

Database state was verified: all 20 tables in `sma_move` remain at 0 lingering rows, and all 10 migrations remain intact.

---

## 7. Remaining Limitations & Architectural Considerations

1. **RFC 7239 `Forwarded` Header:** The implementation supports the de-facto standard `X-Forwarded-For` and Cloudflare's `CF-Connecting-IP`. The RFC 7239 structured `Forwarded: for=192.0.2.60;proto=http;by=203.0.113.43` header is not currently parsed. This is acceptable as major reverse proxies (Nginx, HAProxy, AWS ALB, Cloudflare) emit standard `X-Forwarded-For`.
2. **Reverse Proxy Configuration Dependency:** In a production cloud deployment utilizing an application load balancer (ALB) or reverse proxy, the operator must populate `TRUSTED_PROXIES` in `.env` with the proxy's private CIDR (e.g. `10.0.0.0/16`) to ensure rate limiting correctly keys off the end-user's public IP rather than the load balancer's private IP.
