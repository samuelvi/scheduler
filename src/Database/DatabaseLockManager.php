<?php

namespace App\Database;

/**
 * Manages database-level locks across different database platforms.
 *
 * This class provides a unified interface for acquiring and releasing locks
 * using the native locking mechanisms of each database system:
 * - PostgreSQL: Advisory locks (session-level, integer-based)
 * - MySQL/MariaDB: Named locks (connection-level, string-based)
 * - SQLite: No-op (single-threaded, no locking needed)
 */
class DatabaseLockManager
{
    /**
     * PostgreSQL advisory lock acquisition query.
     * Uses pg_try_advisory_lock which is non-blocking.
     */
    private const LOCK_ACQUIRE_POSTGRESQL = "SELECT pg_try_advisory_lock(?)";

    /**
     * MySQL/MariaDB named lock acquisition query.
     * Uses GET_LOCK with configurable timeout.
     */
    private const LOCK_ACQUIRE_MYSQL = "SELECT GET_LOCK(?, ?)";

    /**
     * PostgreSQL advisory lock release query.
     */
    private const LOCK_RELEASE_POSTGRESQL = "SELECT pg_advisory_unlock(?)";

    /**
     * MySQL/MariaDB named lock release query.
     */
    private const LOCK_RELEASE_MYSQL = "SELECT RELEASE_LOCK(?)";

    public function __construct(
        private DatabaseConnection $connection
    ) {
    }

    /**
     * Acquire a database lock using the appropriate method for the current database.
     *
     * @param string $lockName Lock identifier (will be hashed for PostgreSQL)
     * @param int $timeout Timeout in seconds (0 = non-blocking)
     * @return bool True if lock was acquired
     */
    public function acquireLock(string $lockName, int $timeout = 0): bool
    {
        $platform = $this->connection->getPlatform();

        if ($platform === 'sqlite') {
            // SQLite: No locking support needed for single-threaded tests
            return true;
        }

        if ($platform === 'postgresql') {
            // PostgreSQL: Use advisory locks (integer hash key)
            $lockKey = crc32($lockName);
            return (bool) $this->connection->fetchOne(self::LOCK_ACQUIRE_POSTGRESQL, [$lockKey]);
        }

        // MySQL/MariaDB: Use GET_LOCK with named locks
        return (bool) $this->connection->fetchOne(self::LOCK_ACQUIRE_MYSQL, [$lockName, $timeout]);
    }

    /**
     * Release a database lock using the appropriate method for the current database.
     *
     * @param string $lockName Lock identifier (must match the name used in acquireLock)
     * @return bool True if lock was released
     */
    public function releaseLock(string $lockName): bool
    {
        $platform = $this->connection->getPlatform();

        if ($platform === 'sqlite') {
            // SQLite: No locking support needed for single-threaded tests
            return true;
        }

        if ($platform === 'postgresql') {
            // PostgreSQL: Release advisory lock
            $lockKey = crc32($lockName);
            return (bool) $this->connection->fetchOne(self::LOCK_RELEASE_POSTGRESQL, [$lockKey]);
        }

        // MySQL/MariaDB: Release named lock
        return (bool) $this->connection->fetchOne(self::LOCK_RELEASE_MYSQL, [$lockName]);
    }

    /**
     * Execute a callback within a database lock.
     * Automatically handles lock acquisition and release.
     *
     * @param string $lockName Lock identifier
     * @param callable $callback Function to execute while holding the lock
     * @param int $timeout Timeout in seconds (0 = non-blocking)
     * @return mixed Returns the result of the callback
     * @throws \RuntimeException If lock cannot be acquired
     */
    public function withLock(string $lockName, callable $callback, int $timeout = 0): mixed
    {
        if (!$this->acquireLock($lockName, $timeout)) {
            throw new \RuntimeException("Could not acquire lock: {$lockName}");
        }

        try {
            return $callback();
        } finally {
            $this->releaseLock($lockName);
        }
    }
}
