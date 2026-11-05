-- ==============================================================================
-- Migration: 008_create_patient_invitations_table.sql
-- Description: Creates patient_invitations table for patient-generated caregiver invitation codes.
-- Integrity: Enforces foreign key constraint to users(id) ON DELETE CASCADE.
-- ==============================================================================

CREATE TABLE IF NOT EXISTS `patient_invitations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `patient_user_id` BIGINT UNSIGNED NOT NULL,
    `invitation_code` VARCHAR(32) NOT NULL UNIQUE,
    `caregiver_name` VARCHAR(150) DEFAULT NULL,
    `caregiver_contact` VARCHAR(50) DEFAULT NULL,
    `relationship_type` VARCHAR(100) NOT NULL DEFAULT 'Primary Family Member / Caregiver',
    `can_view_care_plan` TINYINT(1) NOT NULL DEFAULT 1,
    `can_receive_summaries` TINYINT(1) NOT NULL DEFAULT 1,
    `can_edit_assistance` TINYINT(1) NOT NULL DEFAULT 0,
    `status` ENUM('active', 'accepted', 'expired', 'cancelled') NOT NULL DEFAULT 'active',
    `expires_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_patient_invitations` (`patient_user_id`),
    INDEX `idx_invitation_code` (`invitation_code`),
    CONSTRAINT `fk_patient_invitations_user` FOREIGN KEY (`patient_user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
