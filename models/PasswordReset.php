<?php
declare(strict_types=1);

/**
 * SMA-Move — Password Reset Token Model
 */

namespace SmaMove\Models;

require_once dirname(__DIR__) . '/config/database.php';

use SmaMove\Config\Database;
use PDO;

class PasswordReset
{
    /**
     * Store a new password reset token hash.
     */
    public static function createReset(int $userId, string $tokenHash, string $expiresAt): int
    {
        $pdo = Database::getConnection();

        // Invalidate any existing unused reset tokens for this user
        $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE user_id = :user_id AND used_at IS NULL")
            ->execute([':user_id' => $userId]);

        $sql = "INSERT INTO password_resets (user_id, reset_token_hash, expires_at) VALUES (:user_id, :hash, :expires_at)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':hash' => $tokenHash,
            ':expires_at' => $expiresAt,
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Find a valid, unexpired, unused reset token.
     */
    public static function findValidReset(string $tokenHash): ?array
    {
        $pdo = Database::getConnection();

        $sql = "SELECT r.*, u.email, u.full_name, u.account_status
                FROM password_resets r
                JOIN users u ON r.user_id = u.id
                WHERE r.reset_token_hash = :hash
                  AND r.used_at IS NULL
                  AND r.expires_at > NOW()
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':hash' => $tokenHash]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        return $record ?: null;
    }

    /**
     * Mark a reset token as used.
     */
    public static function markUsed(string $tokenHash): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE reset_token_hash = :hash AND used_at IS NULL");
        return $stmt->execute([':hash' => $tokenHash]);
    }
}
