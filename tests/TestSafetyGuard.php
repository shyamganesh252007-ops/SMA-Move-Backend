<?php
declare(strict_types=1);

/**
 * SMA-Move — Test Database Safety Guard
 *
 * Enforces fail-closed safety constraints for automated test execution.
 * Prevents accidental destructive cleanup against production or shared operational databases.
 */

namespace SmaMove\Tests;

use PDO;
use RuntimeException;
use InvalidArgumentException;

class TestSafetyGuard
{
    /**
     * Permitted non-production environment names.
     */
    private const ALLOWED_ENVIRONMENTS = ['development', 'dev', 'testing', 'test', 'local'];

    /**
     * Forbidden database name fragments indicating production data.
     */
    private const FORBIDDEN_DB_FRAGMENTS = ['production', 'prod', 'live'];

    /**
     * Verify that the current execution environment and database connection are safe for test operations.
     *
     * @param PDO $pdo Active PDO connection
     * @param string|null $envOverride Optional environment override for simulation/testing
     * @throws RuntimeException If environment or database indicates production
     */
    public static function assertSafeEnvironment(PDO $pdo, ?string $envOverride = null): void
    {
        $appEnv = strtolower(trim((string)($envOverride ?? env('APP_ENV', 'development'))));

        if (!in_array($appEnv, self::ALLOWED_ENVIRONMENTS, true)) {
            throw new RuntimeException(
                "SAFETY GUARD ABORT: Test execution blocked in environment '{$appEnv}'. Tests may only run in development or testing environments."
            );
        }

        $dbName = strtolower(trim((string)$pdo->query("SELECT DATABASE()")->fetchColumn()));
        foreach (self::FORBIDDEN_DB_FRAGMENTS as $fragment) {
            if (str_contains($dbName, $fragment)) {
                throw new RuntimeException(
                    "SAFETY GUARD ABORT: Connected database '{$dbName}' matches protected production fragment '{$fragment}'. Destructive actions strictly prohibited."
                );
            }
        }
    }

    /**
     * Delete only synthetic test users matching strict synthetic naming conventions.
     *
     * @param PDO $pdo Active PDO connection
     * @param string $emailPattern SQL LIKE pattern (must conform to synthetic naming)
     * @param string|null $envOverride Optional environment override
     * @return int Number of deleted rows
     * @throws RuntimeException If environment is unsafe
     * @throws InvalidArgumentException If email pattern does not conform to synthetic namespace
     */
    public static function cleanSyntheticUsers(PDO $pdo, string $emailPattern, ?string $envOverride = null): int
    {
        self::assertSafeEnvironment($pdo, $envOverride);

        // Pattern MUST contain 'synthetic' to prevent wide deletes like '%' or '@company.com'
        if (!str_contains(strtolower($emailPattern), 'synthetic')) {
            throw new InvalidArgumentException(
                "SAFETY GUARD ABORT: Email pattern '{$emailPattern}' does not contain 'synthetic'. Refusing broad user deletion."
            );
        }

        $stmt = $pdo->prepare("DELETE FROM users WHERE email LIKE :pattern");
        $stmt->execute([':pattern' => $emailPattern]);
        return $stmt->rowCount();
    }

    /**
     * Delete only explicitly scoped rate-limit test keys and actions.
     *
     * @param PDO $pdo Active PDO connection
     * @param array<string> $testKeys List of specific test rate keys
     * @param array<string> $testActions List of specific actions
     * @param string|null $envOverride Optional environment override
     * @return int Number of deleted rows
     * @throws RuntimeException If environment is unsafe
     * @throws InvalidArgumentException If keys or actions are empty
     */
    public static function cleanRateLimits(
        PDO $pdo,
        array $testKeys,
        array $testActions,
        ?string $envOverride = null
    ): int {
        self::assertSafeEnvironment($pdo, $envOverride);

        if (empty($testKeys) || empty($testActions)) {
            throw new InvalidArgumentException(
                "SAFETY GUARD ABORT: cleanRateLimits requires non-empty test keys and actions. Broad deletion is forbidden."
            );
        }

        $keyPlaceholders = implode(',', array_fill(0, count($testKeys), '?'));
        $actionPlaceholders = implode(',', array_fill(0, count($testActions), '?'));

        $sql = "DELETE FROM rate_limits WHERE rate_key IN ({$keyPlaceholders}) AND action IN ({$actionPlaceholders})";
        $stmt = $pdo->prepare($sql);
        $params = array_merge($testKeys, $testActions);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * Delete rate limits matching specific key prefixes or patterns.
     *
     * @param PDO $pdo Active PDO connection
     * @param string $keyPattern SQL LIKE pattern for rate_key
     * @param array<string> $testActions List of specific actions
     * @param string|null $envOverride Optional environment override
     * @return int Number of deleted rows
     */
    public static function cleanRateLimitsByPattern(
        PDO $pdo,
        string $keyPattern,
        array $testActions,
        ?string $envOverride = null
    ): int {
        self::assertSafeEnvironment($pdo, $envOverride);

        if ($keyPattern === '%' || $keyPattern === '' || empty($testActions)) {
            throw new InvalidArgumentException(
                "SAFETY GUARD ABORT: Unqualified pattern or empty actions supplied to cleanRateLimitsByPattern."
            );
        }

        $actionPlaceholders = implode(',', array_fill(0, count($testActions), '?'));
        $sql = "DELETE FROM rate_limits WHERE rate_key LIKE ? AND action IN ({$actionPlaceholders})";
        $stmt = $pdo->prepare($sql);
        $params = array_merge([$keyPattern], $testActions);
        $stmt->execute($params);

        return $stmt->rowCount();
    }
}
