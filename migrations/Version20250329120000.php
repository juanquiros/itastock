<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250329120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create sales, sale items and payments tables';
    }

    public function up(Schema $schema): void
    {
        $sales = $schema->createTable('sales');
        $sales->addColumn('id', 'integer', ['autoincrement' => true]);
        $sales->addColumn('business_id', 'integer');
        $sales->addColumn('created_by_id', 'integer', ['notnull' => false]);
        $sales->addColumn('total', 'decimal', ['precision' => 10, 'scale' => 2]);
        $sales->addColumn('created_at', 'datetime_immutable');
        $sales->setPrimaryKey(['id']);
        $sales->addIndex(['business_id'], 'IDX_SALES_BUSINESS');
        $sales->addIndex(['created_by_id'], 'IDX_SALES_CREATED_BY');
        $sales->addForeignKeyConstraint('business', ['business_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_SALES_BUSINESS');
        $sales->addForeignKeyConstraint('users', ['created_by_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_SALES_CREATED_BY');

        $saleItems = $schema->createTable('sale_items');
        $saleItems->addColumn('id', 'integer', ['autoincrement' => true]);
        $saleItems->addColumn('sale_id', 'integer');
        $saleItems->addColumn('product_id', 'integer', ['notnull' => false]);
        $saleItems->addColumn('description', 'string', ['length' => 255]);
        $saleItems->addColumn('qty', 'integer');
        $saleItems->addColumn('unit_price', 'decimal', ['precision' => 10, 'scale' => 2]);
        $saleItems->addColumn('line_total', 'decimal', ['precision' => 10, 'scale' => 2]);
        $saleItems->setPrimaryKey(['id']);
        $saleItems->addIndex(['sale_id'], 'IDX_SALE_ITEMS_SALE');
        $saleItems->addIndex(['product_id'], 'IDX_SALE_ITEMS_PRODUCT');
        $saleItems->addForeignKeyConstraint('sales', ['sale_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_SALE_ITEMS_SALE');
        $saleItems->addForeignKeyConstraint('products', ['product_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_SALE_ITEMS_PRODUCT');

        $payments = $schema->createTable('payments');
        $payments->addColumn('id', 'integer', ['autoincrement' => true]);
        $payments->addColumn('sale_id', 'integer');
        $payments->addColumn('amount', 'decimal', ['precision' => 10, 'scale' => 2]);
        $payments->addColumn('method', 'string', ['length' => 32]);
        $payments->addColumn('created_at', 'datetime_immutable');
        $payments->setPrimaryKey(['id']);
        $payments->addIndex(['sale_id'], 'IDX_PAYMENTS_SALE');
        $payments->addForeignKeyConstraint('sales', ['sale_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_PAYMENTS_SALE');
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('payments')->removeForeignKey('FK_PAYMENTS_SALE');
        $schema->dropTable('payments');

        $schema->getTable('sale_items')->removeForeignKey('FK_SALE_ITEMS_SALE');
        $schema->getTable('sale_items')->removeForeignKey('FK_SALE_ITEMS_PRODUCT');
        $schema->dropTable('sale_items');

        $schema->getTable('sales')->removeForeignKey('FK_SALES_BUSINESS');
        $schema->getTable('sales')->removeForeignKey('FK_SALES_CREATED_BY');
        $schema->dropTable('sales');
    }
}
