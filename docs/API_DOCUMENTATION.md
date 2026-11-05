# SMA-MOVE — API DOCUMENTATION: AUTHENTICATION & ROLE-BASED ACCESS CONTROL

**API Version:** 2.0.0 (Phase 3 & Phase 4)  
**Base URL:** `http://localhost/sma-move-backend/api/`  
**Authentication Scheme:** Bearer Token (`Authorization: Bearer <token>`)  
**Data Format:** `application/json; charset=UTF-8`  

---

## 1. Endpoints Overview

### 1.1 Authentication & Account Security (Phase 3)
| Method | Endpoint | Description | Auth Required | Role |
| :--- | :--- | :--- | :--- | :--- |
| `POST` | `/auth/signup.php` | Register new patient, caregiver, or professional | No | Any |
| `POST` | `/auth/login.php` | Authenticate credentials and issue bearer tokens | No | Any |
| `POST` | `/auth/logout.php` | Revoke active bearer access token | **Yes** | Any |
| `GET` | `/auth/me.php` | Retrieve current user profile summary | **Yes** | Any |
| `POST` | `/auth/forgot_password.php` | Request single-use password reset token | No | Any |
| `POST` | `/auth/reset_password.php` | Complete password reset and invalidate sessions | No | Any |
| `POST` | `/auth/verify_email.php` | Activate pending account with 6-digit code | No | Any |

### 1.2 Caregiver Access & CareCircle Management (Phase 4)
| Method | Endpoint | Description | Auth Required | Role |
| :--- | :--- | :--- | :--- | :--- |
| `POST` | `/caregiver/invite.php` | Patient generates invitation code (`SMA-XXXX`) | **Yes** | `patient` |
| `POST` | `/caregiver/request_access.php` | Caregiver submits code to request access | **Yes** | `caregiver` |
| `GET` | `/caregiver/patients.php` | Caregiver views authorized linked patients | **Yes** | `caregiver` |
| `GET` | `/caregiver/review_requests.php` | Patient reviews connected caregivers & invitations | **Yes** | `patient` |
| `POST` | `/caregiver/approve_request.php` | Patient approves/rejects request with permissions | **Yes** | `patient` |
| `POST` | `/caregiver/revoke_access.php` | Patient or Caregiver revokes relationship | **Yes** | `patient`/`caregiver` |

### 1.3 Healthcare Professional Verification (Phase 4)
| Method | Endpoint | Description | Auth Required | Role |
| :--- | :--- | :--- | :--- | :--- |
| `POST` | `/professional/submit_verification.php` | Specialist submits license & registration | **Yes** | `professional` |
| `GET` | `/professional/verification_status.php` | Query clinical verification status | **Yes** | `professional` |
| `GET` | `/professional/profile.php` | Retrieve clinical practitioner profile | **Yes** | `professional` |
| `PUT` | `/professional/profile.php` | Update clinical practitioner profile | **Yes** | `professional` |

### 1.4 Application Data Modules (Phase 5)
| Method | Endpoint | Description | Auth Required | Role |
| :--- | :--- | :--- | :--- | :--- |
| `GET` | `/patient/dashboard.php` | Aggregated dashboard (tasks, appointment, wellbeing, movetrack) | **Yes** | `patient` / `caregiver` |
| `GET` | `/careplan/tasks.php` | Active care tasks with steps and completion state for date | **Yes** | Any authorized |
| `GET` | `/careplan/task_details.php` | Care task protocol steps and instructions | **Yes** | Any authorized |
| `POST` | `/careplan/toggle_task.php` | Toggle/record task completion on date | **Yes** | `patient` / `caregiver` |
| `POST` | `/careplan/create_task.php` | Create clinician- or patient-defined task with steps | **Yes** | `patient` / `professional` |
| `GET` | `/appointments/list.php` | List scheduled/completed clinical consultations | **Yes** | Any authorized |
| `GET` | `/appointments/details.php` | Consultation details and virtual room link | **Yes** | Participant |
| `POST` | `/appointments/create.php` | Schedule a new clinical consultation | **Yes** | `patient` / `professional` |
| `POST` | `/appointments/update_status.php`| Transition appointment status | **Yes** | Participant |
| `POST` | `/journal/create.php` | Submit daily wellbeing check-in (mood, comfort, fatigue) | **Yes** | `patient` / `caregiver` |
| `GET` | `/journal/list.php` | Historical wellbeing check-in entries | **Yes** | Any authorized |
| `GET` | `/journal/latest.php` | Today's check-in status and latest entry | **Yes** | Any authorized |
| `POST` | `/movetrack/start_session.php` | Start session (enforces camera consent; minimal metadata) | **Yes** | `patient` |
| `POST` | `/movetrack/complete_session.php`| Finalize session (duration, consistency, quality) | **Yes** | `patient` |
| `GET` | `/movetrack/sessions.php` | List movement analysis sessions metadata | **Yes** | Any authorized |
| `GET` | `/movetrack/session_details.php` | Session summary metrics and non-diagnostic disclaimer | **Yes** | Any authorized |

---

## 2. Authentication Endpoint Details

*(See Section 2 of previous version for detailed Signup, Login, Logout, /me, and Password Reset specifications).*

---

## 3. Caregiver Access & CareCircle Endpoints

### 3.1 Patient Generates Caregiver Invitation (`POST /caregiver/invite.php`)
Allows a patient to generate a patient-approved invitation code (`SMA-XXXX`) for family or caregivers.

#### Headers
```http
Authorization: Bearer <patient_token>
Content-Type: application/json
```

#### Request Body
```json
{
  "relationship_type": "Primary Family Caregiver",
  "caregiver_name": "Rohan Caregiver",
  "caregiver_contact": "+91 9876543210",
  "can_view_care_plan": 1,
  "can_receive_summaries": 1,
  "can_edit_assistance": 0
}
```

#### Response (`201 Created`)
```json
{
  "success": true,
  "message": "Caregiver invitation code generated successfully.",
  "data": {
    "id": 1,
    "patient_user_id": 1,
    "invitation_code": "SMA-8A1F",
    "caregiver_name": "Rohan Caregiver",
    "caregiver_contact": "+91 9876543210",
    "relationship_type": "Primary Family Caregiver",
    "can_view_care_plan": true,
    "can_receive_summaries": true,
    "can_edit_assistance": false,
    "expires_at": "2026-11-11 09:30:00"
  }
}
```

---

### 3.2 Caregiver Requests Access (`POST /caregiver/request_access.php`)
Caregiver inputs the patient's invitation code to link to the patient in `pending` status.

#### Headers
```http
Authorization: Bearer <caregiver_token>
Content-Type: application/json
```

#### Request Body
```json
{
  "invitation_code": "SMA-8A1F",
  "relationship_type": "Brother / Primary Caregiver"
}
```

#### Response (`200 OK`)
```json
{
  "success": true,
  "message": "Access request submitted. Awaiting patient authorization.",
  "data": {
    "relationship_id": 1,
    "patient_name": "Kavita Patient",
    "relationship_type": "Brother / Primary Caregiver",
    "access_status": "pending",
    "notice": "Access request submitted. Awaiting patient authorization."
  }
}
```

---

### 3.3 Caregiver Lists Authorized Patients (`GET /caregiver/patients.php`)
Lists only patients who have authorized access (`access_status == 'approved'`).

#### Headers
```http
Authorization: Bearer <caregiver_token>
```

#### Response (`200 OK`)
```json
{
  "success": true,
  "message": "Authorized patients retrieved successfully.",
  "data": {
    "total": 1,
    "patients": [
      {
        "relationship_id": 1,
        "access_status": "approved",
        "relationship_type": "Brother / Primary Caregiver",
        "can_view_care_plan": 1,
        "can_receive_summaries": 1,
        "can_edit_assistance": 0,
        "approved_at": "2026-11-04 09:35:00",
        "patient_user_id": 1,
        "patient_name": "Kavita Patient",
        "patient_email": "kavita.patient@example.com",
        "preferred_language": "English",
        "emergency_contact_name": "Sunita",
        "emergency_contact_phone": "+91 9876543210"
      }
    ]
  }
}
```

---

### 3.4 Patient Reviews Connected Caregivers (`GET /caregiver/review_requests.php`)
Returns all connected caregivers, their status (pending, approved, rejected, revoked), and active invitation codes.

#### Headers
```http
Authorization: Bearer <patient_token>
```

#### Response (`200 OK`)
```json
{
  "success": true,
  "message": "CareCircle connections and invitations retrieved successfully.",
  "data": {
    "connected_caregivers": [
      {
        "relationship_id": 1,
        "access_status": "pending",
        "relationship_type": "Brother / Primary Caregiver",
        "can_view_care_plan": 1,
        "can_receive_summaries": 1,
        "can_edit_assistance": 0,
        "requested_at": "2026-11-04 09:34:00",
        "caregiver_user_id": 2,
        "caregiver_name": "Rohan Caregiver",
        "caregiver_email": "rohan.caregiver@example.com"
      }
    ],
    "active_invitations": []
  }
}
```

---

### 3.5 Patient Approves Caregiver Request (`POST /caregiver/approve_request.php`)

#### Request Body
```json
{
  "relationship_id": 1,
  "action": "approve",
  "can_view_care_plan": 1,
  "can_receive_summaries": 1,
  "can_edit_assistance": 0
}
```

#### Response (`200 OK`)
```json
{
  "success": true,
  "message": "Caregiver access request approved successfully.",
  "data": {
    "id": 1,
    "patient_user_id": 1,
    "caregiver_user_id": 2,
    "access_status": "approved",
    "can_view_care_plan": 1,
    "can_receive_summaries": 1,
    "can_edit_assistance": 0,
    "approved_at": "2026-11-04 09:36:00"
  }
}
```

---

### 3.6 Revoke Caregiver Access (`POST /caregiver/revoke_access.php`)

#### Request Body
```json
{
  "relationship_id": 1
}
```

#### Response (`200 OK`)
```json
{
  "success": true,
  "message": "Caregiver access has been successfully revoked.",
  "data": null
}
```

---

## 4. Healthcare Professional Verification Endpoints

### 4.1 Specialist Submits Credentials (`POST /professional/submit_verification.php`)

#### Headers
```http
Authorization: Bearer <professional_token>
Content-Type: application/json
```

#### Request Body
```json
{
  "registration_number": "DMC-PT-2026-8877",
  "institution_name": "All India Institute of Physical Medicine & Rehab (AIIPMR)",
  "professional_title": "Senior Neuro-Physiotherapist",
  "qualification": "MPT (Neurology), IAP Member"
}
```

#### Response (`200 OK`)
```json
{
  "success": true,
  "message": "Credentials submitted for review. Clinical access will be unlocked once verified by board administrators.",
  "data": {
    "verification_status": "pending",
    "submitted_at": "2026-11-04 09:37:00",
    "profile": {
      "id": 1,
      "user_id": 3,
      "professional_title": "Senior Neuro-Physiotherapist",
      "qualification": "MPT (Neurology), IAP Member",
      "registration_number": "DMC-PT-2026-8877",
      "institution_name": "All India Institute of Physical Medicine & Rehab (AIIPMR)",
      "verification_status": "pending"
    }
  }
}
```

---

### 4.2 Query Verification Status (`GET /professional/verification_status.php`)

#### Headers
```http
Authorization: Bearer <professional_token>
```

#### Response (`200 OK`)
```json
{
  "success": true,
  "message": "Verification status retrieved.",
  "data": {
    "verification_status": "pending",
    "registration_number": "DMC-PT-2026-8877",
    "institution_name": "All India Institute of Physical Medicine & Rehab (AIIPMR)",
    "professional_title": "Senior Neuro-Physiotherapist",
    "qualification": "MPT (Neurology), IAP Member",
    "can_access_clinical_records": false
  }
}
```
*(When `verification_status == 'verified'`, `can_access_clinical_records` becomes `true`).*

---

## 5. Patient & Caregiver Profile Endpoints

### 5.1 Patient Profile (`GET /patient/profile.php`, `PUT /patient/profile.php`)
- **GET**: Retrieves authenticated patient's demographics, emergency contact, and language preference.
- **PUT**: Updates patient demographic fields (`date_of_birth`, `preferred_language`, `emergency_contact_name`, `emergency_contact_phone`).

#### Headers
```http
Authorization: Bearer <patient_token>
Content-Type: application/json
```

### 5.2 Caregiver Profile (`GET /caregiver/profile.php`, `PUT /caregiver/profile.php`)
- **GET**: Retrieves authenticated caregiver's contact number and relationship description.
- **PUT**: Updates caregiver profile fields (`contact_number`, `relationship_description`).

#### Headers
```http
Authorization: Bearer <caregiver_token>
Content-Type: application/json
```

### 5.3 Caregiver Patient Summary (`GET /caregiver/patient_summary.php?patient_id=<id>`)
Retrieves permitted patient summary (IDOR-protected: verifies relationship is `approved` and patient has active `caregiver_sharing` consent).

#### Headers
```http
Authorization: Bearer <caregiver_token>
```

---

## 6. Patient Consent Management (Privacy & Telemetry Controls)

### 6.1 Get Active Consents (`GET /consent/get_consent.php`)
Returns the patient's current consent matrix (`camera_tracking`, `caregiver_sharing`, `clinical_sharing`).

#### Headers
```http
Authorization: Bearer <patient_token>
```

### 6.2 Update Consent (`POST /consent/update_consent.php`)
Explicitly grants or confirms a consent category.

#### Request Body
```json
{
  "consent_type": "caregiver_sharing",
  "granted": 1
}
```

### 6.3 Revoke Consent (`POST /consent/revoke_consent.php`)
Instantly revokes consent for a category, immediately blocking data sharing across caregiver/clinical endpoints.

#### Request Body
```json
{
  "consent_type": "caregiver_sharing"
}
```

---

## 7. Clinical Data Access & Board Verification Workflow

### 7.1 Patient Authorizes Doctor (`POST /professional/authorize_patient.php`)
Allows a patient to authorize a specific verified healthcare professional to access clinical records.

#### Headers
```http
Authorization: Bearer <patient_token>
Content-Type: application/json
```

#### Request Body
```json
{
  "professional_user_id": 3,
  "access_status": "approved"
}
```

### 7.2 Doctor Accesses Patient Clinical Data (`GET /professional/patient_clinical_data.php?patient_id=<id>`)
Doctor queries authorized patient clinical data. Server strictly enforces:
1. Professional's credentials must be `verified`.
2. Patient must have explicitly approved doctor in `patient_professional_access`.
3. Patient must have active `clinical_sharing` consent.

#### Headers
```http
Authorization: Bearer <professional_token>
```

### 7.3 Administrator Verifies Professional Credentials (`POST /admin/verify_professional.php`)
Administrative endpoint for credential review. Prevents self-verification (`professional_id !== reviewer_id`).
**Security Guard (Fail-Closed):** Requires `X-Admin-Key` header matching `ADMIN_API_KEY` using timing-safe comparison (`hash_equals()`). Rejects requests with 403 Forbidden if key is missing, empty, or unconfigured.

#### Headers
```http
Authorization: Bearer <reviewer_token>
X-Admin-Key: <ADMIN_API_KEY>
Content-Type: application/json
```

#### Request Body
```json
{
  "professional_id": 3,
  "status": "verified",
  "verification_notes": "License validated against DMC public registry."
}
```

---

## 8. Patient Aggregated Dashboard (`GET /patient/dashboard.php`)

Supplies live aggregated data for the Android `PatientDashboardScreen`. Returns greeting, care plan progress percentage, task previews, next upcoming appointment, today's wellbeing check-in state, and MoveTrack session status.

#### Headers
```http
Authorization: Bearer <patient_token>
```

#### Response (`200 OK`)
```json
{
  "success": true,
  "message": "Dashboard data retrieved successfully.",
  "data": {
    "patient": {
      "id": 1,
      "full_name": "Aarav Sharma",
      "email": "aarav.patient@example.com",
      "preferred_language": "English",
      "emergency_contact": {
        "name": "Sunita Sharma",
        "phone": "+91 9876543210"
      }
    },
    "current_date": "Tuesday, 04 Nov 2026",
    "care_plan": {
      "total_tasks": 4,
      "completed_tasks": 1,
      "progress_percent": 25,
      "date": "2026-11-04",
      "tasks_preview": [ ... ],
      "remaining_count": 3
    },
    "next_appointment": {
      "id": 1,
      "specialist_name": "Dr. Aris Thorne",
      "specialist_title": "Pulmonology Specialist",
      "appointment_date": "2026-11-14",
      "appointment_time": "10:00 AM – 10:45 AM (IST)",
      "session_type": "Virtual Care Consultation",
      "meeting_link": null
    },
    "wellbeing": {
      "has_checked_in_today": true,
      "latest_entry": {
        "mood": "Calm & Rested",
        "comfort_level": "Comfortable",
        "fatigue_level": "Mild",
        "mobility_assistance_needed": false
      }
    },
    "movetrack": {
      "camera_consent_granted": true,
      "total_sessions_completed": 3,
      "latest_session": {
        "session_uuid": "e839-4b11-...",
        "duration_seconds": 390,
        "tracking_quality_summary": "Steady Trunk Alignment",
        "consistency_percentage": 88
      }
    }
  }
}
```

---

## 9. Care Plan & Tasks Module

### 9.1 List Tasks (`GET /careplan/tasks.php?date=YYYY-MM-DD`)
Returns all active care tasks with steps and completion status for the specified date.

### 9.2 Task Details (`GET /careplan/task_details.php?task_id=X&date=YYYY-MM-DD`)
Supplies step-by-step instructions for `CareTaskDetailsScreen`.

### 9.3 Toggle Task Completion (`POST /careplan/toggle_task.php`)
```json
{
  "task_id": 1,
  "completed_date": "2026-11-04",
  "is_completed": true,
  "notes": "Completed morning protocol with caregiver support."
}
```

### 9.4 Create Care Task (`POST /careplan/create_task.php`)
Clinician- or patient-defined regimen with steps.

---

## 10. Clinical Appointments Module

### 10.1 List Appointments (`GET /appointments/list.php?status=scheduled`)
Lists upcoming consultations for the patient or doctor.

### 10.2 Consultation Details (`GET /appointments/details.php?id=X`)
Returns specialist credentials, virtual room link, and preparation notes.

### 10.3 Schedule Appointment (`POST /appointments/create.php`)
Schedules a clinical consultation under strict meeting link governance rules:
- **Patients and Caregivers:** May **never** supply a meeting link. The server strictly forces `meeting_link = null` regardless of payload contents or alternative field names.
- **Healthcare Professionals:** An external meeting link is accepted **only** if:
  1. The professional is verified (`ProfessionalProfile::isVerified`).
  2. The professional is explicitly authorized for this patient (`PatientProfessionalAccess::isAuthorized`).
  3. The professional is the assigned practitioner for the consultation.
  4. The URL strictly uses `https://` with no embedded credentials (`user:pass`).
  5. The hostname exactly matches the configured domain allowlist (`TELEHEALTH_APPROVED_DOMAINS`).
- If no link is provided, or if the domain allowlist is empty or unconfigured, `meeting_link` defaults strictly to `null`.
- Unapproved domains, non-HTTPS protocols, or malformed URLs return `422 Unprocessable Entity`.

```json
{
  "specialist_name": "Dr. Aris Thorne",
  "specialist_title": "Pulmonology Specialist",
  "appointment_date": "2026-11-14",
  "appointment_time": "10:00 AM – 10:45 AM (IST)",
  "session_type": "Virtual Care Consultation",
  "meeting_link": null
}
```

### 10.4 Update Appointment Status (`POST /appointments/update_status.php`)
Transitions consultation status under strict state machine rules:
- **Permitted from `scheduled`:** `completed`, `cancelled`, `rescheduled`
- **Permitted from `rescheduled`:** `completed`, `cancelled`, `rescheduled`
- **Terminal states:** `completed` and `cancelled` cannot be transitioned or reopened (HTTP 400).
- Employs row locking (`FOR UPDATE`) inside a transaction to prevent race conditions.

```json
{
  "appointment_id": 1,
  "status": "completed",
  "preparation_notes": "Session finished. Prescriptions confirmed."
}
```

---

## 11. Wellbeing Journal Module

### 11.1 Daily Check-in (`POST /journal/create.php`)
```json
{
  "mood": "Calm",
  "comfort_level": "Comfortable",
  "fatigue_level": "Mild",
  "mobility_assistance_needed": false,
  "notes": "Good mobility during morning stretches.",
  "symptom_notes": "No increased stiffness."
}
```

### 11.2 Historical Entries (`GET /journal/list.php`)
Lists patient check-ins. Scoped to patient or authorized caregiver/doctor.

### 11.3 Latest Check-in (`GET /journal/latest.php`)
Returns `has_checked_in_today` and latest recorded entry.

---

## 12. MoveTrack Movement Analysis (Minimal Metadata Only)

### 12.1 Start Session (`POST /movetrack/start_session.php`)
Initializes movement guidance session. **Precondition:** Patient must have active `camera_tracking` consent.

### 12.2 Complete Session (`POST /movetrack/complete_session.php`)
Finalizes session metrics.
**Server-side Numerical Bounds Enforced:**
- `duration_seconds`: integer between `1` and `86400` (1 sec to 24 hours).
- `consistency_percentage`: integer between `0` and `100` (percentage).
- `reps_completed`: integer between `0` and `1000`.
- Rejects negative values, out-of-range metrics, non-integers, and malformed types (HTTP 422).
- Non-diagnostic disclaimer included in response.

```json
{
  "session_uuid": "3a7b8e1f-49b2-...",
  "duration_seconds": 390,
  "tracking_quality_summary": "Steady Trunk Alignment",
  "consistency_percentage": 88,
  "reps_completed": 3,
  "user_notes": "Completed without discomfort."
}
```

### 12.3 List Sessions (`GET /movetrack/sessions.php`)
Lists historical session metadata.

### 12.4 Session Details (`GET /movetrack/session_details.php?session_uuid=X`)
Supplies metrics for `MoveTrackSessionSummaryScreen`.


