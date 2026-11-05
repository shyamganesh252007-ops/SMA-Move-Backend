-- ==============================================================================
-- Migration: 004_create_appointments_table.sql
-- Description: Creates appointments table for clinical consultation management.
-- Integrity: Links patient to user table and sets professional FK to NULL on delete.
-- ==============================================================================

CREATE TABLE IF NOT EXISTS `appointments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `patient_user_id` BIGINT UNSIGNED NOT NULL,
    `professional_user_id` BIGINT UNSIGNED DEFAULT NULL,
    `specialist_name` VARCHAR(150) NOT NULL,
    `specialist_title` VARCHAR(100) NOT NULL,
    `appointment_date` DATE NOT NULL,
    `appointment_time` VARCHAR(20) NOT NULL,
    `session_type` ENUM('Virtual Care Consultation', 'In-Person Assessment', 'Follow-up Review') NOT NULL,
    `status` ENUM('scheduled', 'completed', 'cancelled', 'rescheduled') NOT NULL DEFAULT 'scheduled',
    `meeting_link` VARCHAR(255) DEFAULT NULL,
    `preparation_notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_patient_appointment` (`patient_user_id`),
    INDEX `idx_professional_appointment` (`professional_user_id`),
    CONSTRAINT `fk_appointment_patient` FOREIGN KEY (`patient_user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_appointment_prof` FOREIGN KEY (`professional_user_id`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
