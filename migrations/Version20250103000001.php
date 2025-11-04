<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250103000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add worker_id column for fair task distribution';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scheduled_tasks ADD COLUMN worker_id INTEGER NULL');

        $this->addSql('
            CREATE INDEX idx_worker_status
            ON scheduled_tasks (worker_id, status)
        ');
    }

    public function down(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform()->getName();

        if ($platform === 'postgresql') {
            $this->addSql('DROP INDEX idx_worker_status');
        } else {
            // MySQL/MariaDB
            $this->addSql('DROP INDEX idx_worker_status ON scheduled_tasks');
        }

        $this->addSql('ALTER TABLE scheduled_tasks DROP COLUMN worker_id');
    }
}
