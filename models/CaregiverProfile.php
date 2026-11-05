<?php
declare(strict_types=1);

/**
 * SMA-Move — Caregiver Profile Model
 */

namespace SmaMove\Models;

require_once dirname(__DIR__) . '/config/database.php';

use SmaMove\Config\Database;
use PDO;

class CaregiverProfile
{
    public static function create(int $userId, array $data = []): int
    {
        $pdo = Database::getConnection();
        $sql = "INSERT INTO caregiver_profiles (
            user_id,
            contact_number,
            relationship_description
        ) VALUES (
            :user_id,
            :contact_number,
            :relationship_description
        )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':contact_number' => $data['contact_number'] ?? null,
            ':relationship_description' => $data['relationship_description'] ?? 'Primary Family Caregiver',
        ]);

        return (int)$pdo->lastInsertId();
    }

    public static function findByUserId(int $userId): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT id, user_id, contact_number, relationship_description, created_at, updated_at FROM caregiver_profiles WHERE user_id = :user_id LIMIT 1");
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Update caregiver profile details.
     */
    public static function updateProfile(int $userId, array $data): bool
    {
        $pdo = Database::getConnection();

        $sql = "UPDATE caregiver_profiles SET
                    contact_number = COALESCE(:contact, contact_number),
                    relationship_description = COALESCE(:desc, relationship_description)
                WHERE user_id = :uid";

        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            ':contact' => $data['contact_number'] ?? null,
            ':desc' => $data['relationship_description'] ?? null,
            ':uid' => $userId
        ]);
    }

    /**
     * Get caregiver profile joined with user info.
     */
    public static function getProfileWithUser(int $userId): ?array
    {
        $pdo = Database::getConnection();
        $sql = "SELECT u.id AS user_id, u.full_name, u.email, u.role, u.account_status,
                       cp.id AS profile_id, cp.contact_number, cp.relationship_description,
                       cp.created_at, cp.updated_at
                FROM users u
                LEFT JOIN caregiver_profiles cp ON u.id = cp.user_id
                WHERE u.id = :uid AND u.role = 'caregiver'
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
