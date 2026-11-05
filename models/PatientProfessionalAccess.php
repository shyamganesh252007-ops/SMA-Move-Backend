<?php
declare(strict_types=1);

/**
 * SMA-Move — Patient-Specific Healthcare Professional Access Model
 *
 * Implements explicit patient authorization for clinical specialists,
 * preventing arbitrary browsing of patient records by verified practitioners.
 */

namespace SmaMove\Models;

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/AuditLog.php';

use SmaMove\Config\Database;
use SmaMove\Models\AuditLog;
use PDO;

class PatientProfessionalAccess
{
    /**
     * Patient authorizes a healthcare professional for clinical access.
     */
    public static function authorize(
        int $patientUserId,
        int $professionalUserId,
        string $scope = 'Physical Therapy & Respiratory Monitoring'
    ): bool {
        $pdo = Database::getConnection();

        $sql = "INSERT INTO patient_professional_access (
            patient_user_id,
            professional_user_id,
            access_status,
            clinical_scope,
            authorized_at,
            revoked_at
        ) VALUES (
            :puid,
            :profid,
            'approved',
            :scope,
            NOW(),
            NULL
        ) ON DUPLICATE KEY UPDATE
            access_status = 'approved',
            clinical_scope = VALUES(clinical_scope),
            authorized_at = NOW(),
            revoked_at = NULL";

        $stmt = $pdo->prepare($sql);
        $res = $stmt->execute([
            ':puid' => $patientUserId,
            ':profid' => $professionalUserId,
            ':scope' => $scope
        ]);

        if ($res) {
            AuditLog::record(
                $patientUserId,
                'clinical_access',
                $professionalUserId,
                'authorize_professional',
                "Authorized professional {$professionalUserId} with scope: {$scope}"
            );
        }

        return $res;
    }

    /**
     * Patient revokes access for a healthcare professional.
     */
    public static function revoke(int $patientUserId, int $professionalUserId): bool
    {
        $pdo = Database::getConnection();

        $sql = "UPDATE patient_professional_access
                SET access_status = 'revoked', revoked_at = NOW()
                WHERE patient_user_id = :puid AND professional_user_id = :profid";

        $stmt = $pdo->prepare($sql);
        $res = $stmt->execute([':puid' => $patientUserId, ':profid' => $professionalUserId]);

        if ($res) {
            AuditLog::record(
                $patientUserId,
                'clinical_access',
                $professionalUserId,
                'revoke_professional',
                "Revoked clinical access for professional {$professionalUserId}"
            );
        }

        return $res;
    }

    /**
     * Verify whether a professional is explicitly authorized for a patient.
     */
    public static function isAuthorized(int $professionalUserId, int $patientUserId): bool
    {
        $pdo = Database::getConnection();

        $sql = "SELECT access_status FROM patient_professional_access
                WHERE professional_user_id = :profid
                  AND patient_user_id = :puid
                  AND access_status = 'approved'
                  AND revoked_at IS NULL
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':profid' => $professionalUserId, ':puid' => $patientUserId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Get authorized patients for a professional.
     */
    public static function getAuthorizedPatientsForProfessional(int $professionalUserId): array
    {
        $pdo = Database::getConnection();

        $sql = "SELECT ppa.id AS access_id, ppa.clinical_scope, ppa.authorized_at,
                       u.id AS patient_user_id, u.full_name AS patient_name, u.email AS patient_email,
                       pp.preferred_language, pp.date_of_birth, pp.emergency_contact_name, pp.emergency_contact_phone
                FROM patient_professional_access ppa
                JOIN users u ON ppa.patient_user_id = u.id
                LEFT JOIN patient_profiles pp ON u.id = pp.user_id
                WHERE ppa.professional_user_id = :profid
                  AND ppa.access_status = 'approved'
                  AND ppa.revoked_at IS NULL
                ORDER BY ppa.authorized_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':profid' => $professionalUserId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
