<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250420123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move barcode scan sound to global platform settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE platform_settings (id INT AUTO_INCREMENT NOT NULL, barcode_scan_sound_path LONGTEXT DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE business DROP barcode_scan_sound_path');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business ADD barcode_scan_sound_path LONGTEXT DEFAULT NULL');
        $this->addSql('DROP TABLE platform_settings');
    }
}
