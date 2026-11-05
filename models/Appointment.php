<?php
declare(strict_types=1);

/**
 * SMA-Move — Clinical Appointment Model
 *
 * Manages consultation bookings, statuses, and preparation details.
 */

namespace SmaMove\Models;

require_once dirname(__DIR__) . '/config/database.php';

use SmaMove\Config\Database;
use PDO;

class Appointment
{
    /**
     * Create a new appointment booking.
     */
    public static function create(array $data): int
    {
        $pdo = Database::getConnection();

        $sql = "INSERT INTO appointments (
                    patient_user_id,
                    professional_user_id,
                    specialist_name,
                    specialist_title,
                    appointment_date,
                    appointment_time,
                    session_type,
                    status,
                    meeting_link,
                    preparation_notes
                ) VALUES (
                    :patient_id,
                    :prof_id,
                    :name,
                    :title,
                    :app_date,
                    :app_time,
                    :session_type,
                    :status,
                    :meeting_link,
                    :prep_notes
                )";

        $stmt = $pdo->prepare($sql);
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

        return (int)$pdo->lastInsertId();
    }

    /**
     * Find appointment by ID.
     */
    public static function findById(int $id): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM appointments WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $row['id'] = (int)$row['id'];
        $row['patient_user_id'] = (int)$row['patient_user_id'];
        $row['professional_user_id'] = $row['professional_user_id'] !== null ? (int)$row['professional_user_id'] : null;

        return $row;
    }

    /**
     * List appointments for a patient.
     */
    public static function listByPatient(int $patientUserId, ?string $status = null): array
    {
        $pdo = Database::getConnection();

        $sql = "SELECT * FROM appointments WHERE patient_user_id = :puid";
        $params = [':puid' => $patientUserId];

        if ($status !== null) {
            $sql .= " AND status = :status";
            $params[':status'] = $status;
        }

        $sql .= " ORDER BY appointment_date ASC, appointment_time ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['patient_user_id'] = (int)$row['patient_user_id'];
            $row['professional_user_id'] = $row['professional_user_id'] !== null ? (int)$row['professional_user_id'] : null;
        }
        unset($row);

        return $rows;
    }

    /**
     * List appointments for a healthcare professional.
     */
    public static function listByProfessional(int $professionalUserId, ?string $status = null): array
    {
        $pdo = Database::getConnection();

        $sql = "SELECT a.*, u.full_name AS patient_name, u.email AS patient_email
                FROM appointments a
                JOIN users u ON u.id = a.patient_user_id
                WHERE a.professional_user_id = :prof_id";
        $params = [':prof_id' => $professionalUserId];

        if ($status !== null) {
            $sql .= " AND a.status = :status";
            $params[':status'] = $status;
        }

        $sql .= " ORDER BY a.appointment_date ASC, a.appointment_time ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['patient_user_id'] = (int)$row['patient_user_id'];
            $row['professional_user_id'] = (int)$row['professional_user_id'];
        }
        unset($row);

        return $rows;
    }

    /**
     * Get the next upcoming scheduled appointment for a patient.
     */
    public static function getNextUpcoming(int $patientUserId): ?array
    {
        $pdo = Database::getConnection();

        $sql = "SELECT * FROM appointments 
                WHERE patient_user_id = :puid 
                  AND status = 'scheduled' 
                  AND appointment_date >= CURDATE()
                ORDER BY appointment_date ASC, appointment_time ASC 
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':puid' => $patientUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $row['id'] = (int)$row['id'];
        $row['patient_user_id'] = (int)$row['patient_user_id'];
        $row['professional_user_id'] = $row['professional_user_id'] !== null ? (int)$row['professional_user_id'] : null;

        return $row;
    }

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_RESCHEDULED = 'rescheduled';

    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED
    ];

    public const ALLOWED_TRANSITIONS = [
        self::STATUS_SCHEDULED => [
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
            self::STATUS_RESCHEDULED
        ],
        self::STATUS_RESCHEDULED => [
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
            self::STATUS_RESCHEDULED
        ],
        self::STATUS_COMPLETED => [], // Terminal state
        self::STATUS_CANCELLED => []  // Terminal state
    ];

    /**
     * Check if status transition is allowed under state transition rules.
     */
    public static function canTransition(string $currentStatus, string $newStatus): bool
    {
        $allowed = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];
        return in_array($newStatus, $allowed, true);
    }

    /**
     * Update appointment status with explicit transition validation, locking, and concurrency protection.
     */
    public static function updateStatus(
        int $appointmentId,
        string $newStatus,
        ?string $prepNotes = null
    ): array {
        if (!in_array($newStatus, [self::STATUS_SCHEDULED, self::STATUS_COMPLETED, self::STATUS_CANCELLED, self::STATUS_RESCHEDULED], true)) {
            throw new \InvalidArgumentException("Invalid appointment status: '{$newStatus}'.", 400);
        }

        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        try {
            // Lock the row FOR UPDATE to prevent concurrent race conditions
            $stmt = $pdo->prepare("SELECT id, status, patient_user_id, professional_user_id FROM appointments WHERE id = :id FOR UPDATE");
            $stmt->execute([':id' => $appointmentId]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$current) {
                $pdo->rollBack();
                throw new \RuntimeException("Appointment not found.", 404);
            }

            $currentStatus = $current['status'];

            // Check if appointment is in terminal state
            if (in_array($currentStatus, self::TERMINAL_STATUSES, true)) {
                $pdo->rollBack();
                throw new \RuntimeException("Cannot update appointment. Status '{$currentStatus}' is terminal and cannot be reopened or transitioned.", 400);
            }

            // Check transition policy
            if (!self::canTransition($currentStatus, $newStatus)) {
                $pdo->rollBack();
                throw new \RuntimeException("Invalid status transition: Cannot change appointment from '{$currentStatus}' to '{$newStatus}'.", 400);
            }

            $updateSql = "UPDATE appointments 
                          SET status = :new_status,
                              preparation_notes = COALESCE(:prep_notes, preparation_notes)
                          WHERE id = :id AND status = :current_status";

            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute([
                ':new_status' => $newStatus,
                ':prep_notes' => $prepNotes,
                ':id' => $appointmentId,
                ':current_status' => $currentStatus
            ]);

            if ($updateStmt->rowCount() === 0) {
                $pdo->rollBack();
                throw new \RuntimeException("Conflict: Appointment status was modified concurrently by another process.", 409);
            }

            $pdo->commit();
            return self::findById($appointmentId);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
