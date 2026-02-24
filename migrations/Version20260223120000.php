<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260223120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add quotation tables and quotation settings fields in businesses';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE businesses ADD quotation_header_lines LONGTEXT DEFAULT NULL, ADD quotation_footer_lines LONGTEXT DEFAULT NULL, ADD quotation_header_image_path LONGTEXT DEFAULT NULL, ADD quotation_footer_image_path LONGTEXT DEFAULT NULL');
        $this->addSql('CREATE TABLE quotations (id INT AUTO_INCREMENT NOT NULL, business_id INT NOT NULL, created_by_id INT DEFAULT NULL, customer_id INT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", price_list_id_used INT DEFAULT NULL, price_list_name_used VARCHAR(255) DEFAULT NULL, subtotal NUMERIC(12, 2) NOT NULL, total NUMERIC(12, 2) NOT NULL, status VARCHAR(16) NOT NULL, notes LONGTEXT DEFAULT NULL, INDEX IDX_8B8A21118902B4B (business_id), INDEX IDX_8B8A211B03A8386 (created_by_id), INDEX IDX_8B8A2119395C3F3 (customer_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE quotation_items (id INT AUTO_INCREMENT NOT NULL, quotation_id INT NOT NULL, product_id INT DEFAULT NULL, description LONGTEXT NOT NULL, qty NUMERIC(12, 3) NOT NULL, unit_price NUMERIC(12, 2) NOT NULL, line_subtotal NUMERIC(12, 2) NOT NULL, line_discount NUMERIC(12, 2) NOT NULL, line_total NUMERIC(12, 2) NOT NULL, iva_rate NUMERIC(5, 2) DEFAULT NULL, INDEX IDX_DA5005AFDB29484B (quotation_id), INDEX IDX_DA5005AF4584665A (product_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE quotations ADD CONSTRAINT FK_8B8A21118902B4B FOREIGN KEY (business_id) REFERENCES businesses (id)');
        $this->addSql('ALTER TABLE quotations ADD CONSTRAINT FK_8B8A211B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE quotations ADD CONSTRAINT FK_8B8A2119395C3F3 FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE quotation_items ADD CONSTRAINT FK_DA5005AFDB29484B FOREIGN KEY (quotation_id) REFERENCES quotations (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quotation_items ADD CONSTRAINT FK_DA5005AF4584665A FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quotations DROP FOREIGN KEY FK_8B8A21118902B4B');
        $this->addSql('ALTER TABLE quotations DROP FOREIGN KEY FK_8B8A211B03A8386');
        $this->addSql('ALTER TABLE quotations DROP FOREIGN KEY FK_8B8A2119395C3F3');
        $this->addSql('ALTER TABLE quotation_items DROP FOREIGN KEY FK_DA5005AFDB29484B');
        $this->addSql('ALTER TABLE quotation_items DROP FOREIGN KEY FK_DA5005AF4584665A');
        $this->addSql('DROP TABLE quotation_items');
        $this->addSql('DROP TABLE quotations');
        $this->addSql('ALTER TABLE businesses DROP quotation_header_lines, DROP quotation_footer_lines, DROP quotation_header_image_path, DROP quotation_footer_image_path');
    }
}
