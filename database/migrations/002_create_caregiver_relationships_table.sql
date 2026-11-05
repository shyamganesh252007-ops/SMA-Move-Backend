-- ==============================================================================
-- Migration: 002_create_caregiver_relationships_table.sql
-- Description: Creates caregiver_profiles and caregiver_relationships tables.
-- Integrity: Ensures non-destructive schema growth and explicit patient approval workflow.
-- ==============================================================================

CREATE TABLE IF NOT EXISTS `caregiver_profiles` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` BIGINT UNSIGNED NOT NULL UNIQUE,
    `contact_number` VARCHAR(20) DEFAULT NULL,
    `relationship_description` VARCHAR(100) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_caregiver_profile_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `caregiver_relationships` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `patient_user_id` BIGINT UNSIGNED NOT NULL,
    `caregiver_user_id` BIGINT UNSIGNED NOT NULL,
    `requested_by_user_id` BIGINT UNSIGNED NOT NULL,
    `relationship_type` VARCHAR(100) NOT NULL,
    `access_status` ENUM('pending', 'approved', 'rejected', 'revoked') NOT NULL DEFAULT 'pending',
    `can_view_care_plan` TINYINT(1) NOT NULL DEFAULT 1,
    `can_receive_summaries` TINYINT(1) NOT NULL DEFAULT 1,
    `can_edit_assistance` TINYINT(1) NOT NULL DEFAULT 0,
    `invitation_code` VARCHAR(32) DEFAULT NULL UNIQUE,
    `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `approved_at` DATETIME DEFAULT NULL,
    `revoked_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_patient_caregiver` (`patient_user_id`, `caregiver_user_id`),
    INDEX `idx_relationship_status` (`access_status`),
    CONSTRAINT `fk_cr_patient_user` FOREIGN KEY (`patient_user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cr_caregiver_user` FOREIGN KEY (`caregiver_user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cr_requested_by_user` FOREIGN KEY (`requested_by_user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
