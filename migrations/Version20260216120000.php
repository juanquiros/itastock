<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260216120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add allow_negative_stock flag to business';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business ADD allow_negative_stock TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business DROP allow_negative_stock');
    }
}
