<?php
declare(strict_types=1);

/**
 * SMA-Move — Permission & Verification Audit Log Model
 */

namespace SmaMove\Models;

require_once dirname(__DIR__) . '/config/database.php';

use SmaMove\Config\Database;
use PDO;

class AuditLog
{
    /**
     * Record a sensitive authorization, consent, or credential change.
     */
    public static function record(
        int $actorUserId,
        string $targetType,
        int $targetId,
        string $action,
        ?string $details = null,
        ?string $ipAddress = null
    ): void {
        try {
            $pdo = Database::getConnection();
            $sql = "INSERT INTO permission_audit_logs (
                actor_user_id,
                target_type,
                target_id,
                action,
                details,
                ip_address
            ) VALUES (
                :actor,
                :type,
                :target,
                :action,
                :details,
                :ip
            )";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':actor' => $actorUserId,
                ':type' => $targetType,
                ':target' => $targetId,
                ':action' => $action,
                ':details' => $details,
                ':ip' => $ipAddress ? substr($ipAddress, 0, 45) : null
            ]);
        } catch (\Throwable $e) {
            // Log silently to error log to never crash transaction for auditing
            error_log("[AuditLog Error] " . $e->getMessage());
        }
    }
}
