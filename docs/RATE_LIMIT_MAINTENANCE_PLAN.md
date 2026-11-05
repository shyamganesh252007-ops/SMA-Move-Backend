# SMA-MOVE — PRODUCTION RATE-LIMIT MAINTENANCE PLAN

**Document Version:** 1.0.0  
**Phase:** 7.5 — Test Database Safety & Security Documentation Corrections  
**Date:** November 5, 2026  
**Target Table:** `sma_move.rate_limits`  
**Purpose:** Long-term lifecycle, safe expiration policy, and automated maintenance strategy for operational rate-limiting records.

---

## 1. Executive Summary

The SMA-Move backend employs an atomic MariaDB sliding-window rate limiter (`utils/rate_limiter.php`) to defend against authentication brute-force, email verification guessing, and registration spam. 

While active client requests evaluate sliding windows dynamically and eviction occurs on subsequent requests, records created by one-time or abandoned requests (e.g. a single failed password or a one-time registration attempt from an ephemeral IP) remain in the `rate_limits` table as logically expired rows. 

To maintain table health, prevent unbounded disk growth, and ensure consistent index seek performance, this document specifies a safe, automated production maintenance strategy for pruning stale records without disrupting active client rate-limit windows.

> [!IMPORTANT]
> **Phase 7.5 Non-Interference Guarantee:**  
> In accordance with Phase 7.5 guidelines, no cron jobs, Windows Task Scheduler tasks, or MySQL Event Schedulers have been installed or activated during this development phase. Current rate-limit records in development remain preserved.

---

## 2. Rate-Limit Window Inventory

The backend configures the following rate-limiting sliding windows:

| Action | Endpoint / Service | Max Attempts | Window Duration (Seconds) | Window Duration (Human) | Key Construction |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `login` | `AuthService::login` | 5 | 900 s | 15 Minutes | Client IP (`REMOTE_ADDR`) |
| `forgot_pwd` | `AuthService::requestPasswordReset` | 5 | 900 s | 15 Minutes | Client IP (`REMOTE_ADDR`) |
| `verify_email` | `api/auth/verify_email.php` | 5 | 900 s | 15 Minutes | `$ip:verify_email:$emailHash` |
| `verify_email_ip` | `api/auth/verify_email.php` | 15 | 900 s | 15 Minutes | `$ip:verify_email_ip` |
| `signup` | `api/auth/signup.php` | 5 | 3,600 s | 1 Hour | Client IP (`REMOTE_ADDR`) |

**Maximum Operational Window Across Entire Backend:** **3,600 seconds (1 hour)**.

---

## 3. Safe Expiration Policy & SQL Specification

### 3.1 Strict Safety Invariant
An operational maintenance routine must **never** delete a record whose active sliding window is still in effect:
$$\text{Current Time} - \text{window\_start} \le \text{window\_duration}$$

Deleting an active record would reset the client's attempt counter, allowing an attacker to bypass rate limits.

### 3.2 Conservative Safety Buffer
To guarantee that clock skew, timezone conversions, or prolonged long-polling connections cannot result in the premature eviction of an active window, the maintenance policy defines a **24-hour (86,400 seconds) conservative retention threshold**:

- Any record where $\text{window\_start} < (\text{UNIX\_TIMESTAMP}() - 86400)$ is guaranteed to be at least **23 hours beyond its maximum possible operational window** (`signup` at 1 hour).
- Evicting records older than 24 hours poses **zero operational risk** of resetting an active counter.

### 3.3 Safe SQL Pruning Query
To prevent long table locks on high-traffic production databases, deletions should execute in chunked batches:

```sql
-- Batch-limited safe eviction of stale rate-limit records (>24 hours old)
DELETE FROM rate_limits 
WHERE window_start < (UNIX_TIMESTAMP() - 86400)
LIMIT 1000;
```

---

## 4. Production Implementation Architectures

Two viable deployment architectures are available for production environments:

### Option A: Standalone PHP CLI Maintenance Command (Recommended)
Create a standalone CLI script (e.g. `bin/prune_rate_limits.php` or `scripts/maintenance/prune_rate_limits.php`):

```php
<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/environment.php';
require_once dirname(__DIR__, 2) . '/config/database.php';

use SmaMove\Config\Database;

$pdo = Database::getConnection();
$retentionSeconds = 86400; // 24 hours
$cutoff = time() - $retentionSeconds;
$batchSize = 1000;
$totalDeleted = 0;

do {
    $stmt = $pdo->prepare("DELETE FROM rate_limits WHERE window_start < ? LIMIT ?");
    $stmt->bindValue(1, $cutoff, PDO::PARAM_INT);
    $stmt->bindValue(2, $batchSize, PDO::PARAM_INT);
    $stmt->execute();
    $deleted = $stmt->rowCount();
    $totalDeleted += $deleted;
} while ($deleted === $batchSize);

$logMessage = sprintf(
    "[%s] [RATE_LIMIT_PRUNE] Safely pruned %d expired rate limit rows (cutoff: %d).\n",
    date('Y-m-d H:i:s'),
    $totalDeleted,
    $cutoff
);

error_log($logMessage, 3, dirname(__DIR__, 2) . '/logs/maintenance.log');
```

#### Scheduled Cron Configuration (Linux Production):
```cron
# Run daily at 03:00 AM UTC
0 3 * * * /usr/bin/php /var/www/sma-move-backend/scripts/maintenance/prune_rate_limits.php >> /var/log/sma_move_cron.log 2>&1
```

### Option B: MariaDB Event Scheduler
For cloud deployments where cron access is not available (e.g. managed AWS RDS or Google Cloud SQL):

```sql
-- Enable event scheduler on MariaDB instance
SET GLOBAL event_scheduler = ON;

-- Create recurring daily cleanup event
DELIMITER $$
CREATE EVENT IF NOT EXISTS evt_prune_expired_rate_limits
ON SCHEDULE EVERY 1 DAY
STARTS (CURRENT_DATE + INTERVAL 1 DAY + INTERVAL 3 HOUR)
DO
BEGIN
    DELETE FROM sma_move.rate_limits 
    WHERE window_start < (UNIX_TIMESTAMP() - 86400);
END $$
DELIMITER ;
```

---

## 5. Monitoring, Alerting & Operational Runbook

### 5.1 Monitoring Metrics
1. **Table Row Count:** Monitor total rows in `rate_limits`. Normal steady-state for a regional clinic deployment is typically 500 to 5,000 active records.
2. **Purge Execution Latency:** Ensure prune execution completes within 250 milliseconds.
3. **Execution Success:** Track exit codes of the daily maintenance script.

### 5.2 Threshold Alerting:
- **Warning:** `COUNT(*) FROM rate_limits` $> 50,000$. Indicates that the automated cleanup job has failed to run or the backend is experiencing distributed credential stuffing.
- **Critical:** `COUNT(*) FROM rate_limits` $> 200,000$. Table requires manual intervention or index optimization.

### 5.3 Failure Logging Standards
All pruning operations must log structured output to `logs/maintenance.log`:
- Timestamp (ISO 8601 / standard UTC).
- Operation name (`RATE_LIMIT_PRUNE`).
- Rows affected.
- Peak duration in milliseconds.
- Any PDO exception messages (with stack traces suppressed).

---

## 6. Summary of Recommendations for Phase Deployment

1. **Keep Development Pristine:** Do not configure cron jobs in the local development environment.
2. **Pre-Production Checklist:** Before going live on production hosting, configure Option A as a scheduled daily cron job.
3. **Keep Scoped Test Cleanups:** Continue using `TestSafetyGuard::cleanRateLimits()` in automated integration tests to ensure test runs never touch production keys.
