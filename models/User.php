<?php
declare(strict_types=1);

/**
 * SMA-Move — User Data Model
 */

namespace SmaMove\Models;

require_once dirname(__DIR__) . '/config/database.php';

use SmaMove\Config\Database;
use PDO;

class User
{
    /**
     * Create a new user record.
     */
    public static function create(array $data): int
    {
        $pdo = Database::getConnection();

        $sql = "INSERT INTO users (
            full_name,
            email,
            password_hash,
            role,
            account_status,
            email_verified_at
        ) VALUES (
            :full_name,
            :email,
            :password_hash,
            :role,
            :account_status,
            :email_verified_at
        )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':full_name' => $data['full_name'],
            ':email' => $data['email'],
            ':password_hash' => $data['password_hash'],
            ':role' => $data['role'],
            ':account_status' => $data['account_status'] ?? 'pending',
            ':email_verified_at' => $data['email_verified_at'] ?? null,
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Find a user by normalized email address.
     */
    public static function findByEmail(string $email): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => strtolower(trim($email))]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    }

    /**
     * Find a user by primary ID.
     */
    public static function findById(int $id): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT id, full_name, email, role, account_status, email_verified_at, created_at, updated_at FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    }

    /**
     * Update user password hash.
     */
    public static function updatePassword(int $userId, string $passwordHash): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
        return $stmt->execute([
            ':hash' => $passwordHash,
            ':id' => $userId
        ]);
    }

    /**
     * Update account status and email verification timestamp.
     */
    public static function updateAccountStatus(int $userId, string $status, ?string $verifiedAt = null): bool
    {
        $pdo = Database::getConnection();
        $sql = "UPDATE users SET account_status = :status, email_verified_at = COALESCE(:verified_at, email_verified_at) WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            ':status' => $status,
            ':verified_at' => $verifiedAt,
            ':id' => $userId
        ]);
    }
}
