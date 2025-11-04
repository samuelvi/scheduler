<?php

namespace App\Database;

use Doctrine\DBAL\Connection as DBALConnection;

/**
 * Manages database connections with efficient query execution.
 *
 * This class wraps Doctrine's DBAL connection to provide a clean interface
 * while leveraging Symfony's connection pool management.
 *
 * Note: We use Doctrine DBAL directly instead of extracting PDO for better
 * compatibility across different environments and Doctrine versions.
 */
class DatabaseConnection
{
    private ?string $platform = null;

    public function __construct(
        private DBALConnection $connection
    ) {
    }

    /**
     * Detect the database platform.
     *
     * @return string 'postgresql', 'mysql', 'mariadb', or 'sqlite'
     */
    public function getPlatform(): string
    {
        if ($this->platform === null) {
            $platformName = $this->connection->getDatabasePlatform()->getName();

            $this->platform = match (true) {
                str_contains($platformName, 'postgres') => 'postgresql',
                str_contains($platformName, 'mysql') => 'mysql',
                str_contains($platformName, 'sqlite') => 'sqlite',
                default => 'mysql', // Fallback to MySQL/MariaDB
            };
        }

        return $this->platform;
    }

    /**
     * Prepare a SQL statement using Doctrine DBAL.
     *
     * @param string $sql
     * @return \Doctrine\DBAL\Statement
     */
    public function prepare(string $sql): \Doctrine\DBAL\Statement
    {
        return $this->connection->prepare($sql);
    }

    /**
     * Execute a statement (INSERT, UPDATE, DELETE).
     *
     * @param string $sql
     * @param array $params
     * @return int Number of affected rows
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->connection->executeStatement($sql, $params);
    }

    /**
     * Fetch a single value.
     *
     * @param string $sql
     * @param array $params
     * @return mixed
     */
    public function fetchOne(string $sql, array $params = []): mixed
    {
        return $this->connection->fetchOne($sql, $params);
    }

    /**
     * Fetch all rows as associative arrays.
     *
     * @param string $sql
     * @param array $params
     * @return array
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->connection->fetchAllAssociative($sql, $params);
    }

    /**
     * Fetch a single row as associative array.
     *
     * @param string $sql
     * @param array $params
     * @return array|false
     */
    public function fetchRow(string $sql, array $params = []): array|false
    {
        return $this->connection->fetchAssociative($sql, $params);
    }

    /**
     * Begin a transaction.
     */
    public function beginTransaction(): void
    {
        $this->connection->beginTransaction();
    }

    /**
     * Commit a transaction.
     */
    public function commit(): void
    {
        $this->connection->commit();
    }

    /**
     * Rollback a transaction.
     */
    public function rollBack(): void
    {
        $this->connection->rollBack();
    }

    /**
     * Get the last insert ID.
     */
    public function lastInsertId(): string
    {
        return $this->connection->lastInsertId();
    }
}
