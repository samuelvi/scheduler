<?php

namespace App\Database;

use Doctrine\DBAL\Connection as DBALConnection;

/**
 * Manages database connections using PDO for optimal performance.
 *
 * This class wraps Doctrine's DBAL connection to provide direct PDO access
 * while leveraging Symfony's connection pool management.
 */
class DatabaseConnection
{
    private ?\PDO $pdo = null;
    private ?string $platform = null;

    public function __construct(
        private DBALConnection $connection
    ) {
    }

    /**
     * Get native PDO connection from Doctrine DBAL.
     * Reuses the same connection (pool management by Symfony).
     *
     * @return \PDO
     */
    public function getPDO(): \PDO
    {
        if ($this->pdo === null) {
            // Get native PDO from Doctrine's wrapped connection
            $this->pdo = $this->connection->getNativeConnection();

            // Ensure it's really a PDO instance (Doctrine wraps it)
            if ($this->pdo instanceof \PDO) {
                // Configure PDO for better error handling
                $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            } else {
                // If it's a Doctrine wrapper, unwrap it
                $this->pdo = $this->connection->getWrappedConnection();
            }
        }

        return $this->pdo;
    }

    /**
     * Detect the database platform.
     *
     * @return string 'postgresql', 'mysql', or 'mariadb'
     */
    public function getPlatform(): string
    {
        if ($this->platform === null) {
            $platformName = $this->connection->getDatabasePlatform()->getName();

            $this->platform = match (true) {
                str_contains($platformName, 'postgres') => 'postgresql',
                str_contains($platformName, 'mysql') => 'mysql',
                default => 'mysql', // Fallback to MySQL/MariaDB
            };
        }

        return $this->platform;
    }

    /**
     * Prepare a SQL statement.
     *
     * @param string $sql
     * @return \PDOStatement
     */
    public function prepare(string $sql): \PDOStatement
    {
        return $this->getPDO()->prepare($sql);
    }

    /**
     * Execute a query and return a statement.
     *
     * @param string $sql
     * @param array $params
     * @return \PDOStatement
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->prepare($sql);
        $stmt->execute($params);
        return $stmt;
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
        $stmt = $this->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
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
        $stmt = $this->query($sql, $params);
        return $stmt->fetchColumn();
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
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
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
        $stmt = $this->query($sql, $params);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Begin a transaction.
     */
    public function beginTransaction(): bool
    {
        return $this->getPDO()->beginTransaction();
    }

    /**
     * Commit a transaction.
     */
    public function commit(): bool
    {
        return $this->getPDO()->commit();
    }

    /**
     * Rollback a transaction.
     */
    public function rollBack(): bool
    {
        return $this->getPDO()->rollBack();
    }

    /**
     * Get the last insert ID.
     */
    public function lastInsertId(): string
    {
        return $this->getPDO()->lastInsertId();
    }
}
