<?php
declare(strict_types=1);

/**
 * SMA-Move — Caregiver Access & CareCircle Service
 *
 * Implements patient invitation, caregiver access requests, explicit patient approvals,
 * permission management, and revocation workflows.
 */

namespace SmaMove\Services;

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/models/CaregiverRelationship.php';
require_once dirname(__DIR__) . '/models/PatientInvitation.php';
require_once dirname(__DIR__) . '/models/CaregiverProfile.php';
require_once dirname(__DIR__) . '/models/PatientProfile.php';
require_once dirname(__DIR__) . '/models/Consent.php';
require_once dirname(__DIR__) . '/models/User.php';

use SmaMove\Config\Database;
use SmaMove\Models\CaregiverRelationship;
use SmaMove\Models\PatientInvitation;
use SmaMove\Models\CaregiverProfile;
use SmaMove\Models\PatientProfile;
use SmaMove\Models\Consent;
use SmaMove\Models\User;
use RuntimeException;
use Exception;

class CaregiverService
{
    /**
     * Patient creates a caregiver invitation with code.
     */
    public static function createInvitation(int $patientUserId, array $data): array
    {
        return PatientInvitation::create($patientUserId, $data);
    }

    /**
     * Caregiver requests access to a patient using an invitation code.
     */
    public static function requestAccessByCode(
        int $caregiverUserId,
        string $invitationCode,
        string $relationshipType = 'Primary Family Caregiver'
    ): array {
        $invitation = PatientInvitation::findByCode($invitationCode);

        if ($invitation === null) {
            throw new RuntimeException("Invalid, expired, or already used invitation code.", 404);
        }

        $patientUserId = (int)$invitation['patient_user_id'];

        if ($caregiverUserId === $patientUserId) {
            throw new RuntimeException("You cannot link to your own patient account as a caregiver.", 422);
        }

        // Check if an existing approved relationship already exists
        $existing = CaregiverRelationship::findByPatientAndCaregiver($patientUserId, $caregiverUserId);
        if ($existing && $existing['access_status'] === 'approved') {
            throw new RuntimeException("You are already an authorized caregiver for this patient.", 409);
        }

        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $relationshipId = CaregiverRelationship::createOrUpdate([
                'patient_user_id' => $patientUserId,
                'caregiver_user_id' => $caregiverUserId,
                'requested_by_user_id' => $caregiverUserId,
                'relationship_type' => !empty($relationshipType) ? $relationshipType : $invitation['relationship_type'],
                'access_status' => 'pending',
                'can_view_care_plan' => $invitation['can_view_care_plan'],
                'can_receive_summaries' => $invitation['can_receive_summaries'],
                'can_edit_assistance' => $invitation['can_edit_assistance'],
                'invitation_code' => $invitationCode,
            ]);

            // Mark invitation as accepted
            PatientInvitation::markAccepted((int)$invitation['id']);

            $pdo->commit();

            return [
                'relationship_id' => $relationshipId,
                'patient_name' => $invitation['patient_name'],
                'relationship_type' => $relationshipType,
                'access_status' => 'pending',
                'notice' => 'Access request submitted. Awaiting patient authorization.'
            ];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Get list of authorized patients for caregiver.
     */
    public static function getCaregiverPatients(int $caregiverUserId, bool $approvedOnly = true): array
    {
        return CaregiverRelationship::getPatientsForCaregiver($caregiverUserId, $approvedOnly);
    }

    /**
     * Get list of connected caregivers for patient.
     */
    public static function getPatientCaregivers(int $patientUserId): array
    {
        $caregivers = CaregiverRelationship::getCaregiversForPatient($patientUserId);
        $activeInvitations = PatientInvitation::getActiveInvitations($patientUserId);

        return [
            'connected_caregivers' => $caregivers,
            'active_invitations' => $activeInvitations
        ];
    }

    /**
     * Patient approves a pending caregiver request and sets permissions.
     */
    public static function approveRequest(int $patientUserId, int $relationshipId, array $permissions): array
    {
        $rel = CaregiverRelationship::findById($relationshipId);

        if (!$rel || (int)$rel['patient_user_id'] !== $patientUserId) {
            throw new RuntimeException("Relationship request not found.", 404);
        }

        if ((int)$rel['caregiver_user_id'] === $patientUserId) {
            throw new RuntimeException("Caregivers cannot approve their own requests on behalf of patients.", 403);
        }

        $success = CaregiverRelationship::approve($relationshipId, $patientUserId, $permissions);

        if (!$success) {
            throw new RuntimeException("Unable to approve caregiver request.", 500);
        }

        return CaregiverRelationship::findById($relationshipId) ?: [];
    }

    /**
     * Patient rejects a pending caregiver request.
     */
    public static function rejectRequest(int $patientUserId, int $relationshipId): bool
    {
        $rel = CaregiverRelationship::findById($relationshipId);

        if (!$rel || (int)$rel['patient_user_id'] !== $patientUserId) {
            throw new RuntimeException("Relationship request not found.", 404);
        }

        return CaregiverRelationship::reject($relationshipId, $patientUserId);
    }

    /**
     * Retrieve caregiver profile by user ID.
     */
    public static function getProfile(int $caregiverUserId): array
    {
        $profile = CaregiverProfile::getProfileWithUser($caregiverUserId);
        if (!$profile) {
            throw new RuntimeException("Caregiver profile not found.", 404);
        }
        return $profile;
    }

    /**
     * Update caregiver profile details.
     */
    public static function updateProfile(int $caregiverUserId, array $data): array
    {
        CaregiverProfile::updateProfile($caregiverUserId, $data);
        return self::getProfile($caregiverUserId);
    }

    /**
     * Caregiver retrieves authorized summary data for a linked patient.
     * Strictly verifies approval status, permissions, and active patient consent.
     */
    public static function getPatientSummary(int $caregiverUserId, int $patientUserId): array
    {
        $rel = CaregiverRelationship::findByPatientAndCaregiver($patientUserId, $caregiverUserId);

        if (!$rel) {
            throw new RuntimeException("Access denied. No relationship exists with this patient.", 403);
        }

        if ($rel['access_status'] !== 'approved') {
            throw new RuntimeException(
                "Access denied. Relationship status is '{$rel['access_status']}'. Only approved caregivers can view patient data.",
                403
            );
        }

        // Verify patient caregiver sharing consent
        if (!Consent::isGranted($patientUserId, Consent::TYPE_CAREGIVER)) {
            throw new RuntimeException(
                "Access denied. Patient has paused or revoked caregiver information sharing consent.",
                403
            );
        }

        $patient = PatientProfile::getProfileWithUser($patientUserId);

        $summary = [
            'patient_id' => $patientUserId,
            'patient_name' => $patient['full_name'],
            'relationship_type' => $rel['relationship_type'],
            'access_status' => $rel['access_status'],
            'permissions' => [
                'can_view_care_plan' => (bool)$rel['can_view_care_plan'],
                'can_receive_summaries' => (bool)$rel['can_receive_summaries'],
                'can_edit_assistance' => (bool)$rel['can_edit_assistance'],
            ]
        ];

        // Include emergency contact details
        if ($rel['can_view_care_plan']) {
            $summary['emergency_contact'] = [
                'name' => $patient['emergency_contact_name'],
                'phone' => $patient['emergency_contact_phone']
            ];
            $summary['preferred_language'] = $patient['preferred_language'];
        }

        return $summary;
    }

    /**
     * Revoke caregiver access (either party can initiate).
     */
    public static function revokeAccess(int $userId, int $relationshipId): bool
    {
        $rel = CaregiverRelationship::findById($relationshipId);

        if (!$rel) {
            throw new RuntimeException("Relationship record not found.", 404);
        }

        if ((int)$rel['patient_user_id'] !== $userId && (int)$rel['caregiver_user_id'] !== $userId) {
            throw new RuntimeException("Unauthorized. You are not a party to this relationship.", 403);
        }

        return CaregiverRelationship::revoke($relationshipId, $userId);
    }
}
