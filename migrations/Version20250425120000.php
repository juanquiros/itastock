<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250425120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ticket customization, POS numbering, and remito sequences';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business ADD ticket_header_lines LONGTEXT DEFAULT NULL, ADD ticket_footer_lines LONGTEXT DEFAULT NULL, ADD ticket_header_image_path LONGTEXT DEFAULT NULL, ADD ticket_footer_image_path LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD pos_number INT DEFAULT NULL');
        $this->addSql('ALTER TABLE sales ADD pos_number INT DEFAULT NULL, ADD pos_sequence INT DEFAULT NULL');

        $this->addSql("UPDATE users SET pos_number = 1 WHERE roles LIKE '%ROLE_ADMIN%'");
        $this->addSql('UPDATE sales s SET s.pos_number = COALESCE((SELECT u.pos_number FROM users u WHERE u.id = s.created_by_id), 1)');
        $this->addSql('UPDATE sales SET pos_sequence = id WHERE pos_sequence IS NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business DROP ticket_header_lines, DROP ticket_footer_lines, DROP ticket_header_image_path, DROP ticket_footer_image_path');
        $this->addSql('ALTER TABLE users DROP pos_number');
        $this->addSql('ALTER TABLE sales DROP pos_number, DROP pos_sequence');
    }
}
