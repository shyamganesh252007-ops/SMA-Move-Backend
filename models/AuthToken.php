<?php
declare(strict_types=1);

/**
 * SMA-Move — Authentication Token Model
 */

namespace SmaMove\Models;

require_once dirname(__DIR__) . '/config/database.php';

use SmaMove\Config\Database;
use PDO;

class AuthToken
{
    /**
     * Store a newly issued token hash.
     */
    public static function createToken(
        int $userId,
        string $tokenHash,
        string $type,
        string $expiresAt,
        ?string $userAgent = null,
        ?string $ipAddress = null
    ): int {
        $pdo = Database::getConnection();

        $sql = "INSERT INTO auth_tokens (
            user_id,
            token_hash,
            token_type,
            user_agent,
            ip_address,
            expires_at
        ) VALUES (
            :user_id,
            :token_hash,
            :token_type,
            :user_agent,
            :ip_address,
            :expires_at
        )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':token_hash' => $tokenHash,
            ':token_type' => $type,
            ':user_agent' => $userAgent ? substr($userAgent, 0, 255) : null,
            ':ip_address' => $ipAddress ? substr($ipAddress, 0, 45) : null,
            ':expires_at' => $expiresAt,
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Look up an unrevoked, non-expired token by its SHA-256 hash.
     */
    public static function findValidToken(string $tokenHash, string $type = 'access'): ?array
    {
        $pdo = Database::getConnection();

        $sql = "SELECT t.*, u.full_name, u.email, u.role, u.account_status, u.email_verified_at
                FROM auth_tokens t
                JOIN users u ON t.user_id = u.id
                WHERE t.token_hash = :hash
                  AND t.token_type = :type
                  AND t.revoked_at IS NULL
                  AND t.expires_at > NOW()
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':hash' => $tokenHash,
            ':type' => $type
        ]);

        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        return $record ?: null;
    }

    /**
     * Revoke a single active token.
     */
    public static function revokeToken(string $tokenHash): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("UPDATE auth_tokens SET revoked_at = NOW() WHERE token_hash = :hash AND revoked_at IS NULL");
        return $stmt->execute([':hash' => $tokenHash]);
    }

    /**
     * Revoke all active sessions for a specific user (e.g. on password reset or account lock).
     */
    public static function revokeAllUserTokens(int $userId): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("UPDATE auth_tokens SET revoked_at = NOW() WHERE user_id = :user_id AND revoked_at IS NULL");
        return $stmt->execute([':user_id' => $userId]);
    }
}
