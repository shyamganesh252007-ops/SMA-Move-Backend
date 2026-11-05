# SMA-MOVE — PHASE 7: COMPLETE BACKEND API INVENTORY

**Document Version:** 1.0.0  
**Phase:** 7.0 — Complete Backend Health Audit  
**Date:** November 5, 2026  
**Auditor:** SMA-Move Engineering Audit System  
**Environment:** Apache 2.4 / PHP 8.2 / MariaDB 10.4 (XAMPP Windows)  
**Base URL:** `http://localhost/sma-move-backend/api/` (or `http://<LAN_IP>/sma-move-backend/api/`)  
**Authentication Scheme:** Bearer Token (`Authorization: Bearer <hex_token>`)  
**Data Format:** `application/json; charset=UTF-8`

---

## 1. Inventory Summary

The SMA-Move backend exposes **42 API endpoint files** structured across 11 functional modules.

| Module | Endpoints | Primary Target Roles | Authentication |
| :--- | :---: | :--- | :--- |
| **System Health** | 1 | Public | None |
| **Authentication & Identity** | 7 | Public / Any Authenticated | Mixed (Public & Bearer Token) |
| **Patient Core & Profile** | 2 | Patient, Caregiver | Bearer Token (`patient`, `caregiver`) |
| **CareCircle & Caregiver** | 8 | Patient, Caregiver | Bearer Token (`patient`, `caregiver`) |
| **Healthcare Professional** | 5 | Professional, Patient | Bearer Token (`professional`, `patient`) |
| **Administration** | 1 | Admin / Secret Key | Bearer Token + Header Key |
| **Consent Management** | 3 | Patient | Bearer Token (`patient`) |
| **Care Plan & Daily Tasks** | 4 | Patient, Caregiver, Professional | Bearer Token |
| **Appointments & Telehealth** | 4 | Patient, Caregiver, Professional | Bearer Token |
| **Wellbeing Journal** | 3 | Caregiver, Professional | Bearer Token (`caregiver`, `professional`) |
| **MoveTrack Movement Analytics** | 4 | Caregiver, Professional, Patient | Bearer Token |
| **Total** | **42** | — | — |

---

## 2. System Health Endpoint

### 2.1 Health Check
* **File:** `api/health.php`
* **Method:** `GET`
* **Authentication:** Public (No token required)
* **Access Control:** None
* **Query Parameters:** None
* **Request Body:** None
* **Success Response (200 OK):**
  ```json
  {
    "status": "success",
    "data": {
      "status": "healthy",
      "timestamp": "2026-11-05 09:30:00",
      "environment": "development",
      "database": "connected"
    }
  }
  ```
* **Error Response (500 Internal Server Error):**
  ```json
  {
    "status": "error",
    "message": "Database connection failed"
  }
  ```

---

## 3. Authentication & Identity Management

### 3.1 User Registration
* **File:** `api/auth/signup.php`
* **Method:** `POST`
* **Authentication:** Public
* **Access Control:** Unauthenticated users
* **Request Body:**
  ```json
  {
    "email": "user@example.com",
    "password": "Password123!",
    "full_name": "Jane Doe",
    "role": "patient|caregiver|professional",
    "phone": "+1234567890",
    "license_number": "MD-123456" // Required only if role === professional
  }
  ```
* **Success Response (201 Created):**
  ```json
  {
    "status": "success",
    "message": "User registered successfully. Please verify your email.",
    "data": {
      "user_id": 12,
      "email": "user@example.com",
      "role": "patient",
      "status": "pending_verification"
    }
  }
  ```
* **Error Responses:**
  * `400 Bad Request`: Validation failure (weak password, missing fields, invalid role).
  * `409 Conflict`: Email address already registered.

### 3.2 User Authentication (Login)
* **File:** `api/auth/login.php`
* **Method:** `POST`
* **Authentication:** Public (Protected by IP Rate Limiter: 5 attempts / 15 min)
* **Access Control:** Unauthenticated users
* **Request Body:**
  ```json
  {
    "email": "user@example.com",
    "password": "Password123!"
  }
  ```
* **Success Response (200 OK):**
  ```json
  {
    "status": "success",
    "message": "Login successful",
    "data": {
      "user": {
        "id": 12,
        "email": "user@example.com",
        "full_name": "Jane Doe",
        "role": "patient",
        "status": "active",
        "email_verified": true
      },
      "access_token": "a1b2c3d4...",
      "refresh_token": "e5f6g7h8...",
      "token_type": "Bearer",
      "expires_in": 86400
    }
  }
  ```
* **Error Responses:**
  * `400 Bad Request`: Missing email or password.
  * `401 Unauthorized`: Invalid credentials.
  * `403 Forbidden`: Account pending email verification or suspended.
  * `429 Too Many Requests`: Rate limit exceeded.

### 3.3 User Logout & Session Invalidation
* **File:** `api/auth/logout.php`
* **Method:** `POST`
* **Authentication:** Bearer Token
* **Access Control:** Any authenticated user
* **Request Body:** None
* **Success Response (200 OK):**
  ```json
  {
    "status": "success",
    "message": "Logged out successfully",
    "data": null
  }
  ```
* **Error Responses:**
  * `401 Unauthorized`: Missing, expired, or revoked bearer token.

### 3.4 Current Authenticated User Profile Summary
* **File:** `api/auth/me.php`
* **Method:** `GET`
* **Authentication:** Bearer Token
* **Access Control:** Any authenticated user
* **Query Parameters:** None
* **Success Response (200 OK):**
  ```json
  {
    "status": "success",
    "data": {
      "user": {
        "id": 12,
        "email": "user@example.com",
        "full_name": "Jane Doe",
        "role": "patient",
        "status": "active",
        "phone": "+1234567890",
        "email_verified_at": "2026-11-04 12:00:00"
      }
    }
  }
  ```
* **Error Responses:**
  * `401 Unauthorized`: Invalid or missing bearer token.

### 3.5 Forgot Password Request
* **File:** `api/auth/forgot_password.php`
* **Method:** `POST`
* **Authentication:** Public (Rate Limited: 3 attempts / hour)
* **Access Control:** Any user
* **Request Body:**
  ```json
  {
    "email": "user@example.com"
  }
  ```
* **Success Response (200 OK):**
  ```json
  {
    "status": "success",
    "message": "If the email is registered, a password reset link has been dispatched."
  }
  ```
* **Error Responses:**
  * `400 Bad Request`: Invalid email format.
  * `429 Too Many Requests`: Rate limit exceeded.

### 3.6 Reset Password
* **File:** `api/auth/reset_password.php`
* **Method:** `POST`
* **Authentication:** Public
* **Access Control:** Valid single-use reset token holder
* **Request Body:**
  ```json
  {
    "token": "64_hex_character_reset_token",
    "new_password": "NewSecurePassword123!"
  }
  ```
* **Success Response (200 OK):**
  ```json
  {
    "status": "success",
    "message": "Password has been successfully reset. All active sessions have been invalidated."
  }
  ```
* **Error Responses:**
  * `400 Bad Request`: Token expired, invalid token, or password does not meet complexity requirements.

### 3.7 Verify Email & Account Activation
* **File:** `api/auth/verify_email.php`
* **Method:** `POST`
* **Authentication:** Public
* **Access Control:** User with 6-digit verification code
* **Request Body:**
  ```json
  {
    "email": "user@example.com",
    "code": "123456"
  }
  ```
* **Success Response (200 OK):**
  ```json
  {
    "status": "success",
    "message": "Email verified successfully. Account is now active."
  }
  ```
* **Error Responses:**
  * `400 Bad Request`: Invalid or expired activation code.

---

## 4. Patient Core & Profile

### 4.1 Aggregated Patient Dashboard
* **File:** `api/patient/dashboard.php`
* **Method:** `GET`
* **Authentication:** Bearer Token
* **Access Control:** Patient (self) OR Authorized Caregiver with valid consent
* **Query Parameters:**
  * `patient_id` (optional for patient, required for caregiver): Target patient ID.
  * `date` (optional, default: current date `YYYY-MM-DD`).
* **Success Response (200 OK):**
  ```json
  {
    "status": "success",
    "data": {
      "patient_info": { "id": 12, "full_name": "Jane Doe", "sma_type": "Type 2" },
      "care_tasks": [ ... ],
      "next_appointment": { ... },
      "latest_wellbeing": { ... },
      "recent_movetrack": { ... }
    }
  }
  ```
* **Error Responses:**
  * `401 Unauthorized`: Unauthenticated.
  * `403 Forbidden`: Caregiver lacks access permission or consent revoked.

### 4.2 Patient Profile Management
* **File:** `api/patient/profile.php`
* **Method:** `GET`, `PUT`
* **Authentication:** Bearer Token
* **Access Control:** `patient`
* **GET Query Parameters:** None
* **PUT Request Body:**
  ```json
  {
    "sma_type": "Type 2",
    "date_of_birth": "2005-06-15",
    "mobility_status": "Wheelchair dependent",
    "emergency_contact_name": "John Doe",
    "emergency_contact_phone": "+1987654321"
  }
  ```
* **Success Response (200 OK):** Updated or retrieved profile object.
* **Error Responses:** `400 Bad Request`, `401 Unauthorized`, `403 Forbidden`.

---

## 5. CareCircle & Caregiver Access Management

### 5.1 Invite Caregiver
* **File:** `api/caregiver/invite.php`
* **Method:** `POST`
* **Authentication:** Bearer Token
* **Access Control:** `patient`
* **Request Body:**
  ```json
  {
    "relationship": "Family Member|Parent|Spouse|Professional Aide",
    "can_view_wellbeing": true,
    "can_log_wellbeing": true,
    "can_view_care_plan": true,
    "can_edit_assistance": false
  }
  ```
* **Success Response (201 Created):**
  ```json
  {
    "status": "success",
    "data": {
      "invitation_code": "SMA-7K9P2X",
      "expires_at": "2026-11-07 10:00:00"
    }
  }
  ```

### 5.2 Request Caregiver Access
* **File:** `api/caregiver/request_access.php`
* **Method:** `POST`
* **Authentication:** Bearer Token
* **Access Control:** `caregiver`
* **Request Body:**
  ```json
  {
    "invitation_code": "SMA-7K9P2X"
  }
  ```
* **Success Response (200 OK):**
  ```json
  {
    "status": "success",
    "message": "Care access request submitted. Awaiting patient approval."
  }
  ```

### 5.3 Review Caregiver Requests
* **File:** `api/caregiver/review_requests.php`
* **Method:** `GET`
* **Authentication:** Bearer Token
* **Access Control:** `patient`
* **Success Response (200 OK):** List of pending, approved, and rejected caregiver relationships.

### 5.4 Approve / Reject Caregiver Request
* **File:** `api/caregiver/approve_request.php`
* **Method:** `POST`
* **Authentication:** Bearer Token
* **Access Control:** `patient`
* **Request Body:**
  ```json
  {
    "request_id": 5,
    "decision": "approved|rejected",
    "can_view_wellbeing": true,
    "can_log_wellbeing": true,
    "can_view_care_plan": true,
    "can_edit_assistance": false
  }
  ```
* **Success Response (200 OK):** Status updated.

### 5.5 Caregiver Authorized Patients List
* **File:** `api/caregiver/patients.php`
* **Method:** `GET`
* **Authentication:** Bearer Token
* **Access Control:** `caregiver`
* **Success Response (200 OK):** Array of linked patient profiles where relationship status is `approved`.

### 5.6 Caregiver Patient Summary
* **File:** `api/caregiver/patient_summary.php`
* **Method:** `GET`
* **Authentication:** Bearer Token
* **Access Control:** `caregiver`
* **Query Parameters:** `patient_id`
* **Success Response (200 OK):** Clinical and daily status overview for the requested authorized patient.

### 5.7 Caregiver Profile Management
* **File:** `api/caregiver/profile.php`
* **Method:** `GET`, `PUT`
* **Authentication:** Bearer Token
* **Access Control:** `caregiver`
* **PUT Request Body:** Profile information and contact details.

### 5.8 Revoke Caregiver Access
* **File:** `api/caregiver/revoke_access.php`
* **Method:** `POST`
* **Authentication:** Bearer Token
* **Access Control:** `patient` OR `caregiver`
* **Request Body:**
  ```json
  {
    "relationship_id": 5
  }
  ```
* **Success Response (200 OK):** Status set to `revoked`. Caregiver immediately loses access to patient data.

---

## 6. Healthcare Professional Management

### 6.1 Submit Professional Verification
* **File:** `api/professional/submit_verification.php`
* **Method:** `POST`
* **Authentication:** Bearer Token
* **Access Control:** `professional`
* **Request Body:**
  ```json
  {
    "license_number": "MED-789012",
    "specialty": "Pediatric Neurology",
    "organization": "Childrens National Hospital"
  }
  ```
* **Success Response (200 OK):** Verification submission recorded (`pending_verification`).

### 6.2 Query Professional Verification Status
* **File:** `api/professional/verification_status.php`
* **Method:** `GET`
* **Authentication:** Bearer Token
* **Access Control:** `professional`
* **Success Response (200 OK):** Returns current verification state (`pending_verification`, `verified`, `rejected`).

### 6.3 Professional Profile Management
* **File:** `api/professional/profile.php`
* **Method:** `GET`, `PUT`
* **Authentication:** Bearer Token
* **Access Control:** `professional`
* **Success Response (200 OK):** Professional credentials, contact details, and hospital affiliation.

### 6.4 Patient Authorize Professional
* **File:** `api/professional/authorize_patient.php`
* **Method:** `POST`
* **Authentication:** Bearer Token
* **Access Control:** `patient`
* **Request Body:**
  ```json
  {
    "professional_id": 8,
    "status": "authorized|revoked"
  }
  ```
* **Success Response (200 OK):** Authorization link created or revoked.

### 6.5 Professional Access to Patient Clinical Data
* **File:** `api/professional/patient_clinical_data.php`
* **Method:** `GET`
* **Authentication:** Bearer Token
* **Access Control:** `professional` with verified status AND patient authorization
* **Query Parameters:** `patient_id`
* **Success Response (200 OK):** Longitudinal clinical metrics, MoveTrack sessions, wellbeing trends.
* **Error Responses:**
  * `403 Forbidden`: Professional not verified OR patient has not authorized this clinician.

---

## 7. Administrative Verification

### 7.1 Verify Healthcare Professional (Admin)
* **File:** `api/admin/verify_professional.php`
* **Method:** `POST`
* **Authentication:** Bearer Token + Header `X-Admin-Key`
* **Access Control:** System Administrator
* **Request Body:**
  ```json
  {
    "professional_id": 8,
    "action": "verify|reject",
    "notes": "Verified against state medical board registry."
  }
  ```
* **Success Response (200 OK):** Professional status updated to `verified`.
* **Security Controls:** Requires configured non-empty `ADMIN_API_KEY` matching request header. Fails closed with `500 Internal Server Error` if `ADMIN_API_KEY` is undefined or default.

---

## 8. Patient Consent Management

### 8.1 Get Consent Preferences
* **File:** `api/consent/get_consent.php`
* **Method:** `GET`
* **Authentication:** Bearer Token
* **Access Control:** `patient`
* **Success Response (200 OK):** Active consent flags (analytics, camera tracking, third-party caregiver sharing).

### 8.2 Update Consent Preferences
* **File:** `api/consent/update_consent.php`
* **Method:** `POST`
* **Authentication:** Bearer Token
* **Access Control:** `patient`
* **Request Body:**
  ```json
  {
    "consent_type": "camera_tracking|caregiver_sharing|research_telemetry",
    "granted": true
  }
  ```
* **Success Response (200 OK):** Audit log recorded with IP and timestamp; preferences updated.

### 8.3 Immediate Consent Revocation
* **File:** `api/consent/revoke_consent.php`
* **Method:** `POST`
* **Authentication:** Bearer Token
* **Access Control:** `patient`
* **Request Body:**
  ```json
  {
    "consent_type": "all|camera_tracking|caregiver_sharing"
  }
  ```
* **Success Response (200 OK):** Revocation enacted immediately. Downstream queries check this state in real time.

---

## 9. Care Plan & Daily Tasks

### 9.1 List Tasks for Date
* **File:** `api/careplan/tasks.php`
* **Method:** `GET`
* **Authentication:** Bearer Token
* **Access Control:** `patient`, authorized `caregiver`, authorized `professional`
* **Query Parameters:** `patient_id` (optional for self), `date` (`YYYY-MM-DD`)
* **Success Response (200 OK):** Tasks, sub-steps, scheduled times, and completion statuses.

### 9.2 Care Task Details
* **File:** `api/careplan/task_details.php`
* **Method:** `GET`
* **Authentication:** Bearer Token
* **Access Control:** Authorized users
* **Query Parameters:** `task_id`
* **Success Response (200 OK):** Detailed instructions, clinical rationale, step-by-step guidance.

### 9.3 Toggle Task Completion
* **File:** `api/careplan/toggle_task.php`
* **Method:** `POST`
* **Authentication:** Bearer Token
* **Access Control:** `patient`, authorized `caregiver` (if granted `can_edit_assistance`)
* **Request Body:**
  ```json
  {
    "task_id": 14,
    "completion_date": "2026-11-05",
    "completed": true,
    "notes": "Completed morning physical therapy exercises."
  }
  ```
* **Success Response (200 OK):** Task completion record upserted.

### 9.4 Create Care Task
* **File:** `api/careplan/create_task.php`
* **Method:** `POST`
* **Authentication:** Bearer Token
* **Access Control:** `patient`, verified & authorized `professional`
* **Request Body:** Task title, schedule recurrence, category (`physiotherapy`, `medication`, `respiratory`), steps.
* **Success Response (201 Created):** New task entity.

---

## 10. Appointments & Telehealth

### 10.1 Create Appointment
* **File:** `api/appointments/create.php`
* **Method:** `POST`
* **Authentication:** Bearer Token
* **Access Control:** `patient`, `professional`
* **Security Guard:** `meeting_link` parameter is strictly forced to `NULL` for patients and unverified users. Only verified professionals may provide a telehealth link, which is rigorously validated against HTTPS and domain allowlists.
* **Request Body:**
  ```json
  {
    "patient_id": 12,
    "professional_id": 8,
    "scheduled_at": "2026-11-10 14:00:00",
    "appointment_type": "virtual|in_person",
    "meeting_link": "https://meet.google.com/abc-defg-hij" // Professional only
  }
  ```
* **Success Response (201 Created):** Appointment record.

### 10.2 List Appointments
* **File:** `api/appointments/list.php`
* **Method:** `GET`
* **Authentication:** Bearer Token
* **Access Control:** `patient`, `professional`, authorized `caregiver`
* **Query Parameters:** `status` (`scheduled`, `completed`, `cancelled`), `from_date`, `to_date`
* **Success Response (200 OK):** Filtered list of consultations.

### 10.3 Appointment Details
* **File:** `api/appointments/details.php`
* **Method:** `GET`
* **Authentication:** Bearer Token
* **Access Control:** Consultation participant (Patient, Doctor, or authorized Caregiver)
* **Query Parameters:** `appointment_id`
* **Success Response (200 OK):** Consultation specifics, notes, verified telehealth room URL.

### 10.4 Update Appointment Status
* **File:** `api/appointments/update_status.php`
* **Method:** `POST`
* **Authentication:** Bearer Token
* **Access Control:** Patient or Doctor participant
* **State Machine Guard:** Terminal states (`completed`, `cancelled`) are locked with pessimistic database row locks (`SELECT ... FOR UPDATE`). No transition out of terminal state is permitted.
* **Request Body:**
  ```json
  {
    "appointment_id": 3,
    "status": "completed|cancelled"
  }
  ```
* **Success Response (200 OK):** State transition applied.

---

## 11. Wellbeing Journal

### 11.1 Create Wellbeing Check-in
* **File:** `api/journal/create.php`
* **Method:** `POST`
* **Authentication:** Bearer Token
* **Access Control:** `patient`, authorized `caregiver` (with `can_log_wellbeing` permission)
* **Request Body:**
  ```json
  {
    "patient_id": 12,
    "fatigue_score": 3,
    "mood_score": 4,
    "pain_score": 1,
    "sleep_hours": 8.5,
    "notes": "Good energy throughout the day."
  }
  ```
* **Success Response (201 Created):** Recorded entry.

### 11.2 Latest Wellbeing Entry
* **File:** `api/journal/latest.php`
* **Method:** `GET`
* **Authentication:** Bearer Token
* **Access Control:** `patient`, authorized `caregiver`, authorized `professional`
* **Query Parameters:** `patient_id`
* **Success Response (200 OK):** Most recent check-in record.

### 11.3 List Wellbeing Entries
* **File:** `api/journal/list.php`
* **Method:** `GET`
* **Authentication:** Bearer Token
* **Access Control:** `patient`, authorized `caregiver`, authorized `professional`
* **Query Parameters:** `patient_id`, `limit`, `offset`
* **Success Response (200 OK):** Chronological paginated history.

---

## 12. MoveTrack Movement Tracking & Biometrics

### 12.1 Start MoveTrack Session
* **File:** `api/movetrack/start_session.php`
* **Method:** `POST`
* **Authentication:** Bearer Token
* **Access Control:** `patient`, authorized `caregiver`
* **Privacy Guard:** Verifies active patient consent for `camera_tracking`. If revoked, fails with `403 Forbidden`.
* **Request Body:**
  ```json
  {
    "patient_id": 12,
    "exercise_type": "arm_elevation|wrist_flexion|hand_opening"
  }
  ```
* **Success Response (201 Created):**
  ```json
  {
    "status": "success",
    "data": {
      "session_id": 101,
      "status": "in_progress",
      "started_at": "2026-11-05 09:35:00"
    }
  }
  ```

### 12.2 Complete MoveTrack Session
* **File:** `api/movetrack/complete_session.php`
* **Method:** `POST`
* **Authentication:** Bearer Token
* **Access Control:** Session creator
* **Data Privacy & Bounding Guard:** Strictly accepts only numerical kinematic summaries (repetition count, duration, range of motion angles, symmetry metrics). No raw video, audio, or frame imagery is accepted or stored. Range of motion bounded to [0.0, 180.0] degrees. Repetitions bounded to [0, 500].
* **Request Body:**
  ```json
  {
    "session_id": 101,
    "repetition_count": 15,
    "duration_seconds": 120,
    "range_of_motion_deg": 85.5,
    "symmetry_score": 92.0
  }
  ```
* **Success Response (200 OK):** Session closed and persisted.

### 12.3 List MoveTrack Sessions
* **File:** `api/movetrack/sessions.php`
* **Method:** `GET`
* **Authentication:** Bearer Token
* **Access Control:** `patient`, authorized `caregiver`, verified & authorized `professional`
* **Query Parameters:** `patient_id`, `limit`, `offset`
* **Success Response (200 OK):** Array of session summaries.

### 12.4 MoveTrack Session Details
* **File:** `api/movetrack/session_details.php`
* **Method:** `GET`
* **Authentication:** Bearer Token
* **Access Control:** Session participants
* **Query Parameters:** `session_id`
* **Success Response (200 OK):** Detailed breakdown of kinematic session metrics.
