<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260214120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user ticket paper size preference';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD ticket_paper_size VARCHAR(20) NOT NULL DEFAULT 'A4'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP ticket_paper_size');
    }
}
