<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250412120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add billing webhook events table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE billing_webhook_events (id INT AUTO_INCREMENT NOT NULL, event_id VARCHAR(128) DEFAULT NULL, resource_id VARCHAR(128) DEFAULT NULL, payload LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, received_at DATETIME NOT NULL, processed_at DATETIME DEFAULT NULL, INDEX idx_billing_webhook_event_event_id (event_id), INDEX idx_billing_webhook_event_resource_id (resource_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE billing_webhook_events');
    }
}
