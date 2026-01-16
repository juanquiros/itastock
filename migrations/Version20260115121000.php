<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260115121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add meta image path to public pages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public_pages ADD meta_image_path LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public_pages DROP meta_image_path');
    }
}
