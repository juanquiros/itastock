<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260115123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add discount engine tables and sale discount fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE discounts (id INT AUTO_INCREMENT NOT NULL, business_id INT NOT NULL, name VARCHAR(255) NOT NULL, status VARCHAR(16) NOT NULL, action_type VARCHAR(16) NOT NULL, action_value NUMERIC(10, 2) NOT NULL, logic_operator VARCHAR(8) NOT NULL, stackable TINYINT(1) NOT NULL, priority INT NOT NULL, conditions JSON NOT NULL, start_at DATETIME DEFAULT NULL, end_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_DISCOUNTS_BUSINESS (business_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE sale_discounts (id INT AUTO_INCREMENT NOT NULL, sale_id INT NOT NULL, discount_id INT DEFAULT NULL, discount_name VARCHAR(255) NOT NULL, action_type VARCHAR(16) NOT NULL, action_value NUMERIC(10, 2) NOT NULL, applied_amount NUMERIC(10, 2) NOT NULL, meta JSON NOT NULL, created_at DATETIME NOT NULL, INDEX IDX_SALE_DISCOUNTS_SALE (sale_id), INDEX IDX_SALE_DISCOUNTS_DISCOUNT (discount_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE discounts ADD CONSTRAINT FK_DISCOUNTS_BUSINESS FOREIGN KEY (business_id) REFERENCES business (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sale_discounts ADD CONSTRAINT FK_SALE_DISCOUNTS_SALE FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sale_discounts ADD CONSTRAINT FK_SALE_DISCOUNTS_DISCOUNT FOREIGN KEY (discount_id) REFERENCES discounts (id) ON DELETE SET NULL');
        $this->addSql("ALTER TABLE sales ADD subtotal NUMERIC(10, 2) NOT NULL DEFAULT '0.00', ADD discount_total NUMERIC(10, 2) NOT NULL DEFAULT '0.00'");
        $this->addSql("ALTER TABLE sale_items ADD line_subtotal NUMERIC(10, 2) NOT NULL DEFAULT '0.00', ADD line_discount NUMERIC(10, 2) NOT NULL DEFAULT '0.00'");
        $this->addSql('UPDATE sales SET subtotal = total, discount_total = 0');
        $this->addSql('UPDATE sale_items SET line_subtotal = line_total, line_discount = 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sale_discounts DROP FOREIGN KEY FK_SALE_DISCOUNTS_SALE');
        $this->addSql('ALTER TABLE sale_discounts DROP FOREIGN KEY FK_SALE_DISCOUNTS_DISCOUNT');
        $this->addSql('ALTER TABLE discounts DROP FOREIGN KEY FK_DISCOUNTS_BUSINESS');
        $this->addSql('DROP TABLE sale_discounts');
        $this->addSql('DROP TABLE discounts');
        $this->addSql('ALTER TABLE sales DROP subtotal, DROP discount_total');
        $this->addSql('ALTER TABLE sale_items DROP line_subtotal, DROP line_discount');
    }
}
