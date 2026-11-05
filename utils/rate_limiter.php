<?php
declare(strict_types=1);

/**
 * SMA-Move — Database-Backed Rate Limiting Utility
 *
 * Protects authentication endpoints (login, forgot_password) from brute-force
 * and credential stuffing attacks across distributed/multi-process PHP executions.
 */

namespace SmaMove\Utils;

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/environment.php';

use SmaMove\Config\Database;
use PDO;

class RateLimiter
{
    /**
     * Get the client IP address safely.
     *
     * In direct (non-proxied) connections, always returns REMOTE_ADDR.
     * Forwarded headers (X-Forwarded-For, CF-Connecting-IP) are strictly IGNORED
     * unless REMOTE_ADDR matches an explicitly configured trusted proxy.
     *
     * When behind a trusted proxy, walks the forwarded chain from right to left,
     * traversing intermediate trusted proxy hops, and selecting the rightmost
     * untrusted, valid client IP.
     *
     * @param array|null $server Optional server environment override (defaults to $_SERVER)
     * @param array|null $trustedProxies Optional trusted proxy list override (defaults to env('TRUSTED_PROXIES'))
     * @return string Validated client IP address
     */
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

        // If forwarded headers were missing, empty, malformed, or contained only trusted proxies,
        // fallback safely to REMOTE_ADDR
        return $remoteAddr;
    }

    /**
     * Retrieve the list of configured trusted proxy IPs or CIDR blocks from environment.
     *
     * @return array<string>
     */
    public static function getConfiguredTrustedProxies(): array
    {
        $raw = (string)env('TRUSTED_PROXIES', '');
        if (trim($raw) === '') {
            return [];
        }

        $items = explode(',', $raw);
        $proxies = [];
        foreach ($items as $item) {
            $trimmed = trim($item);
            if ($trimmed !== '') {
                $proxies[] = $trimmed;
            }
        }

        return $proxies;
    }

    /**
     * Check if a given IP matches any trusted proxy definition (supports exact IPv4/IPv6 and CIDR).
     *
     * @param string $ip The IP to test
     * @param array<string> $trustedList List of trusted IPs or CIDR notations
     * @return bool
     */
    public static function isTrustedProxy(string $ip, array $trustedList): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        foreach ($trustedList as $trusted) {
            $trusted = trim($trusted);
            if ($trusted === '') {
                continue;
            }

            // Exact binary match (handles IPv4 and standardized/shortened IPv6)
            if ($ip === $trusted) {
                return true;
            }
            $ipBin = @inet_pton($ip);
            $trustedBin = @inet_pton($trusted);
            if ($ipBin !== false && $trustedBin !== false && $ipBin === $trustedBin) {
                return true;
            }

            // CIDR notation matching
            if (str_contains($trusted, '/')) {
                [$subnet, $mask] = explode('/', $trusted, 2);
                if (!is_numeric($mask)) {
                    continue;
                }
                $maskInt = (int)$mask;

                // IPv4 CIDR
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) &&
                    filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                ) {
                    if ($maskInt >= 0 && $maskInt <= 32) {
                        if ($maskInt === 0) {
                            return true;
                        }
                        $ipLong = ip2long($ip);
                        $subnetLong = ip2long($subnet);
                        $maskLong = (-1 << (32 - $maskInt)) & 0xFFFFFFFF;
                        if (($ipLong & $maskLong) === ($subnetLong & $maskLong)) {
                            return true;
                        }
                    }
                }

                // IPv6 CIDR
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) &&
                    filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
                ) {
                    if ($maskInt >= 0 && $maskInt <= 128) {
                        if ($maskInt === 0) {
                            return true;
                        }
                        $subnetBin = @inet_pton($subnet);
                        if ($ipBin !== false && $subnetBin !== false) {
                            $bytes = (int)($maskInt / 8);
                            $bits = $maskInt % 8;
                            if (substr($ipBin, 0, $bytes) === substr($subnetBin, 0, $bytes)) {
                                if ($bits === 0) {
                                    return true;
                                }
                                $ipByte = ord($ipBin[$bytes]);
                                $subnetByte = ord($subnetBin[$bytes]);
                                $maskByte = (0xFF << (8 - $bits)) & 0xFF;
                                if (($ipByte & $maskByte) === ($subnetByte & $maskByte)) {
                                    return true;
                                }
                            }
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if the rate limit has been exceeded.
     *
     * @param string $rateKey Identifying key (e.g. IP address or email+IP)
     * @param string $action Action name (e.g. 'login', 'forgot_password')
     * @param int $maxAttempts Maximum allowed attempts in window
     * @param int $windowSeconds Window duration in seconds
     * @return bool True if rate limit is exceeded, false otherwise
     */
    public static function isExceeded(
        string $rateKey,
        string $action = 'login',
        int $maxAttempts = 5,
        int $windowSeconds = 900
    ): bool {
        if (!(bool)env('RATE_LIMIT_ENABLED', true)) {
            return false;
        }

        $pdo = Database::getConnection();
        $now = time();

        $stmt = $pdo->prepare("SELECT attempts, window_start FROM rate_limits WHERE rate_key = :key AND action = :action");
        $stmt->execute([':key' => $rateKey, ':action' => $action]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            return false;
        }

        $attempts = (int)$record['attempts'];
        $windowStart = (int)$record['window_start'];

        // If window has elapsed, reset window
        if (($now - $windowStart) > $windowSeconds) {
            self::clear($rateKey, $action);
            return false;
        }

        return $attempts >= $maxAttempts;
    }

    /**
     * Increment the attempt counter for a given key and action.
     *
     * @return int Current total attempts in active window
     */
    public static function hit(
        string $rateKey,
        string $action = 'login',
        int $windowSeconds = 900
    ): int {
        if (!(bool)env('RATE_LIMIT_ENABLED', true)) {
            return 1;
        }

        $pdo = Database::getConnection();
        $now = time();

        // 1. Attempt initial insert (succeeds on first attempt in window)
        try {
            $insert = $pdo->prepare("INSERT INTO rate_limits (rate_key, action, attempts, window_start) VALUES (?, ?, 1, ?)");
            $insert->execute([$rateKey, $action, $now]);
            return 1;
        } catch (\PDOException $e) {
            // Row already exists or concurrent insert in flight: fall through to atomic update
        }

        // 2. Atomic update on existing row without gap locks
        $update = $pdo->prepare("
            UPDATE rate_limits
            SET attempts = IF((? - window_start) > ?, 1, attempts + 1),
                window_start = IF((? - window_start) > ?, ?, window_start)
            WHERE rate_key = ? AND action = ?
        ");
        $update->execute([
            $now,
            $windowSeconds,
            $now,
            $windowSeconds,
            $now,
            $rateKey,
            $action
        ]);

        // 3. Return current attempts
        $query = $pdo->prepare("SELECT attempts FROM rate_limits WHERE rate_key = ? AND action = ?");
        $query->execute([$rateKey, $action]);
        return (int)$query->fetchColumn();
    }

    /**
     * Clear / reset the rate limit counter on successful action.
     */
    public static function clear(string $rateKey, string $action = 'login'): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("DELETE FROM rate_limits WHERE rate_key = :key AND action = :action");
        $stmt->execute([':key' => $rateKey, ':action' => $action]);
    }
}
