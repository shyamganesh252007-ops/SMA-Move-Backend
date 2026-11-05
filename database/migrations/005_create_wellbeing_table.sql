-- ==============================================================================
-- Migration: 005_create_wellbeing_table.sql
-- Description: Creates wellbeing_journal_entries table for mood, comfort, and fatigue tracking.
-- Integrity: Enforces foreign key constraint to users(id).
-- ==============================================================================

CREATE TABLE IF NOT EXISTS `wellbeing_journal_entries` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `patient_user_id` BIGINT UNSIGNED NOT NULL,
    `mood` VARCHAR(50) NOT NULL,
    `comfort_level` VARCHAR(50) NOT NULL,
    `fatigue_level` VARCHAR(50) NOT NULL DEFAULT 'Mild',
    `mobility_assistance_needed` TINYINT(1) NOT NULL DEFAULT 0,
    `notes` TEXT DEFAULT NULL,
    `symptom_notes` TEXT DEFAULT NULL,
    `entry_timestamp` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_patient_wellbeing` (`patient_user_id`, `entry_timestamp`),
    CONSTRAINT `fk_wellbeing_patient` FOREIGN KEY (`patient_user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
