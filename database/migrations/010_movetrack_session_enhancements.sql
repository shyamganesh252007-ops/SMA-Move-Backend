-- ==============================================================================
-- Migration: 010_movetrack_session_enhancements.sql
-- Description: Enhances movetrack_sessions table to support in-progress sessions,
--              optional end_time, consistency metrics, and repetitions.
-- Privacy Principle: Minimal metadata only - NO raw camera footage or video persisted.
-- ==============================================================================

ALTER TABLE `movetrack_sessions`
    MODIFY COLUMN `end_time` DATETIME DEFAULT NULL,
    MODIFY COLUMN `duration_seconds` INT UNSIGNED DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `session_status` ENUM('in_progress', 'completed', 'aborted') NOT NULL DEFAULT 'in_progress' AFTER `session_type`,
    ADD COLUMN IF NOT EXISTS `consistency_percentage` INT UNSIGNED DEFAULT NULL AFTER `tracking_quality_summary`,
    ADD COLUMN IF NOT EXISTS `reps_completed` INT UNSIGNED DEFAULT 0 AFTER `consistency_percentage`;
