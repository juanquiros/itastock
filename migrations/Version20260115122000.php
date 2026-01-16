<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260115122000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add whatsapp link to platform settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE platform_settings ADD whatsapp_link LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE platform_settings DROP whatsapp_link');
    }
}
