# SMA-MOVE — DATABASE AUDIT REPORT

**Audit Date:** November 4, 2026  
**Auditor:** Senior Database Architect (Antigravity)  
**Database Name:** `sma_move`  
**Engine:** MariaDB 10.4.32 (InnoDB)  
**Character Set:** `utf8mb4`  
**Collation:** `utf8mb4_unicode_ci`  

---

## 1. Executive Summary

A non-destructive schema inspection of the MariaDB database `sma_move` was conducted on November 4, 2026.

### Summary Findings
- **Database Status:** Exists, online, and accessible via PDO MySQL.
- **Existing Tables Confirmed:** Exactly 3 tables exist:
  1. `users`
  2. `patient_profiles`
  3. `professional_profiles`
- **Total Existing Records:** 0 records across all tables (clean development baseline).
- **Proposed Table `caregiver_relationships` Status:** **MISSING**. It has not been created in the physical database yet.
- **Additional Domain Tables Status:** Currently absent (care plans, appointments, wellbeing check-ins, consent tracking, MoveTrack session metadata, authentication tokens, password recovery).

All existing tables and foreign key constraints must be strictly preserved without dropping or modifying existing table structure.

---

## 2. Existing Schema Inspection (Ground Truth)

### 2.1 Table: `users`
Stores core user authentication records and role assignment.

```sql
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(254) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('patient','caregiver','professional') NOT NULL,
  `account_status` enum('pending','active','suspended') NOT NULL DEFAULT 'pending',
  `email_verified_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### Column Details
| Column | Type | Nullable | Default | Key | Extra |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `id` | `bigint(20) unsigned` | NO | NULL | PRI | auto_increment |
| `full_name` | `varchar(150)` | NO | NULL | | |
| `email` | `varchar(254)` | NO | NULL | UNI | |
| `password_hash` | `varchar(255)` | NO | NULL | | |
| `role` | `enum('patient','caregiver','professional')` | NO | NULL | | |
| `account_status` | `enum('pending','active','suspended')` | NO | 'pending' | | |
| `email_verified_at` | `datetime` | YES | NULL | | |
| `created_at` | `timestamp` | NO | current_timestamp() | | |
| `updated_at` | `timestamp` | NO | current_timestamp() | | on update current_timestamp() |

- **Current Row Count:** `0`
- **Integrity Status:** Primary key on `id`, unique constraint on `email`.

---

### 2.2 Table: `patient_profiles`
Stores extended demographic and emergency contact data for patient accounts.

```sql
CREATE TABLE `patient_profiles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `preferred_language` varchar(50) DEFAULT 'English',
  `emergency_contact_name` varchar(150) DEFAULT NULL,
  `emergency_contact_phone` varchar(20) DEFAULT NULL,
  `profile_completed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  CONSTRAINT `fk_patient_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### Column Details
| Column | Type | Nullable | Default | Key | Extra |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `id` | `bigint(20) unsigned` | NO | NULL | PRI | auto_increment |
| `user_id` | `bigint(20) unsigned` | NO | NULL | UNI | Foreign key to `users(id)` |
| `date_of_birth` | `date` | YES | NULL | | |
| `preferred_language`| `varchar(50)` | YES | 'English' | | |
| `emergency_contact_name` | `varchar(150)` | YES | NULL | | |
| `emergency_contact_phone`| `varchar(20)` | YES | NULL | | |
| `profile_completed` | `tinyint(1)` | NO | 0 | | |
| `created_at` | `timestamp` | NO | current_timestamp() | | |
| `updated_at` | `timestamp` | NO | current_timestamp() | | on update current_timestamp() |

- **Current Row Count:** `0`
- **Integrity Status:** One-to-one relationship with `users` (`UNIQUE KEY user_id`), foreign key with cascade on user deletion.

---

### 2.3 Table: `professional_profiles`
Stores clinical qualifications, licensing board registration, and verification status for healthcare professionals.

```sql
CREATE TABLE `professional_profiles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `professional_title` varchar(100) DEFAULT NULL,
  `qualification` varchar(255) DEFAULT NULL,
  `registration_number` varchar(100) DEFAULT NULL,
  `institution_name` varchar(200) DEFAULT NULL,
  `verification_status` enum('not_submitted','pending','verified','rejected') NOT NULL DEFAULT 'not_submitted',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  CONSTRAINT `fk_professional_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### Column Details
| Column | Type | Nullable | Default | Key | Extra |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `id` | `bigint(20) unsigned` | NO | NULL | PRI | auto_increment |
| `user_id` | `bigint(20) unsigned` | NO | NULL | UNI | Foreign key to `users(id)` |
| `professional_title` | `varchar(100)` | YES | NULL | | |
| `qualification` | `varchar(255)` | YES | NULL | | |
| `registration_number` | `varchar(100)` | YES | NULL | | |
| `institution_name` | `varchar(200)` | YES | NULL | | |
| `verification_status` | `enum('not_submitted','pending','verified','rejected')` | NO | 'not_submitted' | | |
| `created_at` | `timestamp` | NO | current_timestamp() | | |
| `updated_at` | `timestamp` | NO | current_timestamp() | | on update current_timestamp() |

- **Current Row Count:** `0`
- **Integrity Status:** One-to-one relationship with `users` (`UNIQUE KEY user_id`), foreign key with cascade on user deletion.

---

## 3. Missing Schema Analysis & Required Migrations

To fulfill the requirements of the SMA-Move specifications without breaking or modifying existing tables, the following new tables are required:

### 3.1 Migration 001: Authentication & Token Management
*Purpose: Support stateless bearer tokens, token revocation, refresh tokens, and password reset requests.*

1. **`auth_tokens`**
   - `id` (bigint unsigned, PK)
   - `user_id` (bigint unsigned, FK -> users.id ON DELETE CASCADE)
   - `token_hash` (varchar(64) - SHA-256 hash of random 64-char token, UNIQUE)
   - `token_type` (enum('access', 'refresh'))
   - `user_agent` (varchar(255) nullable)
   - `ip_address` (varchar(45) nullable)
   - `expires_at` (datetime NOT NULL)
   - `revoked_at` (datetime NULL)
   - `created_at` (timestamp)

2. **`password_resets`**
   - `id` (bigint unsigned, PK)
   - `user_id` (bigint unsigned, FK -> users.id ON DELETE CASCADE)
   - `reset_token_hash` (varchar(64) UNIQUE)
   - `expires_at` (datetime NOT NULL)
   - `used_at` (datetime NULL)
   - `created_at` (timestamp)

---

### 3.2 Migration 002: Caregiver Relationships & Caregiver Profiles
*Purpose: Implement the required access control workflow between patients and caregivers.*

1. **`caregiver_profiles`**
   - `id` (bigint unsigned, PK)
   - `user_id` (bigint unsigned, FK -> users.id ON DELETE CASCADE, UNIQUE)
   - `contact_number` (varchar(20) nullable)
   - `relationship_description` (varchar(100) nullable)
   - `created_at` (timestamp)
   - `updated_at` (timestamp)

2. **`caregiver_relationships`** (As specified in prompt design)
   - `id` (bigint unsigned, PK)
   - `patient_user_id` (bigint unsigned, FK -> users.id ON DELETE CASCADE)
   - `caregiver_user_id` (bigint unsigned, FK -> users.id ON DELETE CASCADE)
   - `requested_by_user_id` (bigint unsigned, FK -> users.id)
   - `relationship_type` (varchar(100) NOT NULL)
   - `access_status` (enum('pending', 'approved', 'rejected', 'revoked') NOT NULL DEFAULT 'pending')
   - `can_view_care_plan` (tinyint(1) NOT NULL DEFAULT 1)
   - `can_receive_summaries` (tinyint(1) NOT NULL DEFAULT 1)
   - `can_edit_assistance` (tinyint(1) NOT NULL DEFAULT 0)
   - `invitation_code` (varchar(32) NULL UNIQUE)
   - `requested_at` (datetime NOT NULL DEFAULT current_timestamp())
   - `approved_at` (datetime NULL)
   - `revoked_at` (datetime NULL)
   - `created_at` (timestamp)
   - `updated_at` (timestamp)
   - Constraint: `UNIQUE KEY unique_patient_caregiver (patient_user_id, caregiver_user_id)`

---

### 3.3 Migration 003: Patient Care Plans & Daily Tasks
*Purpose: Store daily clinical regimens, tasks, steps, and patient completion records.*

1. **`care_tasks`**
   - `id` (bigint unsigned, PK)
   - `patient_user_id` (bigint unsigned NULL - NULL indicates template task, FK -> users.id ON DELETE CASCADE)
   - `title` (varchar(200) NOT NULL)
   - `description` (text NOT NULL)
   - `category` (enum('Respiratory care', 'Positioning', 'Hydration', 'Care coordination', 'Wellbeing', 'Mobility') NOT NULL)
   - `duration_text` (varchar(50) NOT NULL DEFAULT '15 mins')
   - `scheduled_time` (varchar(20) NOT NULL DEFAULT '09:00 AM')
   - `prescribed_by` (varchar(150) NOT NULL DEFAULT 'Prescribed Regimen')
   - `is_active` (tinyint(1) NOT NULL DEFAULT 1)
   - `created_at` (timestamp)
   - `updated_at` (timestamp)

2. **`care_task_steps`**
   - `id` (bigint unsigned, PK)
   - `task_id` (bigint unsigned, FK -> care_tasks.id ON DELETE CASCADE)
   - `step_number` (int NOT NULL)
   - `title` (varchar(200) NOT NULL)
   - `instruction` (text NOT NULL)

3. **`patient_task_completions`**
   - `id` (bigint unsigned, PK)
   - `patient_user_id` (bigint unsigned, FK -> users.id ON DELETE CASCADE)
   - `task_id` (bigint unsigned, FK -> care_tasks.id ON DELETE CASCADE)
   - `completed_date` (date NOT NULL)
   - `is_completed` (tinyint(1) NOT NULL DEFAULT 1)
   - `completed_at` (datetime NOT NULL)
   - `notes` (text NULL)
   - Constraint: `UNIQUE KEY unique_task_date (patient_user_id, task_id, completed_date)`

---

### 3.4 Migration 004: Appointments
*Purpose: Manage clinical appointments, video consultations, and status updates.*

1. **`appointments`**
   - `id` (bigint unsigned, PK)
   - `patient_user_id` (bigint unsigned, FK -> users.id ON DELETE CASCADE)
   - `professional_user_id` (bigint unsigned NULL, FK -> users.id ON DELETE SET NULL)
   - `specialist_name` (varchar(150) NOT NULL)
   - `specialist_title` (varchar(100) NOT NULL)
   - `appointment_date` (date NOT NULL)
   - `appointment_time` (varchar(20) NOT NULL)
   - `session_type` (enum('Virtual Care Consultation', 'In-Person Assessment', 'Follow-up Review') NOT NULL)
   - `status` (enum('scheduled', 'completed', 'cancelled', 'rescheduled') NOT NULL DEFAULT 'scheduled')
   - `meeting_link` (varchar(255) NULL)
   - `preparation_notes` (text NULL)
   - `created_at` (timestamp)
   - `updated_at` (timestamp)

---

### 3.5 Migration 005: Wellbeing Check-Ins & Journal
*Purpose: Persist patient daily check-ins, mood, comfort levels, fatigue levels, and assistance flags.*

1. **`wellbeing_journal_entries`**
   - `id` (bigint unsigned, PK)
   - `patient_user_id` (bigint unsigned, FK -> users.id ON DELETE CASCADE)
   - `mood` (varchar(50) NOT NULL)
   - `comfort_level` (varchar(50) NOT NULL)
   - `fatigue_level` (varchar(50) NOT NULL DEFAULT 'Mild')
   - `mobility_assistance_needed` (tinyint(1) NOT NULL DEFAULT 0)
   - `notes` (text NULL)
   - `symptom_notes` (text NULL)
   - `entry_timestamp` (datetime NOT NULL DEFAULT current_timestamp())
   - `created_at` (timestamp)

---

### 3.6 Migration 006: Patient Consents & MoveTrack Metadata
*Purpose: Store fine-grained, revocable consent records and privacy-first movement tracking session metadata (NO raw video or frames).*

1. **`consents`**
   - `id` (bigint unsigned, PK)
   - `patient_user_id` (bigint unsigned, FK -> users.id ON DELETE CASCADE)
   - `consent_type` (enum('camera_tracking', 'caregiver_sharing', 'clinical_sharing') NOT NULL)
   - `consent_version` (varchar(20) NOT NULL DEFAULT '1.0.0')
   - `is_granted` (tinyint(1) NOT NULL DEFAULT 0)
   - `granted_at` (datetime NULL)
   - `revoked_at` (datetime NULL)
   - `ip_address` (varchar(45) NULL)
   - `created_at` (timestamp)
   - `updated_at` (timestamp)
   - Constraint: `UNIQUE KEY unique_user_consent_type (patient_user_id, consent_type)`

2. **`movetrack_sessions`**
   - `id` (bigint unsigned, PK)
   - `session_uuid` (varchar(36) NOT NULL UNIQUE)
   - `patient_user_id` (bigint unsigned, FK -> users.id ON DELETE CASCADE)
   - `session_type` (varchar(100) NOT NULL DEFAULT 'Posture Guidance Evaluation')
   - `start_time` (datetime NOT NULL)
   - `end_time` (datetime NOT NULL)
   - `duration_seconds` (int unsigned NOT NULL)
   - `model_version` (varchar(50) NOT NULL DEFAULT 'on-device-v1.0')
   - `tracking_quality_summary` (varchar(100) NOT NULL DEFAULT 'Satisfactory Alignment')
   - `user_notes` (text NULL)
   - `created_at` (timestamp)

---

## 4. Database Preservation Safeguards

1. **No Drop Statements**: No `DROP TABLE`, `DROP DATABASE`, or destructive `TRUNCATE` will ever be executed.
2. **Strict Foreign Keys**: Foreign keys enforce referential integrity with cascading rules where domain appropriate.
3. **Prepared Statements**: All database interaction will be parameterized through PHP PDO to prevent SQL injection.
4. **Idempotent Migrations**: All migration scripts will use `CREATE TABLE IF NOT EXISTS` and version tracking tables to ensure safe repeatability.
