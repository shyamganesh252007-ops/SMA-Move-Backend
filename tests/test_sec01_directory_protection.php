<?php
declare(strict_types=1);

/**
 * SMA-Move — Phase 7.1 Security Test Suite: SEC-01 Directory Protection
 *
 * Verifies that Apache URL rewriting and access-control rules strictly block
 * direct web access to internal directories (config, database, docs, logs,
 * middleware, models, services, tests, utils) and sensitive files (.env).
 */

namespace SmaMove\Tests;

$baseUrl = 'http://localhost/sma-move-backend';

$endpoints = [
    // Internal directories & files that MUST return 403 Forbidden
    ['path' => '/docs', 'expected' => 403],
    ['path' => '/docs/', 'expected' => 403],
    ['path' => '/docs/API_DOCUMENTATION.md', 'expected' => 403],
    ['path' => '/docs/PHASE_7_BACKEND_HEALTH_AUDIT.md', 'expected' => 403],
    ['path' => '/middleware', 'expected' => 403],
    ['path' => '/middleware/', 'expected' => 403],
    ['path' => '/middleware/auth_middleware.php', 'expected' => 403],
    ['path' => '/middleware/role_middleware.php', 'expected' => 403],
    ['path' => '/config', 'expected' => 403],
    ['path' => '/config/', 'expected' => 403],
    ['path' => '/config/config.php', 'expected' => 403],
    ['path' => '/database', 'expected' => 403],
    ['path' => '/database/', 'expected' => 403],
    ['path' => '/database/migrate.php', 'expected' => 403],
    ['path' => '/logs', 'expected' => 403],
    ['path' => '/logs/', 'expected' => 403],
    ['path' => '/logs/error.log', 'expected' => 403],
    ['path' => '/models', 'expected' => 403],
    ['path' => '/models/', 'expected' => 403],
    ['path' => '/models/User.php', 'expected' => 403],
    ['path' => '/services', 'expected' => 403],
    ['path' => '/services/', 'expected' => 403],
    ['path' => '/services/AuthService.php', 'expected' => 403],
    ['path' => '/tests', 'expected' => 403],
    ['path' => '/tests/', 'expected' => 403],
    ['path' => '/tests/test_auth.php', 'expected' => 403],
    ['path' => '/utils', 'expected' => 403],
    ['path' => '/utils/', 'expected' => 403],
    ['path' => '/utils/response.php', 'expected' => 403],

    // Sensitive files blocked by FilesMatch
    ['path' => '/.env', 'expected' => 403],

    // Public API endpoints that MUST remain accessible
    ['path' => '/api/health.php', 'expected' => 200],
    ['path' => '/api/auth/me.php', 'expected' => 401]
];

echo "\n====================================================================\n";
echo " SMA-MOVE — PHASE 7.1 SEC-01 INTERNAL DIRECTORY PROTECTION TEST\n";
echo "====================================================================\n\n";

$passed = 0;
$failed = 0;

foreach ($endpoints as $item) {
    $url = $baseUrl . $item['path'];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status === $item['expected']) {
        $passed++;
        echo sprintf(" [PASS] %-45s => HTTP %d\n", $item['path'], $status);
    } else {
        $failed++;
        echo sprintf(" [FAIL] %-45s => Got HTTP %d (Expected %d)\n", $item['path'], $status, $item['expected']);
    }
}

echo "\n--------------------------------------------------------------------\n";
echo " Test Summary: {$passed} Passed, {$failed} Failed\n";
echo "--------------------------------------------------------------------\n\n";

if ($failed > 0) {
    exit(1);
}
