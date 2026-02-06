<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260115124000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add suppliers, purchase orders, purchase invoices, and supplier fields for products';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE suppliers (id INT AUTO_INCREMENT NOT NULL, business_id INT NOT NULL, name VARCHAR(255) NOT NULL, cuit VARCHAR(20) DEFAULT NULL, iva_condition VARCHAR(20) NOT NULL, email VARCHAR(255) DEFAULT NULL, phone VARCHAR(50) DEFAULT NULL, address VARCHAR(255) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_SUPPLIERS_BUSINESS (business_id), UNIQUE INDEX uniq_supplier_cuit_business (business_id, cuit), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE purchase_orders (id INT AUTO_INCREMENT NOT NULL, business_id INT NOT NULL, supplier_id INT NOT NULL, status VARCHAR(20) NOT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_PURCHASE_ORDERS_BUSINESS (business_id), INDEX IDX_PURCHASE_ORDERS_SUPPLIER (supplier_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE purchase_order_items (id INT AUTO_INCREMENT NOT NULL, purchase_order_id INT NOT NULL, product_id INT NOT NULL, quantity NUMERIC(10, 3) NOT NULL, unit_cost NUMERIC(10, 2) NOT NULL, subtotal NUMERIC(10, 2) NOT NULL, INDEX IDX_PURCHASE_ORDER_ITEMS_ORDER (purchase_order_id), INDEX IDX_PURCHASE_ORDER_ITEMS_PRODUCT (product_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE purchase_invoices (id INT AUTO_INCREMENT NOT NULL, business_id INT NOT NULL, supplier_id INT NOT NULL, purchase_order_id INT DEFAULT NULL, invoice_type VARCHAR(20) NOT NULL, point_of_sale VARCHAR(20) DEFAULT NULL, invoice_number VARCHAR(50) NOT NULL, invoice_date DATE NOT NULL, net_amount NUMERIC(10, 2) NOT NULL, iva_rate NUMERIC(5, 2) NOT NULL, iva_amount NUMERIC(10, 2) NOT NULL, total_amount NUMERIC(10, 2) NOT NULL, notes LONGTEXT DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, INDEX IDX_PURCHASE_INVOICES_BUSINESS (business_id), INDEX IDX_PURCHASE_INVOICES_SUPPLIER (supplier_id), INDEX IDX_PURCHASE_INVOICES_ORDER (purchase_order_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE suppliers ADD CONSTRAINT FK_SUPPLIERS_BUSINESS FOREIGN KEY (business_id) REFERENCES business (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE purchase_orders ADD CONSTRAINT FK_PURCHASE_ORDERS_BUSINESS FOREIGN KEY (business_id) REFERENCES business (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE purchase_orders ADD CONSTRAINT FK_PURCHASE_ORDERS_SUPPLIER FOREIGN KEY (supplier_id) REFERENCES suppliers (id)');
        $this->addSql('ALTER TABLE purchase_order_items ADD CONSTRAINT FK_PURCHASE_ORDER_ITEMS_ORDER FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE purchase_order_items ADD CONSTRAINT FK_PURCHASE_ORDER_ITEMS_PRODUCT FOREIGN KEY (product_id) REFERENCES products (id)');
        $this->addSql('ALTER TABLE purchase_invoices ADD CONSTRAINT FK_PURCHASE_INVOICES_BUSINESS FOREIGN KEY (business_id) REFERENCES business (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE purchase_invoices ADD CONSTRAINT FK_PURCHASE_INVOICES_SUPPLIER FOREIGN KEY (supplier_id) REFERENCES suppliers (id)');
        $this->addSql('ALTER TABLE purchase_invoices ADD CONSTRAINT FK_PURCHASE_INVOICES_ORDER FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE products ADD supplier_id INT DEFAULT NULL, ADD supplier_sku VARCHAR(64) DEFAULT NULL, ADD purchase_price NUMERIC(10, 2) DEFAULT NULL, ADD target_stock NUMERIC(10, 3) DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD CONSTRAINT FK_PRODUCTS_SUPPLIER FOREIGN KEY (supplier_id) REFERENCES suppliers (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_PRODUCTS_SUPPLIER ON products (supplier_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_order_items DROP FOREIGN KEY FK_PURCHASE_ORDER_ITEMS_PRODUCT');
        $this->addSql('ALTER TABLE purchase_order_items DROP FOREIGN KEY FK_PURCHASE_ORDER_ITEMS_ORDER');
        $this->addSql('ALTER TABLE purchase_orders DROP FOREIGN KEY FK_PURCHASE_ORDERS_BUSINESS');
        $this->addSql('ALTER TABLE purchase_orders DROP FOREIGN KEY FK_PURCHASE_ORDERS_SUPPLIER');
        $this->addSql('ALTER TABLE purchase_invoices DROP FOREIGN KEY FK_PURCHASE_INVOICES_BUSINESS');
        $this->addSql('ALTER TABLE purchase_invoices DROP FOREIGN KEY FK_PURCHASE_INVOICES_SUPPLIER');
        $this->addSql('ALTER TABLE purchase_invoices DROP FOREIGN KEY FK_PURCHASE_INVOICES_ORDER');
        $this->addSql('ALTER TABLE suppliers DROP FOREIGN KEY FK_SUPPLIERS_BUSINESS');
        $this->addSql('ALTER TABLE products DROP FOREIGN KEY FK_PRODUCTS_SUPPLIER');
        $this->addSql('DROP TABLE purchase_order_items');
        $this->addSql('DROP TABLE purchase_orders');
        $this->addSql('DROP TABLE purchase_invoices');
        $this->addSql('DROP TABLE suppliers');
        $this->addSql('DROP INDEX IDX_PRODUCTS_SUPPLIER ON products');
        $this->addSql('ALTER TABLE products DROP supplier_id, DROP supplier_sku, DROP purchase_price, DROP target_stock');
    }
}
