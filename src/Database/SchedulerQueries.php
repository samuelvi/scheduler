<?php

namespace App\Database;

use App\Entity\ScheduledTask;

/**
 * All SQL queries for the scheduler system.
 *
 * This class contains pure SQL queries separated from business logic.
 * All queries use standard SQL syntax for database compatibility.
 */
class SchedulerQueries
{
    /**
     * Database-specific lock acquisition queries.
     */
    public const LOCK_ACQUIRE_POSTGRESQL = "SELECT pg_try_advisory_lock(?)";
    public const LOCK_ACQUIRE_MYSQL = "SELECT GET_LOCK(?, ?)";

    /**
     * Database-specific lock release queries.
     */
    public const LOCK_RELEASE_POSTGRESQL = "SELECT pg_advisory_unlock(?)";
    public const LOCK_RELEASE_MYSQL = "SELECT RELEASE_LOCK(?)";

    /**
     * Count pending tasks ready for processing.
     */
    public const COUNT_PENDING_TASKS = "
        SELECT COUNT(*)
        FROM scheduled_tasks
        WHERE scheduled_at <= CURRENT_TIMESTAMP
          AND status = :pending
          AND attempts < max_attempts
    ";

    /**
     * Select task IDs for worker assignment (with OFFSET and LIMIT).
     */
    public const SELECT_TASK_IDS_FOR_ASSIGNMENT = "
        SELECT id
        FROM scheduled_tasks
        WHERE scheduled_at <= CURRENT_TIMESTAMP
          AND status = :pending
          AND attempts < max_attempts
        ORDER BY scheduled_at ASC, id ASC
        LIMIT :limit OFFSET :offset
    ";

    /**
     * Update tasks to assign them to a worker.
     */
    public const ASSIGN_TASKS_TO_WORKER = "
        UPDATE scheduled_tasks
        SET status = :processing,
            worker_id = :worker_id,
            attempts = attempts + 1,
            updated_at = CURRENT_TIMESTAMP
        WHERE id IN (
            SELECT id FROM (
                SELECT id
                FROM scheduled_tasks
                WHERE scheduled_at <= CURRENT_TIMESTAMP
                  AND status = :pending
                  AND attempts < max_attempts
                ORDER BY scheduled_at ASC, id ASC
                LIMIT :limit OFFSET :offset
            ) AS subquery
        )
    ";

    /**
     * Fetch tasks assigned to a specific worker.
     */
    public const FETCH_ASSIGNED_TASKS = "
        SELECT *
        FROM scheduled_tasks
        WHERE worker_id = :worker_id
          AND status = :processing
          AND updated_at >= :time_threshold
        ORDER BY scheduled_at ASC
    ";

    /**
     * Select tasks for locking with FOR UPDATE SKIP LOCKED.
     */
    public const SELECT_TASKS_FOR_LOCKING = "
        SELECT *
        FROM scheduled_tasks
        WHERE scheduled_at <= CURRENT_TIMESTAMP
          AND status = :status
          AND attempts < max_attempts
        ORDER BY scheduled_at ASC, id ASC
        LIMIT :limit
        FOR UPDATE SKIP LOCKED
    ";

    /**
     * Mark tasks as processing (bulk update by IDs).
     * Note: :ids parameter must be bound as array using proper binding.
     */
    public const MARK_TASKS_AS_PROCESSING = "
        UPDATE scheduled_tasks
        SET status = :processing,
            attempts = attempts + 1,
            updated_at = CURRENT_TIMESTAMP
        WHERE id IN (:ids)
    ";

    /**
     * Reset stuck tasks back to pending.
     */
    public const RESET_STUCK_TASKS = "
        UPDATE scheduled_tasks
        SET status = :pending
        WHERE status = :processing
          AND updated_at < :timeout
          AND attempts < max_attempts
    ";

    /**
     * Get statistics grouped by status.
     */
    public const GET_STATISTICS = "
        SELECT
            status,
            COUNT(*) as count,
            MIN(scheduled_at) as oldest_scheduled,
            MAX(scheduled_at) as newest_scheduled
        FROM scheduled_tasks
        GROUP BY status
    ";

    /**
     * Count overdue tasks.
     */
    public const COUNT_OVERDUE_TASKS = "
        SELECT COUNT(*)
        FROM scheduled_tasks
        WHERE scheduled_at <= :now
          AND status = :pending
    ";

    /**
     * Delete old completed/failed tasks.
     */
    public const DELETE_OLD_TASKS = "
        DELETE FROM scheduled_tasks
        WHERE status IN (:completed, :failed)
          AND processed_at < :threshold
    ";

    /**
     * Update task status to completed.
     */
    public const MARK_TASK_COMPLETED = "
        UPDATE scheduled_tasks
        SET status = :completed,
            processed_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ";

    /**
     * Update task status to failed.
     */
    public const MARK_TASK_FAILED = "
        UPDATE scheduled_tasks
        SET status = :failed,
            processed_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP,
            last_error = :error
        WHERE id = :id
    ";

    /**
     * Reset task to pending for retry.
     */
    public const RESET_TASK_FOR_RETRY = "
        UPDATE scheduled_tasks
        SET status = :pending,
            last_error = :error,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ";

    /**
     * Get task status constants.
     */
    public static function getStatusPending(): string
    {
        return ScheduledTask::STATUS_PENDING;
    }

    public static function getStatusProcessing(): string
    {
        return ScheduledTask::STATUS_PROCESSING;
    }

    public static function getStatusCompleted(): string
    {
        return ScheduledTask::STATUS_COMPLETED;
    }

    public static function getStatusFailed(): string
    {
        return ScheduledTask::STATUS_FAILED;
    }
}
