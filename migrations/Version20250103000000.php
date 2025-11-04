<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250103000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create scheduled_tasks table with indexes for efficient batch processing';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform()->getName();

        if ($platform === 'postgresql') {
            $this->addSql('
                CREATE TABLE scheduled_tasks (
                    id SERIAL PRIMARY KEY,
                    use_case VARCHAR(255) NOT NULL,
                    payload JSONB NOT NULL,
                    scheduled_at TIMESTAMP NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT \'pending\',
                    attempts INTEGER NOT NULL DEFAULT 0,
                    max_attempts INTEGER NOT NULL DEFAULT 3,
                    last_error TEXT,
                    processed_at TIMESTAMP,
                    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
                    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
                )
            ');
        } else {
            // MySQL/MariaDB
            $this->addSql('
                CREATE TABLE scheduled_tasks (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    use_case VARCHAR(255) NOT NULL,
                    payload JSON NOT NULL,
                    scheduled_at TIMESTAMP NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT \'pending\',
                    attempts INT NOT NULL DEFAULT 0,
                    max_attempts INT NOT NULL DEFAULT 3,
                    last_error TEXT,
                    processed_at TIMESTAMP NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )
            ');
        }

        // Critical index for the main query: scheduled_at + status
        $this->addSql('
            CREATE INDEX idx_scheduled_status
            ON scheduled_tasks (scheduled_at, status)
        ');

        // Index for recovery queries: status + attempts
        $this->addSql('
            CREATE INDEX idx_status_attempts
            ON scheduled_tasks (status, attempts)
        ');

        // Index for checking stuck tasks
        $this->addSql('
            CREATE INDEX idx_status_updated
            ON scheduled_tasks (status, updated_at)
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE scheduled_tasks');
    }
}
