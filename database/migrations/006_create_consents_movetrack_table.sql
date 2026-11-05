-- ==============================================================================
-- Migration: 006_create_consents_movetrack_table.sql
-- Description: Creates consents and movetrack_sessions tables.
-- Privacy Principle: Minimal metadata only - NO raw camera footage or frames persisted.
-- ==============================================================================

CREATE TABLE IF NOT EXISTS `consents` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `patient_user_id` BIGINT UNSIGNED NOT NULL,
    `consent_type` ENUM('camera_tracking', 'caregiver_sharing', 'clinical_sharing') NOT NULL,
    `consent_version` VARCHAR(20) NOT NULL DEFAULT '1.0.0',
    `is_granted` TINYINT(1) NOT NULL DEFAULT 0,
    `granted_at` DATETIME DEFAULT NULL,
    `revoked_at` DATETIME DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_user_consent_type` (`patient_user_id`, `consent_type`),
    CONSTRAINT `fk_consents_patient` FOREIGN KEY (`patient_user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `movetrack_sessions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `session_uuid` VARCHAR(36) NOT NULL UNIQUE,
    `patient_user_id` BIGINT UNSIGNED NOT NULL,
    `session_type` VARCHAR(100) NOT NULL DEFAULT 'Posture Guidance Evaluation',
    `start_time` DATETIME NOT NULL,
    `end_time` DATETIME NOT NULL,
    `duration_seconds` INT UNSIGNED NOT NULL,
    `model_version` VARCHAR(50) NOT NULL DEFAULT 'on-device-v1.0',
    `tracking_quality_summary` VARCHAR(100) NOT NULL DEFAULT 'Satisfactory Alignment',
    `user_notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_patient_sessions` (`patient_user_id`, `start_time`),
    CONSTRAINT `fk_movetrack_patient` FOREIGN KEY (`patient_user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
