<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260115126000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add email failed timestamp to purchase orders';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_orders ADD email_failed_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_orders DROP email_failed_at');
    }
}
