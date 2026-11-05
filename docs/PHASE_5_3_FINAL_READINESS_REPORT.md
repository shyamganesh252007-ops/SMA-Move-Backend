# SMA-MOVE — PHASE 5.3: FINAL INTEGRATION READINESS VERIFICATION REPORT

**Date of Execution:** 2026-11-04  
**Audit Scope:** Read-Only Verification of Appointment Meeting-Link Trust & Appointment Concurrency Controls  
**Auditor:** Senior Backend Engineer & API Security Architect  
**Target Environment:** PHP 8.2.12 CLI / Apache 2.4 / MariaDB 10.4.32 (`sma_move`)  
**Android Status:** Untouched (`C:\Users\SCAD LAB\StudioProjects\sma-move-android`)  
**Phase 6 Status:** Not Started  

---

## 1. Executive Summary

As required by **Phase 5.3**, a focused, read-only verification was conducted on two critical areas of the SMA-Move appointment system prior to authorization of subsequent integration phases:
1. **Appointment Meeting-Link Trust and Domain Governance**
2. **Appointment Concurrency, Row Locking, and Terminal State Integrity**

The verification combined static code analysis with live, multi-session MariaDB testing. 

### Key Audit Findings:
- **Concurrency & Locking:** Fully verified. InnoDB pessimistic row locking (`SELECT ... FOR UPDATE`), transaction isolation, terminal state locks against stale updates, and automatic transaction rollback on failure are operating correctly.
- **Meeting-Link Trust (Issue Identified):** While unconfigured appointments correctly default to `meeting_link = NULL`, the current implementation in `api/appointments/create.php` and `models/Appointment.php` accepts any client-supplied string in `$input['meeting_link']`. An ordinary authenticated patient can submit arbitrary URLs, untrusted external domains, or dangerous schemes (e.g., `javascript:`), which are persisted in the database without restriction.

---

## 2. Inventory of Files Inspected

| File Path | Role in Appointment Flow | Inspection Focus |
| :--- | :--- | :--- |
| `api/appointments/create.php` | API Endpoint | Request parsing, role permissions, and `meeting_link` assignment |
| `models/Appointment.php` | Domain Model | Database insertion, FSM transitions, pessimistic row locking (`SELECT ... FOR UPDATE`), and rollback logic |
| `api/appointments/update_status.php` | API Endpoint | Participant authorization checks, request validation, and status mutation |
| `middleware/request_validation.php` | Middleware | Validation rules and request payload parsing |
| `.env` / `.env.example` | Configuration | Telehealth provider keys, URLs, or environment variables |

---

## 3. Area 1: Appointment Meeting-Link Trust Audit

### 3.1 Code Inspection Findings
In `api/appointments/create.php` (lines 68–85):
```php
// Determine meeting link: only use explicit link or configured provider integration (default to null)
$meetingLink = null;
if (!empty($input['meeting_link'])) {
    $meetingLink = trim((string)$input['meeting_link']);
}

$appointmentId = Appointment::create([
    'patient_user_id' => $patientUserId,
    'professional_user_id' => $profUserId,
    'specialist_name' => $input['specialist_name'],
    'specialist_title' => $input['specialist_title'] ?? 'Specialist',
    'appointment_date' => $input['appointment_date'],
    'appointment_time' => $input['appointment_time'],
    'session_type' => $input['session_type'] ?? 'Virtual Care Consultation',
    'status' => 'scheduled',
    'meeting_link' => $meetingLink,
    'preparation_notes' => $input['preparation_notes'] ?? null
]);
```

In `models/Appointment.php` (lines 22–65):
```php
$stmt->execute([
    ':patient_id' => $data['patient_user_id'],
    ':prof_id' => $data['professional_user_id'] ?? null,
    ':name' => trim($data['specialist_name']),
    ':title' => trim($data['specialist_title'] ?? 'Specialist'),
    ':app_date' => $data['appointment_date'],
    ':app_time' => $data['appointment_time'],
    ':session_type' => $data['session_type'] ?? 'Virtual Care Consultation',
    ':status' => $data['status'] ?? 'scheduled',
    ':meeting_link' => $data['meeting_link'] ?? null,
    ':prep_notes' => $data['preparation_notes'] ?? null
]);
```

### 3.2 Evaluation Against Criteria
1. **Unconfigured telehealth providers result in `meeting_link = NULL`:**
   - **Result:** **CONFIRMED (PASS)**. When the client does not provide `meeting_link`, the field evaluates to `NULL` and is persisted as `NULL` in MariaDB.
2. **Existing appointment functionality operates without telehealth configuration:**
   - **Result:** **CONFIRMED (PASS)**. Creation, listing (`listByPatient`, `listByProfessional`), upcoming queries (`getNextUpcoming`), and status transitions function seamlessly with `meeting_link = NULL`.
3. **An ordinary authenticated patient cannot submit an arbitrary meeting URL:**
   - **Result:** **FAILED (VULNERABILITY IDENTIFIED)**. If an ordinary patient includes a `meeting_link` property in the POST payload, it is accepted and persisted directly. There is no role guard restricting meeting link creation to healthcare professionals or trusted administrative workflows.
4. **Invalid URL schemes and untrusted domains are rejected:**
   - **Result:** **FAILED (VULNERABILITY IDENTIFIED)**. The backend performs no scheme validation (`filter_var(..., FILTER_VALIDATE_URL)`) or domain allowlisting. Payloads containing `javascript:alert(...)` or phishing URLs (`https://evil-phishing.com/...`) are accepted and stored.
5. **Actual Source of Every Accepted Meeting URL:**
   - **Source:** The **only** source of meeting URLs currently accepted by the backend is the raw, unsanitized client string provided in `$_POST['meeting_link']` (or JSON body). No server-side telehealth provider integration (e.g., Twilio Video, Daily.co, WebRTC) currently generates or signs room links.

---

## 4. Area 2: Appointment Concurrency & Row Locking Audit

### 4.1 Code Inspection Findings
In `models/Appointment.php` (lines 228–282):
- **Transaction Scope:** `updateStatus()` wraps all inspection and mutation operations in `$pdo->beginTransaction()` and `$pdo->commit()`.
- **Pessimistic Row Lock:** Line 233 executes `SELECT id, status, patient_user_id, professional_user_id FROM appointments WHERE id = :id FOR UPDATE`.
- **Terminal State Lock:** Lines 245–248 verify `in_array($currentStatus, self::TERMINAL_STATUSES, true)`. If the appointment is `completed` or `cancelled`, the transaction executes `$pdo->rollBack()` and throws a `400 Bad Request` exception before modifying any data.
- **State Transition Enforcement:** Lines 251–254 evaluate `self::canTransition($currentStatus, $newStatus)` against `ALLOWED_TRANSITIONS`. Unauthorized transitions trigger an immediate rollback.
- **Atomic Compare-and-Swap in SQL:** Lines 256–267 execute `UPDATE ... WHERE id = :id AND status = :current_status`. If `rowCount() === 0`, it triggers `$pdo->rollBack()` with a `409 Conflict`.
- **Catch-All Rollback:** Lines 276–280 ensure that any unexpected exception rolls back the transaction:
  ```php
  } catch (\Throwable $e) {
      if ($pdo->inTransaction()) {
          $pdo->rollBack();
      }
      throw $e;
  }
  ```
- **Participant Authorization:** In `api/appointments/update_status.php` lines 48–53, the caller's identity is strictly verified against `patient_user_id` and `professional_user_id` before `updateStatus()` is invoked.

### 4.2 Live Multi-Connection Concurrency Test
A live verification script (`tests/audit_phase5_3.php`) was executed against the local MariaDB database utilizing two completely independent PDO database connections (Session A and Session B) representing concurrent worker processes.

#### Test Execution Command:
```powershell
C:\xampp\php\php.exe tests/audit_phase5_3.php
```

#### Actual Test Output:
```text
====================================================================
 SMA-MOVE — PHASE 5.3 VERIFICATION AUDIT SUITE
====================================================================

--- 1. Testing Meeting-Link Trust & Domain Filtering ---
 [CHECK] Unconfigured meeting link defaults to: NULL (PASS)
 [FINDING] Arbitrary untrusted URL submitted: ACCEPTED AND PERSISTED BY MODEL (VULNERABILITY CONFIRMED)
 [FINDING] Dangerous URL scheme (javascript:) submitted: ACCEPTED AND PERSISTED BY MODEL (VULNERABILITY CONFIRMED)

--- 2. Testing Concurrency, Row Locking & Stale Updates ---
 [PASS] Established secondary independent PDO connection (Session B).
 [PASS] Session A acquired exclusive row lock (FOR UPDATE) on Appointment #62.
 [CHECK] Session B blocked by Session A's exclusive lock: YES (Lock wait timeout confirmed - PASS)
 [PASS] Session A updated status to 'completed' and committed transaction.
 [CHECK] Stale update rejected on terminal status: YES (PASS: Cannot update appointment. Status 'completed' is terminal and cannot be reopened or transitioned.)
 [CHECK] Database rollback on invalid transition leaves appointment intact: YES (Status remains 'scheduled' - PASS)

====================================================================
 CLEANUP: All synthetic test data removed.
====================================================================
```

### 4.3 Evaluation Against Concurrency Criteria
1. **Two concurrent updates cannot produce an invalid appointment state:**
   - **Result:** **VERIFIED (PASS)**. When Session A holds the row lock, Session B is blocked by MariaDB's InnoDB lock manager until Session A commits or rolls back. Once committed, Session B reads the updated status and evaluates the transition rules against the newly committed state.
2. **A stale update cannot reopen a completed or cancelled appointment:**
   - **Result:** **VERIFIED (PASS)**. Attempting to transition Appointment #62 from `completed` to `scheduled` was rejected with HTTP 400 (`Status 'completed' is terminal and cannot be reopened or transitioned`).
3. **Row locks are held within the appropriate transaction:**
   - **Result:** **VERIFIED (PASS)**. Row locks are acquired after `beginTransaction()` and held until `commit()` or `rollBack()`.
4. **Database rollback occurs when a transition fails:**
   - **Result:** **VERIFIED (PASS)**. When an invalid transition is attempted, the transaction rolls back, leaving the record in its original `scheduled` state with zero data corruption.
5. **Participant authorization is checked before modifying the appointment:**
   - **Result:** **VERIFIED (PASS)**. `api/appointments/update_status.php` evaluates `$isPatient || $isDoctor` prior to model execution.

---

## 5. Identified Issues & Recommended Remediation

Prior to production deployment or opening appointment booking to ordinary clients, one security gap must be addressed:

### Issue 5.3.1: Untrusted Client-Supplied `meeting_link` (Medium Severity)
- **Current Behavior:**  
  Any authenticated client (including ordinary patients) can supply an arbitrary string in the `meeting_link` field of `POST /api/appointments/create.php`, which is stored and subsequently returned to users and displayed in the Android frontend.
- **Risk:**  
  Malicious actors or compromised patient accounts could inject phishing URLs or malicious URL schemes (`javascript:`, untrusted redirectors) masquerading as official clinical telehealth consultation links.
- **Recommended Remediation (Pending User Authorization):**
  1. **Strict Null Policy for Ordinary Patients:** Ordinary patients booking appointments must **not** be allowed to supply `meeting_link`. The field should be forced to `NULL` on patient creation.
  2. **Role Restriction:** Only verified healthcare professionals (`role === 'professional'`) or system automation keys should be permitted to attach an external meeting link.
  3. **URL Scheme & Domain Validation:** If a professional supplies an external URL, the server must validate:
     - Scheme must be strictly `https://` (`filter_var($url, FILTER_VALIDATE_URL)`).
     - Domain must match an approved clinical video provider allowlist (or an environment variable `ALLOWED_TELEHEALTH_DOMAINS`).

---

## 6. Strict Boundary Adherence Confirmation

- **Android Repository:** [`C:\Users\SCAD LAB\StudioProjects\sma-move-android`](file:///C:/Users/SCAD%20LAB/StudioProjects/sma-move-android) was **strictly untouched**.
- **Backend Source Code:** **No application source files were modified** during Phase 5.3.
- **Database Integrity:** No migrations were created or executed; all test fixtures were automatically removed; all 14 domain tables contain **0 rows**.
- **Phase 6 Status:** **Phase 6 has NOT been started.**

---

Phase 5.3 verification is complete. I have stopped here and am waiting for your review and directions.


---

## 7. Post-Audit Resolution (Phase 5.4 Follow-Up)

Following the identification of Issue 5.3.1, the user authorized **Phase 5.4** to implement the recommended security corrections:

1. **Patient and Caregiver URL Blocking:**  
   `api/appointments/create.php` was updated to strictly force `meeting_link = NULL` for all patient and caregiver submissions, regardless of payload values or parameter aliases.
2. **Healthcare Professional Validation & Assignment Check:**  
   Meeting links supplied by healthcare professionals are accepted only if the professional is verified, explicitly authorized by the patient, and assigned to the consultation.
3. **Approved Domain Allowlist & Scheme Enforcement:**  
   Implemented `Validation::validateTelehealthUrl()` in `utils/validation.php`. Requires `https://`, validates format via `filter_var()`, rejects embedded credentials, and enforces exact hostname matching against `TELEHEALTH_APPROVED_DOMAINS`. Malformed or unapproved URLs return `422 Unprocessable Entity`.
4. **Verification & Testing:**  
   Verified via dedicated test suite `tests/test_phase_5_4_meeting_link_security.php` (19 passed, 0 failed). Full documentation is provided in `docs/PHASE_5_4_MEETING_LINK_SECURITY_REPORT.md`.
