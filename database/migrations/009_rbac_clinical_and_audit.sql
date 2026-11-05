-- ==============================================================================
-- Migration: 009_rbac_clinical_and_audit.sql
-- Description: Adds patient_professional_access for doctor-patient authorization,
--              auditable fields for professional verification, and permission audit logs.
-- Integrity: Non-destructive addition with foreign keys and unique constraints.
-- ==============================================================================

-- 1. Patient-Specific Healthcare Professional Access Authorization
CREATE TABLE IF NOT EXISTS `patient_professional_access` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `patient_user_id` BIGINT UNSIGNED NOT NULL,
    `professional_user_id` BIGINT UNSIGNED NOT NULL,
    `access_status` ENUM('pending', 'approved', 'revoked') NOT NULL DEFAULT 'pending',
    `clinical_scope` VARCHAR(100) NOT NULL DEFAULT 'Physical Therapy & Respiratory Monitoring',
    `authorized_at` DATETIME DEFAULT NULL,
    `revoked_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_patient_professional` (`patient_user_id`, `professional_user_id`),
    INDEX `idx_ppa_prof_status` (`professional_user_id`, `access_status`),
    INDEX `idx_ppa_patient` (`patient_user_id`),
    CONSTRAINT `fk_ppa_patient_user` FOREIGN KEY (`patient_user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ppa_prof_user` FOREIGN KEY (`professional_user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Sensitive Permission & Consent Audit Log
CREATE TABLE IF NOT EXISTS `permission_audit_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `actor_user_id` BIGINT UNSIGNED NOT NULL,
    `target_type` VARCHAR(50) NOT NULL,
    `target_id` BIGINT UNSIGNED NOT NULL,
    `action` VARCHAR(50) NOT NULL,
    `details` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_pal_actor` (`actor_user_id`),
    INDEX `idx_pal_target` (`target_type`, `target_id`),
    CONSTRAINT `fk_pal_actor_user` FOREIGN KEY (`actor_user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Add audit columns to professional_profiles if not present
ALTER TABLE `professional_profiles`
    ADD COLUMN IF NOT EXISTS `verified_by_user_id` BIGINT UNSIGNED DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `verification_notes` TEXT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `verified_at` DATETIME DEFAULT NULL;
