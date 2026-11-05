<?php
declare(strict_types=1);

/**
 * SMA-Move — Care Task & Protocol Model
 *
 * Manages patient care plan tasks, multi-step regimens, and daily completion records.
 */

namespace SmaMove\Models;

require_once dirname(__DIR__) . '/config/database.php';

use SmaMove\Config\Database;
use PDO;

class CareTask
{
    /**
     * Retrieve all active care tasks for a patient on a specific date, including steps and completion state.
     */
    public static function findTasksForPatient(int $patientUserId, string $date): array
    {
        $pdo = Database::getConnection();

        // 1. Fetch tasks
        $sql = "SELECT ct.id,
                       ct.patient_user_id,
                       ct.title,
                       ct.description,
                       ct.category,
                       ct.duration_text,
                       ct.scheduled_time,
                       ct.prescribed_by,
                       ct.is_active,
                       COALESCE(ptc.is_completed, 0) AS is_completed,
                       ptc.completed_at,
                       ptc.notes AS completion_notes
                FROM care_tasks ct
                LEFT JOIN patient_task_completions ptc
                       ON ptc.task_id = ct.id 
                      AND ptc.patient_user_id = :puid_comp 
                      AND ptc.completed_date = :cdate
                WHERE (ct.patient_user_id = :puid OR ct.patient_user_id IS NULL)
                  AND ct.is_active = 1
                ORDER BY ct.scheduled_time ASC, ct.id ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':puid' => $patientUserId,
            ':puid_comp' => $patientUserId,
            ':cdate' => $date
        ]);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($tasks)) {
            return [];
        }

        // 2. Fetch steps for all tasks in a single query
        $taskIds = array_column($tasks, 'id');
        $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
        
        $stepsStmt = $pdo->prepare("SELECT id, task_id, step_number, title, instruction 
                                    FROM care_task_steps 
                                    WHERE task_id IN ($placeholders) 
                                    ORDER BY task_id ASC, step_number ASC");
        $stepsStmt->execute($taskIds);
        $allSteps = $stepsStmt->fetchAll(PDO::FETCH_ASSOC);

        $stepsByTask = [];
        foreach ($allSteps as $step) {
            $tId = (int)$step['task_id'];
            if (!isset($stepsByTask[$tId])) {
                $stepsByTask[$tId] = [];
            }
            $stepsByTask[$tId][] = [
                'id' => (int)$step['id'],
                'step_number' => (int)$step['step_number'],
                'title' => $step['title'],
                'instruction' => $step['instruction']
            ];
        }

        // 3. Assemble response
        foreach ($tasks as &$task) {
            $tId = (int)$task['id'];
            $task['id'] = $tId;
            $task['patient_user_id'] = $task['patient_user_id'] !== null ? (int)$task['patient_user_id'] : null;
            $task['is_active'] = (bool)$task['is_active'];
            $task['is_completed'] = (bool)$task['is_completed'];
            $task['steps'] = $stepsByTask[$tId] ?? [];
        }
        unset($task);

        return $tasks;
    }

    /**
     * Get detailed task information by ID.
     */
    public static function findById(int $taskId, int $patientUserId, string $date): ?array
    {
        $pdo = Database::getConnection();

        $sql = "SELECT ct.id,
                       ct.patient_user_id,
                       ct.title,
                       ct.description,
                       ct.category,
                       ct.duration_text,
                       ct.scheduled_time,
                       ct.prescribed_by,
                       ct.is_active,
                       COALESCE(ptc.is_completed, 0) AS is_completed,
                       ptc.completed_at,
                       ptc.notes AS completion_notes
                FROM care_tasks ct
                LEFT JOIN patient_task_completions ptc
                       ON ptc.task_id = ct.id 
                      AND ptc.patient_user_id = :puid_comp 
                      AND ptc.completed_date = :cdate
                WHERE ct.id = :tid 
                  AND (ct.patient_user_id = :puid OR ct.patient_user_id IS NULL)
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':tid' => $taskId,
            ':puid' => $patientUserId,
            ':puid_comp' => $patientUserId,
            ':cdate' => $date
        ]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$task) {
            return null;
        }

        // Fetch steps
        $stepsStmt = $pdo->prepare("SELECT id, step_number, title, instruction 
                                    FROM care_task_steps 
                                    WHERE task_id = :tid 
                                    ORDER BY step_number ASC");
        $stepsStmt->execute([':tid' => $taskId]);
        $steps = $stepsStmt->fetchAll(PDO::FETCH_ASSOC);

        $task['id'] = (int)$task['id'];
        $task['patient_user_id'] = $task['patient_user_id'] !== null ? (int)$task['patient_user_id'] : null;
        $task['is_active'] = (bool)$task['is_active'];
        $task['is_completed'] = (bool)$task['is_completed'];
        $task['steps'] = array_map(function ($s) {
            return [
                'id' => (int)$s['id'],
                'step_number' => (int)$s['step_number'],
                'title' => $s['title'],
                'instruction' => $s['instruction']
            ];
        }, $steps);

        return $task;
    }

    /**
     * Create a new care task with multi-step protocol in a database transaction.
     */
    public static function createTask(int $patientUserId, array $data, array $steps = []): int
    {
        $pdo = Database::getConnection();
        $pdo->beginTransaction();

        try {
            $sql = "INSERT INTO care_tasks (
                        patient_user_id,
                        title,
                        description,
                        category,
                        duration_text,
                        scheduled_time,
                        prescribed_by,
                        is_active
                    ) VALUES (
                        :puid,
                        :title,
                        :description,
                        :category,
                        :duration_text,
                        :scheduled_time,
                        :prescribed_by,
                        1
                    )";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':puid' => $patientUserId,
                ':title' => trim($data['title']),
                ':description' => trim($data['description']),
                ':category' => $data['category'],
                ':duration_text' => $data['duration_text'] ?? '15 mins',
                ':scheduled_time' => $data['scheduled_time'] ?? '09:00 AM',
                ':prescribed_by' => $data['prescribed_by'] ?? 'Clinician Prescribed'
            ]);

            $taskId = (int)$pdo->lastInsertId();

            if (!empty($steps)) {
                $stepSql = "INSERT INTO care_task_steps (task_id, step_number, title, instruction) 
                            VALUES (:tid, :step_num, :title, :instruction)";
                $stepStmt = $pdo->prepare($stepSql);

                $stepNum = 1;
                foreach ($steps as $step) {
                    $stepStmt->execute([
                        ':tid' => $taskId,
                        ':step_num' => $step['step_number'] ?? $stepNum,
                        ':title' => trim($step['title']),
                        ':instruction' => trim($step['instruction'])
                    ]);
                    $stepNum++;
                }
            }

            $pdo->commit();
            return $taskId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Record or toggle task completion for a patient on a date.
     */
    public static function setCompletion(
        int $taskId,
        int $patientUserId,
        string $date,
        bool $isCompleted,
        ?string $notes = null
    ): bool {
        $pdo = Database::getConnection();

        $sql = "INSERT INTO patient_task_completions (
                    patient_user_id,
                    task_id,
                    completed_date,
                    is_completed,
                    completed_at,
                    notes
                ) VALUES (
                    :puid,
                    :tid,
                    :cdate,
                    :is_comp,
                    NOW(),
                    :notes
                ) ON DUPLICATE KEY UPDATE
                    is_completed = VALUES(is_completed),
                    completed_at = CASE WHEN VALUES(is_completed) = 1 THEN NOW() ELSE completed_at END,
                    notes = VALUES(notes)";

        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            ':puid' => $patientUserId,
            ':tid' => $taskId,
            ':cdate' => $date,
            ':is_comp' => $isCompleted ? 1 : 0,
            ':notes' => $notes
        ]);
    }

    /**
     * Get completion summary metrics for a date.
     */
    public static function getSummaryForDate(int $patientUserId, string $date): array
    {
        $tasks = self::findTasksForPatient($patientUserId, $date);
        $total = count($tasks);
        $completed = 0;

        foreach ($tasks as $t) {
            if (!empty($t['is_completed'])) {
                $completed++;
            }
        }

        $progressPercent = $total > 0 ? (int)round(($completed / $total) * 100) : 0;

        return [
            'total_tasks' => $total,
            'completed_tasks' => $completed,
            'progress_percent' => $progressPercent,
            'date' => $date
        ];
    }
}
