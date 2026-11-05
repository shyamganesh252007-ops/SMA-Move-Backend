<?php
declare(strict_types=1);

/**
 * SMA-Move — Healthcare Professional Profile Model
 */

namespace SmaMove\Models;

require_once dirname(__DIR__) . '/config/database.php';

use SmaMove\Config\Database;
use PDO;

class ProfessionalProfile
{
    public static function create(int $userId, array $data = []): int
    {
        $pdo = Database::getConnection();
        $sql = "INSERT INTO professional_profiles (
            user_id,
            professional_title,
            qualification,
            registration_number,
            institution_name,
            verification_status
        ) VALUES (
            :user_id,
            :professional_title,
            :qualification,
            :registration_number,
            :institution_name,
            'not_submitted'
        )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':professional_title' => $data['professional_title'] ?? null,
            ':qualification' => $data['qualification'] ?? null,
            ':registration_number' => $data['registration_number'] ?? null,
            ':institution_name' => $data['institution_name'] ?? null,
        ]);

        return (int)$pdo->lastInsertId();
    }

    public static function findByUserId(int $userId): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT id, user_id, professional_title, qualification, registration_number, institution_name, verification_status, created_at, updated_at FROM professional_profiles WHERE user_id = :user_id LIMIT 1");
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Submit professional credentials for verification review.
     */
    public static function submitVerification(int $userId, array $data): bool
    {
        $pdo = Database::getConnection();

        $sql = "UPDATE professional_profiles SET
                    professional_title = COALESCE(:title, professional_title),
                    qualification = COALESCE(:qual, qualification),
                    registration_number = :reg_num,
                    institution_name = :inst_name,
                    verification_status = 'pending'
                WHERE user_id = :uid";

        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            ':title' => $data['professional_title'] ?? null,
            ':qual' => $data['qualification'] ?? null,
            ':reg_num' => trim($data['registration_number']),
            ':inst_name' => trim($data['institution_name']),
            ':uid' => $userId
        ]);
    }

    /**
     * Administrative verification of professional credentials with audit logging.
     */
    public static function adminVerify(
        int $professionalUserId,
        int $adminUserId,
        string $status,
        ?string $notes = null
    ): bool {
        if (!in_array($status, ['verified', 'rejected', 'pending'], true)) {
            return false;
        }

        $pdo = Database::getConnection();
        $sql = "UPDATE professional_profiles SET
                    verification_status = :status,
                    verified_by_user_id = :admin_id,
                    verification_notes = :notes,
                    verified_at = CASE WHEN :status_check = 'verified' THEN NOW() ELSE NULL END
                WHERE user_id = :uid";

        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            ':status' => $status,
            ':status_check' => $status,
            ':admin_id' => $adminUserId,
            ':notes' => $notes,
            ':uid' => $professionalUserId
        ]);
    }

    /**
     * Check if a professional is verified.
     */
    public static function isVerified(int $professionalUserId): bool
    {
        $profile = self::findByUserId($professionalUserId);
        return $profile !== null && $profile['verification_status'] === 'verified';
    }
}
