<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250416121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add external reference fields for Mercado Pago preapprovals';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscriptions ADD external_reference VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE pending_subscription_changes ADD external_reference VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pending_subscription_changes DROP external_reference');
        $this->addSql('ALTER TABLE subscriptions DROP external_reference');
    }
}
