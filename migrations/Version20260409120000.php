<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260409120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create supplier_payments table for supplier outflows linked to cash reporting';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE supplier_payments (id INT AUTO_INCREMENT NOT NULL, business_id INT NOT NULL, supplier_id INT NOT NULL, purchase_order_id INT DEFAULT NULL, purchase_invoice_id INT DEFAULT NULL, created_by_id INT NOT NULL, amount NUMERIC(10, 2) NOT NULL, payment_method VARCHAR(20) NOT NULL, paid_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", reference_number VARCHAR(255) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, INDEX IDX_4E31913A8902B4B (business_id), INDEX IDX_4E31913A2ADD6D8C (supplier_id), INDEX IDX_4E31913A3A8B6A34 (purchase_order_id), INDEX IDX_4E31913AF3B5D0FD (purchase_invoice_id), INDEX IDX_4E31913AB03A8386 (created_by_id), INDEX IDX_4E31913A8446AB7A (paid_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE supplier_payments ADD CONSTRAINT FK_4E31913A8902B4B FOREIGN KEY (business_id) REFERENCES businesses (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE supplier_payments ADD CONSTRAINT FK_4E31913A2ADD6D8C FOREIGN KEY (supplier_id) REFERENCES suppliers (id)');
        $this->addSql('ALTER TABLE supplier_payments ADD CONSTRAINT FK_4E31913A3A8B6A34 FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE supplier_payments ADD CONSTRAINT FK_4E31913AF3B5D0FD FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE supplier_payments ADD CONSTRAINT FK_4E31913AB03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE supplier_payments DROP FOREIGN KEY FK_4E31913A8902B4B');
        $this->addSql('ALTER TABLE supplier_payments DROP FOREIGN KEY FK_4E31913A2ADD6D8C');
        $this->addSql('ALTER TABLE supplier_payments DROP FOREIGN KEY FK_4E31913A3A8B6A34');
        $this->addSql('ALTER TABLE supplier_payments DROP FOREIGN KEY FK_4E31913AF3B5D0FD');
        $this->addSql('ALTER TABLE supplier_payments DROP FOREIGN KEY FK_4E31913AB03A8386');
        $this->addSql('DROP TABLE supplier_payments');
    }
}
