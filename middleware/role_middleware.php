<?php
declare(strict_types=1);

/**
 * SMA-Move — Role-Based Access Control (RBAC) & Privacy Middleware
 *
 * Enforces role restrictions, professional credential verification,
 * and authorized patient-caregiver relationship checks on protected endpoints.
 */

namespace SmaMove\Middleware;

require_once __DIR__ . '/auth_middleware.php';
require_once dirname(__DIR__) . '/utils/response.php';
require_once dirname(__DIR__) . '/models/ProfessionalProfile.php';
require_once dirname(__DIR__) . '/models/Consent.php';
require_once dirname(__DIR__) . '/models/PatientProfessionalAccess.php';
require_once dirname(__DIR__) . '/config/database.php';

use SmaMove\Middleware\AuthMiddleware;
use SmaMove\Utils\Response;
use SmaMove\Models\ProfessionalProfile;
use SmaMove\Models\Consent;
use SmaMove\Models\PatientProfessionalAccess;
use SmaMove\Config\Database;
use PDO;

class RoleMiddleware
{
    /**
     * Require that the authenticated user belongs to one of the specified roles.
     *
     * @param array<string> $allowedRoles e.g. ['patient'], ['caregiver'], ['professional']
     * @return array Authenticated user record
     */
    public static function requireRole(array $allowedRoles): array
    {
        $user = AuthMiddleware::authenticate();

        if (!in_array($user['role'], $allowedRoles, true)) {
            $allowedStr = implode(', ', $allowedRoles);
            Response::forbidden("Access forbidden. This endpoint requires one of the following roles: {$allowedStr}.");
        }

        return $user;
    }

    /**
     * Require Patient role.
     */
    public static function requirePatient(): array
    {
        return self::requireRole(['patient']);
    }

    /**
     * Require Caregiver role.
     */
    public static function requireCaregiver(): array
    {
        return self::requireRole(['caregiver']);
    }

    /**
     * Require Healthcare Professional role, optionally checking verification status.
     *
     * @param bool $requireVerified If true, blocks unverified/pending practitioners
     * @return array Authenticated professional user record
     */
    public static function requireProfessional(bool $requireVerified = false): array
    {
        $user = self::requireRole(['professional']);

        if ($requireVerified) {
            $profile = ProfessionalProfile::findByUserId($user['id']);

            $status = $profile['verification_status'] ?? 'not_submitted';

            if ($status !== 'verified') {
                Response::forbidden(
                    "Clinical access restricted. Professional credential verification status is '{$status}'. Access to patient records requires verified credentials."
                );
            }
        }

        return $user;
    }

    /**
     * Verify that a caregiver is authorized to access a specific patient's information.
     *
     * @param int $caregiverUserId The caregiver's user ID
     * @param int $patientUserId The patient's user ID
     * @param string|null $requiredPermission Optional specific permission to verify (e.g. 'can_view_care_plan')
     * @return array Relationship record
     */
    public static function verifyCaregiverPatientAccess(
        int $caregiverUserId,
        int $patientUserId,
        ?string $requiredPermission = null
    ): array {
        $pdo = Database::getConnection();

        $sql = "SELECT * FROM caregiver_relationships
                WHERE caregiver_user_id = :caregiver_id
                  AND patient_user_id = :patient_id
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':caregiver_id' => $caregiverUserId,
            ':patient_id' => $patientUserId
        ]);

        $relationship = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$relationship) {
            Response::forbidden("Access denied. No relationship exists between caregiver and this patient.");
        }

        if ($relationship['access_status'] !== 'approved') {
            Response::forbidden(
                "Access denied. Caregiver relationship status is '{$relationship['access_status']}'. Only approved caregivers may view patient care records."
            );
        }

        if ($requiredPermission !== null) {
            $hasPerm = (bool)($relationship[$requiredPermission] ?? false);
            if (!$hasPerm) {
                Response::forbidden("Access denied. Patient has not granted '{$requiredPermission}' permission to this caregiver.");
            }
        }

        return $relationship;
    }

    /**
     * Verify that a healthcare professional is verified AND specifically authorized for a patient.
     */
    public static function verifyProfessionalPatientAccess(int $professionalUserId, int $patientUserId): void
    {
        if (!ProfessionalProfile::isVerified($professionalUserId)) {
            Response::forbidden("Clinical access restricted. Healthcare professional credentials are not yet verified.");
        }

        if (!PatientProfessionalAccess::isAuthorized($professionalUserId, $patientUserId)) {
            Response::forbidden("Access denied. You have not been authorized by this patient for clinical monitoring.");
        }
    }

    /**
     * Prevent Insecure Direct Object References (IDOR) on patient data.
     */
    public static function verifyPatientOwnership(int $callerUserId, int $targetPatientUserId): void
    {
        if ($callerUserId !== $targetPatientUserId) {
            Response::forbidden("Access denied. You cannot view or modify records belonging to another patient.");
        }
    }

    /**
     * Verify that patient has actively granted a specific consent.
     */
    public static function verifyActiveConsent(int $patientUserId, string $consentType): void
    {
        if (!Consent::isGranted($patientUserId, $consentType)) {
            Response::forbidden("Operation blocked. Required patient consent ('{$consentType}') is not active or has been revoked.");
        }
    }
}
