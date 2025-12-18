<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250327121500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reset password token fields to users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD reset_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD reset_requested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP reset_token');
        $this->addSql('ALTER TABLE users DROP reset_requested_at');
    }
}
