<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250413120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add next payment date to subscriptions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscriptions ADD next_payment_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscriptions DROP next_payment_at');
    }
}
