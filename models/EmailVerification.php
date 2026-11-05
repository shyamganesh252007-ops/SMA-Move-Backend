<?php
declare(strict_types=1);

/**
 * SMA-Move — Email Verification / Account Activation Model
 */

namespace SmaMove\Models;

require_once dirname(__DIR__) . '/config/database.php';

use SmaMove\Config\Database;
use PDO;

class EmailVerification
{
    /**
     * Create an activation / verification record for a user.
     */
    public static function createVerification(int $userId, string $code, string $expiresAt): int
    {
        $pdo = Database::getConnection();

        // Invalidate older unused codes for this user
        $pdo->prepare("UPDATE email_verifications SET verified_at = NOW() WHERE user_id = :user_id AND verified_at IS NULL")
            ->execute([':user_id' => $userId]);

        $sql = "INSERT INTO email_verifications (user_id, verification_code, expires_at) VALUES (:user_id, :code, :expires_at)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':code' => $code,
            ':expires_at' => $expiresAt
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Find a valid verification entry for user and code.
     */
    public static function findValidVerification(int $userId, string $code): ?array
    {
        $pdo = Database::getConnection();

        $sql = "SELECT * FROM email_verifications
                WHERE user_id = :user_id
                  AND verification_code = :code
                  AND verified_at IS NULL
                  AND expires_at > NOW()
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':code' => $code
        ]);

        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        return $record ?: null;
    }

    /**
     * Mark verification record as verified.
     */
    public static function markVerified(int $id): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("UPDATE email_verifications SET verified_at = NOW() WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }
}
