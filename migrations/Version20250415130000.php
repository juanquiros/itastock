<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250415130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add business name to leads';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE leads ADD business_name VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE leads DROP business_name');
    }
}
