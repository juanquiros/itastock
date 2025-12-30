<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250414120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add grace period days to subscriptions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscriptions ADD grace_period_days INT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscriptions DROP grace_period_days');
    }
}
