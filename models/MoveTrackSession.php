<?php
declare(strict_types=1);

/**
 * SMA-Move — MoveTrack Session Metadata Model
 *
 * Privacy Principle: Minimal metadata only.
 * NO raw camera frames, video clips, or biometrics are stored or processed on the server.
 * All pose analysis runs exclusively on-device.
 */

namespace SmaMove\Models;

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/Consent.php';

use SmaMove\Config\Database;
use SmaMove\Models\Consent;
use PDO;
use RuntimeException;

class MoveTrackSession
{
    public const NON_DIAGNOSTIC_DISCLAIMER = "On-device movement analysis is for exploratory posture and mobility guidance only. Not intended for clinical diagnosis, disease progression staging, or unsupervised therapy alterations.";

    /**
     * Start a new MoveTrack session (minimal metadata only).
     * Enforces active camera_tracking consent!
     */
    public static function startSession(int $patientUserId, array $data): array
    {
        // 1. Strictly verify active camera tracking consent
        if (!Consent::isGranted($patientUserId, Consent::TYPE_CAMERA)) {
            throw new RuntimeException("MoveTrack session cannot be initiated without active camera tracking consent.", 403);
        }

        $pdo = Database::getConnection();

        $sessionUuid = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );

        $sessionType = $data['session_type'] ?? 'Posture Guidance Evaluation';
        $modelVersion = $data['model_version'] ?? 'on-device-v1.0';

        $sql = "INSERT INTO movetrack_sessions (
                    session_uuid,
                    patient_user_id,
                    session_type,
                    session_status,
                    start_time,
                    model_version,
                    tracking_quality_summary
                ) VALUES (
                    :uuid,
                    :puid,
                    :type,
                    'in_progress',
                    NOW(),
                    :model_ver,
                    'Session In Progress'
                )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':uuid' => $sessionUuid,
            ':puid' => $patientUserId,
            ':type' => $sessionType,
            ':model_ver' => $modelVersion
        ]);

        return [
            'session_uuid' => $sessionUuid,
            'session_type' => $sessionType,
            'session_status' => 'in_progress',
            'start_time' => date('Y-m-d H:i:s'),
            'model_version' => $modelVersion,
            'privacy_notice' => 'Zero-video policy: Video processing remains 100% on-device. No images leave your phone.',
            'disclaimer' => self::NON_DIAGNOSTIC_DISCLAIMER
        ];
    }

    /**
     * Complete an in-progress MoveTrack session with summary metrics.
     */
    public static function completeSession(
        string $sessionUuid,
        int $patientUserId,
        array $data
    ): array {
        $pdo = Database::getConnection();

        // Verify session belongs to authenticated patient
        $existing = self::findByUuid($sessionUuid, $patientUserId);
        if (!$existing) {
            throw new RuntimeException("MoveTrack session not found or unauthorized.", 404);
        }

        if ($existing['session_status'] === 'completed') {
            throw new RuntimeException("MoveTrack session has already been finalized.", 400);
        }

        // Numerical bounds validation
        if (!isset($data['duration_seconds']) || filter_var($data['duration_seconds'], FILTER_VALIDATE_INT) === false) {
            throw new RuntimeException("duration_seconds must be an integer between 1 and 86400.", 422);
        }
        $duration = (int)$data['duration_seconds'];
        if ($duration < 1 || $duration > 86400) {
            throw new RuntimeException("duration_seconds must be between 1 and 86400.", 422);
        }

        $consistency = null;
        if (array_key_exists('consistency_percentage', $data) && $data['consistency_percentage'] !== null) {
            if (filter_var($data['consistency_percentage'], FILTER_VALIDATE_INT) === false) {
                throw new RuntimeException("consistency_percentage must be an integer between 0 and 100.", 422);
            }
            $consistency = (int)$data['consistency_percentage'];
            if ($consistency < 0 || $consistency > 100) {
                throw new RuntimeException("consistency_percentage must be between 0 and 100.", 422);
            }
        }

        $reps = 0;
        if (array_key_exists('reps_completed', $data) && $data['reps_completed'] !== null) {
            if (filter_var($data['reps_completed'], FILTER_VALIDATE_INT) === false) {
                throw new RuntimeException("reps_completed must be an integer between 0 and 1000.", 422);
            }
            $reps = (int)$data['reps_completed'];
            if ($reps < 0 || $reps > 1000) {
                throw new RuntimeException("reps_completed must be between 0 and 1000.", 422);
            }
        }

        $quality = trim($data['tracking_quality_summary'] ?? 'Satisfactory Alignment');
        $notes = isset($data['user_notes']) ? trim($data['user_notes']) : null;

        $sql = "UPDATE movetrack_sessions 
                SET end_time = NOW(),
                    duration_seconds = :duration,
                    session_status = 'completed',
                    tracking_quality_summary = :quality,
                    consistency_percentage = :consistency,
                    reps_completed = :reps,
                    user_notes = :notes
                WHERE session_uuid = :uuid AND patient_user_id = :puid";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':duration' => $duration,
            ':quality' => $quality,
            ':consistency' => $consistency,
            ':reps' => $reps,
            ':notes' => $notes,
            ':uuid' => $sessionUuid,
            ':puid' => $patientUserId
        ]);

        $updated = self::findByUuid($sessionUuid, $patientUserId);
        $updated['disclaimer'] = self::NON_DIAGNOSTIC_DISCLAIMER;
        return $updated;
    }

    /**
     * Find session by UUID and patient user ID (IDOR-protected).
     */
    public static function findByUuid(string $sessionUuid, int $patientUserId): ?array
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("SELECT * FROM movetrack_sessions 
                               WHERE session_uuid = :uuid AND patient_user_id = :puid 
                               LIMIT 1");
        $stmt->execute([':uuid' => $sessionUuid, ':puid' => $patientUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $row['id'] = (int)$row['id'];
        $row['patient_user_id'] = (int)$row['patient_user_id'];
        $row['duration_seconds'] = (int)$row['duration_seconds'];
        $row['consistency_percentage'] = $row['consistency_percentage'] !== null ? (int)$row['consistency_percentage'] : null;
        $row['reps_completed'] = (int)$row['reps_completed'];

        return $row;
    }

    /**
     * List MoveTrack session summaries for a patient.
     */
    public static function listByPatient(int $patientUserId, int $limit = 20): array
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("SELECT id, session_uuid, patient_user_id, session_type, session_status,
                                      start_time, end_time, duration_seconds, model_version,
                                      tracking_quality_summary, consistency_percentage, reps_completed,
                                      user_notes, created_at
                               FROM movetrack_sessions
                               WHERE patient_user_id = :puid
                               ORDER BY start_time DESC
                               LIMIT :limit");
        $stmt->bindValue(':puid', $patientUserId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['patient_user_id'] = (int)$row['patient_user_id'];
            $row['duration_seconds'] = (int)$row['duration_seconds'];
            $row['consistency_percentage'] = $row['consistency_percentage'] !== null ? (int)$row['consistency_percentage'] : null;
            $row['reps_completed'] = (int)$row['reps_completed'];
        }
        unset($row);

        return $rows;
    }

    /**
     * Get the latest completed session for a patient.
     */
    public static function getLatest(int $patientUserId): ?array
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("SELECT * FROM movetrack_sessions 
                               WHERE patient_user_id = :puid AND session_status = 'completed'
                               ORDER BY start_time DESC 
                               LIMIT 1");
        $stmt->execute([':puid' => $patientUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $row['id'] = (int)$row['id'];
        $row['patient_user_id'] = (int)$row['patient_user_id'];
        $row['duration_seconds'] = (int)$row['duration_seconds'];
        $row['consistency_percentage'] = $row['consistency_percentage'] !== null ? (int)$row['consistency_percentage'] : null;
        $row['reps_completed'] = (int)$row['reps_completed'];

        return $row;
    }
}
