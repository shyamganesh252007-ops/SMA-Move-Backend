<?php
declare(strict_types=1);

/**
 * SMA-Move — Patient Profile Model
 */

namespace SmaMove\Models;

require_once dirname(__DIR__) . '/config/database.php';

use SmaMove\Config\Database;
use PDO;

class PatientProfile
{
    public static function create(int $userId, array $data = []): int
    {
        $pdo = Database::getConnection();
        $sql = "INSERT INTO patient_profiles (
            user_id,
            date_of_birth,
            preferred_language,
            emergency_contact_name,
            emergency_contact_phone,
            profile_completed
        ) VALUES (
            :user_id,
            :date_of_birth,
            :preferred_language,
            :emergency_contact_name,
            :emergency_contact_phone,
            :profile_completed
        )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $userId,
            ':date_of_birth' => $data['date_of_birth'] ?? null,
            ':preferred_language' => $data['preferred_language'] ?? 'English',
            ':emergency_contact_name' => $data['emergency_contact_name'] ?? null,
            ':emergency_contact_phone' => $data['emergency_contact_phone'] ?? null,
            ':profile_completed' => (int)($data['profile_completed'] ?? 0),
        ]);

        return (int)$pdo->lastInsertId();
    }

    public static function findByUserId(int $userId): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT id, user_id, date_of_birth, preferred_language, emergency_contact_name, emergency_contact_phone, profile_completed, created_at, updated_at FROM patient_profiles WHERE user_id = :user_id LIMIT 1");
        $stmt->execute([':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Update patient profile information.
     */
    public static function updateProfile(int $userId, array $data): bool
    {
        $pdo = Database::getConnection();

        $sql = "UPDATE patient_profiles SET
                    date_of_birth = COALESCE(:dob, date_of_birth),
                    preferred_language = COALESCE(:lang, preferred_language),
                    emergency_contact_name = COALESCE(:ec_name, emergency_contact_name),
                    emergency_contact_phone = COALESCE(:ec_phone, emergency_contact_phone),
                    profile_completed = COALESCE(:completed, profile_completed)
                WHERE user_id = :uid";

        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            ':dob' => $data['date_of_birth'] ?? null,
            ':lang' => $data['preferred_language'] ?? null,
            ':ec_name' => $data['emergency_contact_name'] ?? null,
            ':ec_phone' => $data['emergency_contact_phone'] ?? null,
            ':completed' => isset($data['profile_completed']) ? (int)$data['profile_completed'] : null,
            ':uid' => $userId
        ]);
    }

    /**
     * Get patient profile joined with basic user details.
     */
    public static function getProfileWithUser(int $userId): ?array
    {
        $pdo = Database::getConnection();
        $sql = "SELECT u.id AS user_id, u.full_name, u.email, u.role, u.account_status,
                       pp.id AS profile_id, pp.date_of_birth, pp.preferred_language,
                       pp.emergency_contact_name, pp.emergency_contact_phone, pp.profile_completed,
                       pp.created_at, pp.updated_at
                FROM users u
                LEFT JOIN patient_profiles pp ON u.id = pp.user_id
                WHERE u.id = :uid AND u.role = 'patient'
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
