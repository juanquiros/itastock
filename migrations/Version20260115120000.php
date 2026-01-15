<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260115120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add label image path on business';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business ADD label_image_path LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business DROP label_image_path');
    }
}
