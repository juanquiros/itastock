<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260409183000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add quotation module entities and business quotation branding fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE businesses ADD quotation_header_lines LONGTEXT DEFAULT NULL, ADD quotation_footer_lines LONGTEXT DEFAULT NULL, ADD quotation_header_image_path LONGTEXT DEFAULT NULL, ADD quotation_footer_image_path LONGTEXT DEFAULT NULL');
        $this->addSql('CREATE TABLE quotation (id INT AUTO_INCREMENT NOT NULL, business_id INT NOT NULL, created_by_id INT DEFAULT NULL, customer_id INT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", price_list_id_used INT DEFAULT NULL, price_list_name_used VARCHAR(255) DEFAULT NULL, subtotal NUMERIC(10, 2) NOT NULL, total NUMERIC(10, 2) NOT NULL, status VARCHAR(16) NOT NULL, cashier_comment LONGTEXT DEFAULT NULL, INDEX IDX_1DD8C9A28902B4B (business_id), INDEX IDX_1DD8C9AB03A8386 (created_by_id), INDEX IDX_1DD8C9A49395FE6 (customer_id), INDEX IDX_1DD8C9A684ACA1B (created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE quotation_item (id INT AUTO_INCREMENT NOT NULL, quotation_id INT NOT NULL, product_id INT DEFAULT NULL, description VARCHAR(255) NOT NULL, qty NUMERIC(10, 3) NOT NULL, unit_price NUMERIC(10, 2) NOT NULL, line_subtotal NUMERIC(10, 2) NOT NULL, line_discount NUMERIC(10, 2) NOT NULL, line_total NUMERIC(10, 2) NOT NULL, iva_rate NUMERIC(5, 2) DEFAULT NULL, INDEX IDX_6A2E5032DB294509 (quotation_id), INDEX IDX_6A2E50324584665A (product_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE quotation ADD CONSTRAINT FK_1DD8C9A28902B4B FOREIGN KEY (business_id) REFERENCES businesses (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quotation ADD CONSTRAINT FK_1DD8C9AB03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE quotation ADD CONSTRAINT FK_1DD8C9A49395FE6 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE quotation_item ADD CONSTRAINT FK_6A2E5032DB294509 FOREIGN KEY (quotation_id) REFERENCES quotation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quotation_item ADD CONSTRAINT FK_6A2E50324584665A FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE quotation_item DROP FOREIGN KEY FK_6A2E5032DB294509');
        $this->addSql('ALTER TABLE quotation_item DROP FOREIGN KEY FK_6A2E50324584665A');
        $this->addSql('ALTER TABLE quotation DROP FOREIGN KEY FK_1DD8C9A28902B4B');
        $this->addSql('ALTER TABLE quotation DROP FOREIGN KEY FK_1DD8C9AB03A8386');
        $this->addSql('ALTER TABLE quotation DROP FOREIGN KEY FK_1DD8C9A49395FE6');
        $this->addSql('DROP TABLE quotation_item');
        $this->addSql('DROP TABLE quotation');
        $this->addSql('ALTER TABLE businesses DROP quotation_header_lines, DROP quotation_footer_lines, DROP quotation_header_image_path, DROP quotation_footer_image_path');
    }
}
