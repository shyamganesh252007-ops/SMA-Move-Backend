-- ==============================================================================
-- Migration: 003_create_care_plans_table.sql
-- Description: Creates care_tasks, care_task_steps, and patient_task_completions tables.
-- Integrity: Enforces foreign key constraints with cascade deletes.
-- ==============================================================================

CREATE TABLE IF NOT EXISTS `care_tasks` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `patient_user_id` BIGINT UNSIGNED DEFAULT NULL,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT NOT NULL,
    `category` ENUM('Respiratory care', 'Positioning', 'Hydration', 'Care coordination', 'Wellbeing', 'Mobility') NOT NULL,
    `duration_text` VARCHAR(50) NOT NULL DEFAULT '15 mins',
    `scheduled_time` VARCHAR(20) NOT NULL DEFAULT '09:00 AM',
    `prescribed_by` VARCHAR(150) NOT NULL DEFAULT 'Prescribed Regimen',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_patient_tasks` (`patient_user_id`),
    CONSTRAINT `fk_care_tasks_patient` FOREIGN KEY (`patient_user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `care_task_steps` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `task_id` BIGINT UNSIGNED NOT NULL,
    `step_number` INT NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `instruction` TEXT NOT NULL,
    INDEX `idx_task_steps` (`task_id`, `step_number`),
    CONSTRAINT `fk_care_steps_task` FOREIGN KEY (`task_id`)
        REFERENCES `care_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `patient_task_completions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `patient_user_id` BIGINT UNSIGNED NOT NULL,
    `task_id` BIGINT UNSIGNED NOT NULL,
    `completed_date` DATE NOT NULL,
    `is_completed` TINYINT(1) NOT NULL DEFAULT 1,
    `completed_at` DATETIME NOT NULL,
    `notes` TEXT DEFAULT NULL,
    UNIQUE KEY `unique_task_patient_date` (`patient_user_id`, `task_id`, `completed_date`),
    CONSTRAINT `fk_completions_patient` FOREIGN KEY (`patient_user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_completions_task` FOREIGN KEY (`task_id`)
        REFERENCES `care_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
