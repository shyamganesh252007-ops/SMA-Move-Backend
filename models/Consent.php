<?php
declare(strict_types=1);

/**
 * SMA-Move — Patient Consent Model
 *
 * Implements granular, revocable consent for camera tracking,
 * caregiver sharing, and clinical specialist sharing.
 */

namespace SmaMove\Models;

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/AuditLog.php';

use SmaMove\Config\Database;
use SmaMove\Models\AuditLog;
use PDO;

class Consent
{
    public const TYPE_CAMERA = 'camera_tracking';
    public const TYPE_CAREGIVER = 'caregiver_sharing';
    public const TYPE_CLINICAL = 'clinical_sharing';

    /**
     * Get specific consent status for a patient.
     */
    public static function getConsent(int $patientUserId, string $consentType): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM consents WHERE patient_user_id = :puid AND consent_type = :type LIMIT 1");
        $stmt->execute([':puid' => $patientUserId, ':type' => $consentType]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Get all consent preferences for a patient.
     */
    public static function getAllConsents(int $patientUserId): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM consents WHERE patient_user_id = :puid");
        $stmt->execute([':puid' => $patientUserId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $defaultConsents = [
            self::TYPE_CAMERA => ['is_granted' => 0, 'granted_at' => null, 'revoked_at' => null],
            self::TYPE_CAREGIVER => ['is_granted' => 0, 'granted_at' => null, 'revoked_at' => null],
            self::TYPE_CLINICAL => ['is_granted' => 0, 'granted_at' => null, 'revoked_at' => null]
        ];

        foreach ($rows as $row) {
            $type = $row['consent_type'];
            $defaultConsents[$type] = [
                'id' => (int)$row['id'],
                'is_granted' => (bool)$row['is_granted'],
                'consent_version' => $row['consent_version'],
                'granted_at' => $row['granted_at'],
                'revoked_at' => $row['revoked_at'],
                'updated_at' => $row['updated_at']
            ];
        }

        return $defaultConsents;
    }

    /**
     * Update or grant patient consent.
     */
    public static function updateConsent(
        int $patientUserId,
        string $consentType,
        bool $isGranted,
        string $consentVersion = '1.0.0',
        ?string $ipAddress = null
    ): bool {
        $pdo = Database::getConnection();

        $grantedAt = $isGranted ? date('Y-m-d H:i:s') : null;
        $revokedAt = !$isGranted ? date('Y-m-d H:i:s') : null;

        $sql = "INSERT INTO consents (
            patient_user_id,
            consent_type,
            consent_version,
            is_granted,
            granted_at,
            revoked_at,
            ip_address
        ) VALUES (
            :puid,
            :type,
            :ver,
            :granted,
            :granted_at,
            :revoked_at,
            :ip
        ) ON DUPLICATE KEY UPDATE
            consent_version = VALUES(consent_version),
            is_granted = VALUES(is_granted),
            granted_at = CASE WHEN VALUES(is_granted) = 1 THEN NOW() ELSE granted_at END,
            revoked_at = CASE WHEN VALUES(is_granted) = 0 THEN NOW() ELSE NULL END,
            ip_address = VALUES(ip_address)";

        $stmt = $pdo->prepare($sql);
        $res = $stmt->execute([
            ':puid' => $patientUserId,
            ':type' => $consentType,
            ':ver' => $consentVersion,
            ':granted' => $isGranted ? 1 : 0,
            ':granted_at' => $grantedAt,
            ':revoked_at' => $revokedAt,
            ':ip' => $ipAddress
        ]);

        if ($res) {
            AuditLog::record(
                $patientUserId,
                'consent',
                $patientUserId,
                $isGranted ? 'grant_consent' : 'revoke_consent',
                "Consent type '{$consentType}' set to " . ($isGranted ? 'granted' : 'revoked'),
                $ipAddress
            );
        }

        return $res;
    }

    /**
     * Check if a specific consent is actively granted.
     */
    public static function isGranted(int $patientUserId, string $consentType): bool
    {
        $consent = self::getConsent($patientUserId, $consentType);
        return $consent !== null && (int)$consent['is_granted'] === 1 && empty($consent['revoked_at']);
    }
}
