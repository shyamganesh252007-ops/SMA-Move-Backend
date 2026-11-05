<?php
declare(strict_types=1);

/**
 * SMA-Move — Wellbeing Journal Model
 *
 * Manages daily patient check-ins: mood, comfort, fatigue, and assistance requests.
 */

namespace SmaMove\Models;

require_once dirname(__DIR__) . '/config/database.php';

use SmaMove\Config\Database;
use PDO;

class WellbeingJournal
{
    /**
     * Create a new wellbeing journal check-in.
     */
    public static function create(int $patientUserId, array $data): int
    {
        $pdo = Database::getConnection();

        $sql = "INSERT INTO wellbeing_journal_entries (
                    patient_user_id,
                    mood,
                    comfort_level,
                    fatigue_level,
                    mobility_assistance_needed,
                    notes,
                    symptom_notes,
                    entry_timestamp
                ) VALUES (
                    :puid,
                    :mood,
                    :comfort_level,
                    :fatigue_level,
                    :mobility_assistance,
                    :notes,
                    :symptom_notes,
                    NOW()
                )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':puid' => $patientUserId,
            ':mood' => trim($data['mood']),
            ':comfort_level' => trim($data['comfort_level']),
            ':fatigue_level' => $data['fatigue_level'] ?? 'Mild',
            ':mobility_assistance' => !empty($data['mobility_assistance_needed']) ? 1 : 0,
            ':notes' => isset($data['notes']) ? trim($data['notes']) : null,
            ':symptom_notes' => isset($data['symptom_notes']) ? trim($data['symptom_notes']) : null
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * List wellbeing entries for a patient.
     */
    public static function listByPatient(int $patientUserId, int $limit = 30, int $offset = 0): array
    {
        $pdo = Database::getConnection();

        $sql = "SELECT id,
                       patient_user_id,
                       mood,
                       comfort_level,
                       fatigue_level,
                       mobility_assistance_needed,
                       notes,
                       symptom_notes,
                       entry_timestamp,
                       created_at
                FROM wellbeing_journal_entries
                WHERE patient_user_id = :puid
                ORDER BY entry_timestamp DESC, id DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':puid', $patientUserId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['patient_user_id'] = (int)$row['patient_user_id'];
            $row['mobility_assistance_needed'] = (bool)$row['mobility_assistance_needed'];
        }
        unset($row);

        return $rows;
    }

    /**
     * Get the latest check-in entry for a patient.
     */
    public static function getLatest(int $patientUserId): ?array
    {
        $pdo = Database::getConnection();

        $sql = "SELECT id,
                       patient_user_id,
                       mood,
                       comfort_level,
                       fatigue_level,
                       mobility_assistance_needed,
                       notes,
                       symptom_notes,
                       entry_timestamp,
                       created_at
                FROM wellbeing_journal_entries
                WHERE patient_user_id = :puid
                ORDER BY entry_timestamp DESC, id DESC
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':puid' => $patientUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $row['id'] = (int)$row['id'];
        $row['patient_user_id'] = (int)$row['patient_user_id'];
        $row['mobility_assistance_needed'] = (bool)$row['mobility_assistance_needed'];

        return $row;
    }

    /**
     * Check if a patient has submitted a check-in today.
     */
    public static function hasCheckedInToday(int $patientUserId): bool
    {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("SELECT 1 FROM wellbeing_journal_entries 
                               WHERE patient_user_id = :puid 
                                 AND DATE(entry_timestamp) = CURDATE() 
                               LIMIT 1");
        $stmt->execute([':puid' => $patientUserId]);
        return (bool)$stmt->fetchColumn();
    }
}
