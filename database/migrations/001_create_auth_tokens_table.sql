-- ==============================================================================
-- Migration: 001_create_auth_tokens_table.sql
-- Description: Creates auth_tokens and password_resets tables for secure sessions.
-- Integrity: Enforces foreign key constraint to users(id) with ON DELETE CASCADE.
-- ==============================================================================

CREATE TABLE IF NOT EXISTS `auth_tokens` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `token_hash` VARCHAR(64) NOT NULL UNIQUE,
    `token_type` ENUM('access', 'refresh') NOT NULL DEFAULT 'access',
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `expires_at` DATETIME NOT NULL,
    `revoked_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_token_hash` (`token_hash`),
    INDEX `idx_token_user_type` (`user_id`, `token_type`),
    CONSTRAINT `fk_auth_tokens_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_resets` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `reset_token_hash` VARCHAR(64) NOT NULL UNIQUE,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_reset_token` (`reset_token_hash`),
    CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
