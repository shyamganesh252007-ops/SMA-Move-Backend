<?php
declare(strict_types=1);

/**
 * SMA-Move — Caregiver Relationship Data Model
 */

namespace SmaMove\Models;

require_once dirname(__DIR__) . '/config/database.php';

use SmaMove\Config\Database;
use PDO;

class CaregiverRelationship
{
    /**
     * Create or update a caregiver relationship.
     */
    public static function createOrUpdate(array $data): int
    {
        $pdo = Database::getConnection();

        $sql = "INSERT INTO caregiver_relationships (
            patient_user_id,
            caregiver_user_id,
            requested_by_user_id,
            relationship_type,
            access_status,
            can_view_care_plan,
            can_receive_summaries,
            can_edit_assistance,
            invitation_code,
            requested_at
        ) VALUES (
            :patient_user_id,
            :caregiver_user_id,
            :requested_by_user_id,
            :relationship_type,
            :access_status,
            :can_view_care_plan,
            :can_receive_summaries,
            :can_edit_assistance,
            :invitation_code,
            NOW()
        ) ON DUPLICATE KEY UPDATE
            requested_by_user_id = VALUES(requested_by_user_id),
            relationship_type = VALUES(relationship_type),
            access_status = VALUES(access_status),
            can_view_care_plan = VALUES(can_view_care_plan),
            can_receive_summaries = VALUES(can_receive_summaries),
            can_edit_assistance = VALUES(can_edit_assistance),
            requested_at = NOW(),
            revoked_at = NULL";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':patient_user_id' => $data['patient_user_id'],
            ':caregiver_user_id' => $data['caregiver_user_id'],
            ':requested_by_user_id' => $data['requested_by_user_id'],
            ':relationship_type' => $data['relationship_type'] ?? 'Primary Family Caregiver',
            ':access_status' => $data['access_status'] ?? 'pending',
            ':can_view_care_plan' => (int)($data['can_view_care_plan'] ?? 1),
            ':can_receive_summaries' => (int)($data['can_receive_summaries'] ?? 1),
            ':can_edit_assistance' => (int)($data['can_edit_assistance'] ?? 0),
            ':invitation_code' => $data['invitation_code'] ?? null,
        ]);

        $lastId = (int)$pdo->lastInsertId();
        if ($lastId > 0) {
            return $lastId;
        }

        // Fetch existing ID if updated
        $existing = self::findByPatientAndCaregiver((int)$data['patient_user_id'], (int)$data['caregiver_user_id']);
        return $existing ? (int)$existing['id'] : 0;
    }

    /**
     * Find relationship by patient and caregiver IDs.
     */
    public static function findByPatientAndCaregiver(int $patientUserId, int $caregiverUserId): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM caregiver_relationships WHERE patient_user_id = :puid AND caregiver_user_id = :cuid LIMIT 1");
        $stmt->execute([':puid' => $patientUserId, ':cuid' => $caregiverUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Find relationship by ID.
     */
    public static function findById(int $id): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM caregiver_relationships WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Get all authorized patients for a specific caregiver.
     */
    public static function getPatientsForCaregiver(int $caregiverUserId, bool $approvedOnly = true): array
    {
        $pdo = Database::getConnection();

        $sql = "SELECT cr.id AS relationship_id, cr.access_status, cr.relationship_type,
                       cr.can_view_care_plan, cr.can_receive_summaries, cr.can_edit_assistance,
                       cr.approved_at, cr.requested_at,
                       u.id AS patient_user_id, u.full_name AS patient_name, u.email AS patient_email,
                       pp.preferred_language, pp.emergency_contact_name, pp.emergency_contact_phone
                FROM caregiver_relationships cr
                JOIN users u ON cr.patient_user_id = u.id
                LEFT JOIN patient_profiles pp ON u.id = pp.user_id
                WHERE cr.caregiver_user_id = :cuid";

        if ($approvedOnly) {
            $sql .= " AND cr.access_status = 'approved'";
        }

        $sql .= " ORDER BY cr.updated_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':cuid' => $caregiverUserId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get all connected caregivers for a specific patient.
     */
    public static function getCaregiversForPatient(int $patientUserId): array
    {
        $pdo = Database::getConnection();

        $sql = "SELECT cr.id AS relationship_id, cr.access_status, cr.relationship_type,
                       cr.can_view_care_plan, cr.can_receive_summaries, cr.can_edit_assistance,
                       cr.requested_at, cr.approved_at, cr.revoked_at,
                       u.id AS caregiver_user_id, u.full_name AS caregiver_name, u.email AS caregiver_email,
                       cp.contact_number, cp.relationship_description
                FROM caregiver_relationships cr
                JOIN users u ON cr.caregiver_user_id = u.id
                LEFT JOIN caregiver_profiles cp ON u.id = cp.user_id
                WHERE cr.patient_user_id = :puid
                ORDER BY cr.updated_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':puid' => $patientUserId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Approve caregiver access request.
     */
    public static function approve(int $relationshipId, int $patientUserId, array $permissions = []): bool
    {
        $pdo = Database::getConnection();

        $sql = "UPDATE caregiver_relationships SET
                    access_status = 'approved',
                    approved_at = NOW(),
                    revoked_at = NULL,
                    can_view_care_plan = :can_view,
                    can_receive_summaries = :can_sum,
                    can_edit_assistance = :can_edit
                WHERE id = :id AND patient_user_id = :puid";

        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            ':can_view' => isset($permissions['can_view_care_plan']) ? (int)$permissions['can_view_care_plan'] : 1,
            ':can_sum' => isset($permissions['can_receive_summaries']) ? (int)$permissions['can_receive_summaries'] : 1,
            ':can_edit' => isset($permissions['can_edit_assistance']) ? (int)$permissions['can_edit_assistance'] : 0,
            ':id' => $relationshipId,
            ':puid' => $patientUserId
        ]);
    }

    /**
     * Reject caregiver access request.
     */
    public static function reject(int $relationshipId, int $patientUserId): bool
    {
        $pdo = Database::getConnection();
        $sql = "UPDATE caregiver_relationships SET access_status = 'rejected', approved_at = NULL WHERE id = :id AND patient_user_id = :puid";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([':id' => $relationshipId, ':puid' => $patientUserId]);
    }

    /**
     * Revoke caregiver access (either party can initiate).
     */
    public static function revoke(int $relationshipId, int $userId): bool
    {
        $pdo = Database::getConnection();
        $sql = "UPDATE caregiver_relationships SET access_status = 'revoked', revoked_at = NOW() WHERE id = :id AND (patient_user_id = :puid OR caregiver_user_id = :cuid)";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            ':id' => $relationshipId,
            ':puid' => $userId,
            ':cuid' => $userId
        ]);
    }
}
