<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250420120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add barcode scan sound path to business';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business ADD barcode_scan_sound_path LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business DROP barcode_scan_sound_path');
    }
}
