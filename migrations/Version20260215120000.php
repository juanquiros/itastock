<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260215120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional email field to customers';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customers ADD email VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customers DROP email');
    }
}
