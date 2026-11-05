-- ==============================================================================
-- Migration: 007_create_auth_support_tables.sql
-- Description: Creates email_verifications and rate_limits tables.
-- Integrity: Enforces foreign key constraints and prevents race conditions.
-- ==============================================================================

CREATE TABLE IF NOT EXISTS `email_verifications` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `verification_code` VARCHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `verified_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_verification_user` (`user_id`),
    INDEX `idx_verification_code` (`verification_code`),
    CONSTRAINT `fk_verifications_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `rate_key` VARCHAR(191) NOT NULL,
    `action` VARCHAR(50) NOT NULL,
    `attempts` INT UNSIGNED NOT NULL DEFAULT 1,
    `window_start` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_rate_key_action` (`rate_key`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
