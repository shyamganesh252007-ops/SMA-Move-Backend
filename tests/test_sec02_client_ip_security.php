<?php
declare(strict_types=1);

/**
 * SMA-Move — Phase 7.2 Security Test Suite: SEC-02 Client IP & Trusted Proxy Security
 *
 * Verifies that RateLimiter::getClientIp() is secure by default:
 * - Defaults strictly to REMOTE_ADDR
 * - Ignores X-Forwarded-For / CF-Connecting-IP unless REMOTE_ADDR matches a configured trusted proxy
 * - Never blindly trusts the first forwarded address (walks right-to-left)
 * - Safely handles invalid, empty, or multiple forwarded addresses
 * - Supports IPv4 and IPv6 addresses and CIDR notations
 * - Verifies real rate limiting behavior under direct and proxied scenarios
 */

namespace SmaMove\Tests;

require_once dirname(__DIR__) . '/config/environment.php';
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/utils/rate_limiter.php';

use SmaMove\Utils\RateLimiter;

class Sec02ClientIpSecurityTest
{
    private int $passed = 0;
    private int $failed = 0;

    public function runAll(): void
    {
        echo "\n====================================================================\n";
        echo " SMA-MOVE — PHASE 7.2 SEC-02 CLIENT IP & TRUSTED PROXY SECURITY TEST\n";
        echo "====================================================================\n\n";

        $this->testDirectRequestWithoutForwardedFor();
        $this->testDirectRequestWithForgedForwardedFor();
        $this->testTrustedProxyWithValidForwardedIp();
        $this->testUntrustedProxyWithForgedForwardedIp();
        $this->testInvalidForwardedIp();
        $this->testEmptyForwardedIp();
        $this->testMultipleForwardedAddresses();
        $this->testIpv4AndIpv6();
        $this->testMissingTrustedProxyConfiguration();
        $this->testCloudflareHeaderSecurity();
        $this->testCidrRangeMatching();
        $this->testMalformedRemoteAddrFallback();
        $this->testRealRateLimitIntegration();

        echo "\n--------------------------------------------------------------------\n";
        echo " Test Summary: {$this->passed} Passed, {$this->failed} Failed\n";
        echo "--------------------------------------------------------------------\n\n";

        if ($this->failed > 0) {
            exit(1);
        }
    }

    private function assert(string $testName, bool $condition, string $detail = ''): void
    {
        if ($condition) {
            $this->passed++;
            echo " [PASS] {$testName}\n";
        } else {
            $this->failed++;
            echo " [FAIL] {$testName}" . ($detail ? " — {$detail}" : "") . "\n";
        }
    }

    /**
     * 1. Direct request without X-Forwarded-For.
     */
    private function testDirectRequestWithoutForwardedFor(): void
    {
        echo "--- 1. Direct Request Without X-Forwarded-For ---\n";
        $server = ['REMOTE_ADDR' => '198.51.100.25'];
        $ip = RateLimiter::getClientIp($server, []);
        $this->assert("Direct request returns REMOTE_ADDR directly", $ip === '198.51.100.25', "Got {$ip}");

        $serverLocal = ['REMOTE_ADDR' => '127.0.0.1'];
        $ipLocal = RateLimiter::getClientIp($serverLocal, []);
        $this->assert("Local direct connection returns 127.0.0.1", $ipLocal === '127.0.0.1', "Got {$ipLocal}");
    }

    /**
     * 2. Direct request with forged X-Forwarded-For.
     */
    private function testDirectRequestWithForgedForwardedFor(): void
    {
        echo "\n--- 2. Direct Request With Forged X-Forwarded-For ---\n";
        $server = [
            'REMOTE_ADDR' => '198.51.100.25',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.195'
        ];
        // No trusted proxies configured
        $ip = RateLimiter::getClientIp($server, []);
        $this->assert("Forged X-Forwarded-For is ignored when proxy is untrusted", $ip === '198.51.100.25', "Got {$ip}");

        // Random attacker spoofing loopback
        $serverSpoof = [
            'REMOTE_ADDR' => '45.33.32.156',
            'HTTP_X_FORWARDED_FOR' => '127.0.0.1'
        ];
        $ipSpoof = RateLimiter::getClientIp($serverSpoof, []);
        $this->assert("Spoofed loopback in X-Forwarded-For is rejected; returns actual remote IP", $ipSpoof === '45.33.32.156', "Got {$ipSpoof}");
    }

    /**
     * 3. Trusted proxy with valid forwarded IP.
     */
    private function testTrustedProxyWithValidForwardedIp(): void
    {
        echo "\n--- 3. Trusted Proxy With Valid Forwarded IP ---\n";
        $trusted = ['10.0.0.1'];
        $server = [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.195'
        ];
        $ip = RateLimiter::getClientIp($server, $trusted);
        $this->assert("Trusted proxy returns client IP from X-Forwarded-For", $ip === '203.0.113.195', "Got {$ip}");

        // Trusted proxy with whitespace in header
        $serverWithSpaces = [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '   203.0.113.195   '
        ];
        $ipSpaces = RateLimiter::getClientIp($serverWithSpaces, $trusted);
        $this->assert("Trims whitespace from forwarded client IP", $ipSpaces === '203.0.113.195', "Got {$ipSpaces}");
    }

    /**
     * 4. Untrusted proxy with forged forwarded IP.
     */
    private function testUntrustedProxyWithForgedForwardedIp(): void
    {
        echo "\n--- 4. Untrusted Proxy With Forged Forwarded IP ---\n";
        $trusted = ['10.0.0.1']; // only 10.0.0.1 is trusted
        $server = [
            'REMOTE_ADDR' => '192.0.2.1', // untrusted
            'HTTP_X_FORWARDED_FOR' => '203.0.113.195'
        ];
        $ip = RateLimiter::getClientIp($server, $trusted);
        $this->assert("Untrusted proxy header is ignored; returns REMOTE_ADDR", $ip === '192.0.2.1', "Got {$ip}");
    }

    /**
     * 5. Invalid forwarded IP.
     */
    private function testInvalidForwardedIp(): void
    {
        echo "\n--- 5. Invalid Forwarded IP ---\n";
        $trusted = ['10.0.0.1'];
        $server = [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => 'invalid-ip-string; DROP TABLE users;'
        ];
        $ip = RateLimiter::getClientIp($server, $trusted);
        $this->assert("Invalid forwarded IP string rejected; safely falls back to REMOTE_ADDR", $ip === '10.0.0.1', "Got {$ip}");

        $serverNumeric = [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '999.999.999.999'
        ];
        $ipNumeric = RateLimiter::getClientIp($serverNumeric, $trusted);
        $this->assert("Out-of-range IP rejected; safely falls back to REMOTE_ADDR", $ipNumeric === '10.0.0.1', "Got {$ipNumeric}");
    }

    /**
     * 6. Empty forwarded IP.
     */
    private function testEmptyForwardedIp(): void
    {
        echo "\n--- 6. Empty Forwarded IP ---\n";
        $trusted = ['10.0.0.1'];
        $serverEmpty = [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => ''
        ];
        $ipEmpty = RateLimiter::getClientIp($serverEmpty, $trusted);
        $this->assert("Empty X-Forwarded-For safely returns REMOTE_ADDR", $ipEmpty === '10.0.0.1', "Got {$ipEmpty}");

        $serverCommas = [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '  , ,   '
        ];
        $ipCommas = RateLimiter::getClientIp($serverCommas, $trusted);
        $this->assert("Whitespace/comma-only X-Forwarded-For safely returns REMOTE_ADDR", $ipCommas === '10.0.0.1', "Got {$ipCommas}");
    }

    /**
     * 7. Multiple forwarded addresses (walks right-to-left, does not trust first address).
     */
    private function testMultipleForwardedAddresses(): void
    {
        echo "\n--- 7. Multiple Forwarded Addresses (Right-to-Left Traversal) ---\n";
        $trusted = ['10.0.0.1', '10.0.0.2'];

        // Scenario A: Client sent spoofed IP '1.1.1.1', proxy appended real client '203.0.113.50'
        // Header: '1.1.1.1, 203.0.113.50', REMOTE_ADDR is trusted proxy '10.0.0.1'
        $serverA = [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '1.1.1.1, 203.0.113.50'
        ];
        $ipA = RateLimiter::getClientIp($serverA, $trusted);
        $this->assert("Does not trust first address; selects rightmost valid untrusted IP (203.0.113.50)", $ipA === '203.0.113.50', "Got {$ipA}");

        // Scenario B: Chained proxies: Client '198.51.100.99' -> Proxy 1 '10.0.0.2' -> Proxy 2 '10.0.0.1' -> Server
        // Header: '198.51.100.99, 10.0.0.2', REMOTE_ADDR is '10.0.0.1'
        $serverB = [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.99, 10.0.0.2'
        ];
        $ipB = RateLimiter::getClientIp($serverB, $trusted);
        $this->assert("Chained proxy traversal skips intermediate trusted proxy and returns client (198.51.100.99)", $ipB === '198.51.100.99', "Got {$ipB}");

        // Scenario C: Malformed entry in chain
        $serverC = [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => 'malformed_entry, 198.51.100.99, 10.0.0.2'
        ];
        $ipC = RateLimiter::getClientIp($serverC, $trusted);
        $this->assert("Safely skips malformed entries and extracts valid client IP", $ipC === '198.51.100.99', "Got {$ipC}");

        // Scenario D: All entries in chain are trusted proxies
        $serverD = [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '10.0.0.2, 10.0.0.1'
        ];
        $ipD = RateLimiter::getClientIp($serverD, $trusted);
        $this->assert("When all hops are trusted proxies, safely falls back to REMOTE_ADDR", $ipD === '10.0.0.1', "Got {$ipD}");
    }

    /**
     * 8. IPv4 and IPv6 handling.
     */
    private function testIpv4AndIpv6(): void
    {
        echo "\n--- 8. IPv4 and IPv6 Support ---\n";
        // Direct IPv6
        $serverIpv6 = ['REMOTE_ADDR' => '2001:db8:85a3::8a2e:370:7334'];
        $ipIpv6 = RateLimiter::getClientIp($serverIpv6, []);
        $this->assert("Direct IPv6 connection returns IPv6 REMOTE_ADDR", $ipIpv6 === '2001:db8:85a3::8a2e:370:7334', "Got {$ipIpv6}");

        // Forwarded IPv6 through IPv4 trusted proxy
        $trusted = ['10.0.0.1'];
        $serverFwdIpv6 = [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '2001:db8:85a3::8a2e:370:7334'
        ];
        $ipFwdIpv6 = RateLimiter::getClientIp($serverFwdIpv6, $trusted);
        $this->assert("Forwarded IPv6 through IPv4 proxy returned accurately", $ipFwdIpv6 === '2001:db8:85a3::8a2e:370:7334', "Got {$ipFwdIpv6}");

        // Forwarded IPv4 through IPv6 trusted proxy
        $trustedV6 = ['2001:db8::cafe'];
        $serverFwdIpv4 = [
            'REMOTE_ADDR' => '2001:db8::cafe',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.88'
        ];
        $ipFwdIpv4 = RateLimiter::getClientIp($serverFwdIpv4, $trustedV6);
        $this->assert("Forwarded IPv4 through IPv6 proxy returned accurately", $ipFwdIpv4 === '198.51.100.88', "Got {$ipFwdIpv4}");

        // IPv6 shorthand comparison match
        $trustedV6Shorthand = ['::1'];
        $serverV6Shorthand = [
            'REMOTE_ADDR' => '0:0:0:0:0:0:0:1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.5'
        ];
        $ipV6Shorthand = RateLimiter::getClientIp($serverV6Shorthand, $trustedV6Shorthand);
        $this->assert("Normalized IPv6 binary matching recognizes expanded ::1", $ipV6Shorthand === '198.51.100.5', "Got {$ipV6Shorthand}");
    }

    /**
     * 9. Missing trusted-proxy configuration.
     */
    private function testMissingTrustedProxyConfiguration(): void
    {
        echo "\n--- 9. Missing Trusted Proxy Configuration ---\n";
        $server = [
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.195'
        ];
        // Empty array
        $ipEmpty = RateLimiter::getClientIp($server, []);
        $this->assert("Missing trusted proxy config fails closed to REMOTE_ADDR", $ipEmpty === '10.0.0.1', "Got {$ipEmpty}");

        // Null trusted proxies reads env('TRUSTED_PROXIES')
        $prevEnv = $_ENV['TRUSTED_PROXIES'] ?? null;
        $_ENV['TRUSTED_PROXIES'] = '';
        putenv('TRUSTED_PROXIES=');
        $ipDefaultEnv = RateLimiter::getClientIp($server, null);
        $this->assert("Empty TRUSTED_PROXIES in environment fails closed to REMOTE_ADDR", $ipDefaultEnv === '10.0.0.1', "Got {$ipDefaultEnv}");

        // Restore
        if ($prevEnv !== null) {
            $_ENV['TRUSTED_PROXIES'] = $prevEnv;
            putenv("TRUSTED_PROXIES={$prevEnv}");
        } else {
            unset($_ENV['TRUSTED_PROXIES']);
            putenv('TRUSTED_PROXIES');
        }
    }

    /**
     * 10. Cloudflare header security.
     */
    private function testCloudflareHeaderSecurity(): void
    {
        echo "\n--- 10. Cloudflare Header Security (CF-Connecting-IP) ---\n";
        // Untrusted connection sending CF-Connecting-IP
        $serverUntrusted = [
            'REMOTE_ADDR' => '198.51.100.25',
            'HTTP_CF_CONNECTING_IP' => '1.2.3.4'
        ];
        $ipUntrusted = RateLimiter::getClientIp($serverUntrusted, []);
        $this->assert("Forged CF-Connecting-IP from untrusted direct connection is strictly ignored", $ipUntrusted === '198.51.100.25', "Got {$ipUntrusted}");

        // Trusted Cloudflare proxy sending CF-Connecting-IP
        $trustedCf = ['172.64.0.1'];
        $serverTrusted = [
            'REMOTE_ADDR' => '172.64.0.1',
            'HTTP_CF_CONNECTING_IP' => '198.51.100.77'
        ];
        $ipTrusted = RateLimiter::getClientIp($serverTrusted, $trustedCf);
        $this->assert("CF-Connecting-IP accepted when coming from verified trusted Cloudflare proxy", $ipTrusted === '198.51.100.77', "Got {$ipTrusted}");
    }

    /**
     * 11. CIDR Range Matching.
     */
    private function testCidrRangeMatching(): void
    {
        echo "\n--- 11. CIDR Subnet Range Matching ---\n";
        $trustedCidr = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '2001:db8::/32'];

        // 10.x.x.x matches 10.0.0.0/8
        $server10 = [
            'REMOTE_ADDR' => '10.5.25.100',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.88'
        ];
        $ip10 = RateLimiter::getClientIp($server10, $trustedCidr);
        $this->assert("10.5.25.100 correctly recognized as trusted proxy in 10.0.0.0/8", $ip10 === '203.0.113.88', "Got {$ip10}");

        // 11.x.x.x does NOT match 10.0.0.0/8
        $server11 = [
            'REMOTE_ADDR' => '11.5.25.100',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.88'
        ];
        $ip11 = RateLimiter::getClientIp($server11, $trustedCidr);
        $this->assert("11.5.25.100 correctly rejected as outside 10.0.0.0/8", $ip11 === '11.5.25.100', "Got {$ip11}");

        // IPv6 CIDR match
        $serverV6Cidr = [
            'REMOTE_ADDR' => '2001:db8:cafe:1234::1',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.42'
        ];
        $ipV6Cidr = RateLimiter::getClientIp($serverV6Cidr, $trustedCidr);
        $this->assert("2001:db8:cafe:1234::1 recognized in 2001:db8::/32", $ipV6Cidr === '198.51.100.42', "Got {$ipV6Cidr}");
    }

    /**
     * 12. Malformed REMOTE_ADDR fallback.
     */
    private function testMalformedRemoteAddrFallback(): void
    {
        echo "\n--- 12. Malformed REMOTE_ADDR Fallback ---\n";
        $serverMalformed = ['REMOTE_ADDR' => 'totally-invalid-ip'];
        $ipMalformed = RateLimiter::getClientIp($serverMalformed, []);
        $this->assert("Malformed REMOTE_ADDR falls back safely to 127.0.0.1", $ipMalformed === '127.0.0.1', "Got {$ipMalformed}");

        $serverEmpty = [];
        $ipEmpty = RateLimiter::getClientIp($serverEmpty, []);
        $this->assert("Missing REMOTE_ADDR falls back safely to 127.0.0.1", $ipEmpty === '127.0.0.1', "Got {$ipEmpty}");
    }

    /**
     * 13. Real rate limit integration with hit(), isExceeded(), and clear().
     */
    private function testRealRateLimitIntegration(): void
    {
        echo "\n--- 13. Real RateLimiter State Machine Integration ---\n";
        $testKey = 'sec02_test_ip_' . bin2hex(random_bytes(4));
        $action = 'login_test';

        // Initial state: not exceeded
        RateLimiter::clear($testKey, $action);
        $this->assert("Initial state is not exceeded", !RateLimiter::isExceeded($testKey, $action, 3, 60));

        // 1st hit
        $h1 = RateLimiter::hit($testKey, $action, 60);
        $this->assert("Hit 1 returns attempts = 1", $h1 === 1);
        $this->assert("Not exceeded after 1 attempt", !RateLimiter::isExceeded($testKey, $action, 3, 60));

        // 2nd hit
        $h2 = RateLimiter::hit($testKey, $action, 60);
        $this->assert("Hit 2 returns attempts = 2", $h2 === 2);
        $this->assert("Not exceeded after 2 attempts", !RateLimiter::isExceeded($testKey, $action, 3, 60));

        // 3rd hit (threshold reached)
        $h3 = RateLimiter::hit($testKey, $action, 60);
        $this->assert("Hit 3 returns attempts = 3", $h3 === 3);
        $this->assert("Exceeded after 3 attempts (threshold = 3)", RateLimiter::isExceeded($testKey, $action, 3, 60));

        // 4th hit (beyond threshold)
        $h4 = RateLimiter::hit($testKey, $action, 60);
        $this->assert("Hit 4 increments to 4", $h4 === 4);
        $this->assert("Still exceeded on attempt 4", RateLimiter::isExceeded($testKey, $action, 3, 60));

        // Clear resets counter
        RateLimiter::clear($testKey, $action);
        $this->assert("Clear successfully resets rate limit counter", !RateLimiter::isExceeded($testKey, $action, 3, 60));
    }
}

// Run the test suite
$test = new Sec02ClientIpSecurityTest();
$test->runAll();
