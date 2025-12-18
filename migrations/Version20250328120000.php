<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250328120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create categories, products and stock movements tables';
    }

    public function up(Schema $schema): void
    {
        $categories = $schema->createTable('categories');
        $categories->addColumn('id', 'integer', ['autoincrement' => true]);
        $categories->addColumn('business_id', 'integer');
        $categories->addColumn('name', 'string', ['length' => 120]);
        $categories->setPrimaryKey(['id']);
        $categories->addIndex(['business_id'], 'IDX_CATEGORIES_BUSINESS');
        $categories->addForeignKeyConstraint('business', ['business_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_CATEGORIES_BUSINESS');

        $products = $schema->createTable('products');
        $products->addColumn('id', 'integer', ['autoincrement' => true]);
        $products->addColumn('business_id', 'integer');
        $products->addColumn('category_id', 'integer', ['notnull' => false]);
        $products->addColumn('name', 'string', ['length' => 255]);
        $products->addColumn('sku', 'string', ['length' => 64]);
        $products->addColumn('barcode', 'string', ['length' => 128, 'notnull' => false]);
        $products->addColumn('cost', 'decimal', ['precision' => 10, 'scale' => 2]);
        $products->addColumn('base_price', 'decimal', ['precision' => 10, 'scale' => 2]);
        $products->addColumn('stock_min', 'integer');
        $products->addColumn('stock', 'integer');
        $products->addColumn('is_active', 'boolean', ['default' => true]);
        $products->setPrimaryKey(['id']);
        $products->addIndex(['business_id'], 'IDX_PRODUCTS_BUSINESS');
        $products->addIndex(['category_id'], 'IDX_PRODUCTS_CATEGORY');
        $products->addForeignKeyConstraint('business', ['business_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_PRODUCTS_BUSINESS');
        $products->addForeignKeyConstraint('categories', ['category_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_PRODUCTS_CATEGORY');

        $movements = $schema->createTable('stock_movement');
        $movements->addColumn('id', 'integer', ['autoincrement' => true]);
        $movements->addColumn('product_id', 'integer');
        $movements->addColumn('created_by_id', 'integer', ['notnull' => false]);
        $movements->addColumn('type', 'string', ['length' => 16]);
        $movements->addColumn('qty', 'integer');
        $movements->addColumn('reference', 'string', ['length' => 255, 'notnull' => false]);
        $movements->addColumn('created_at', 'datetime_immutable');
        $movements->setPrimaryKey(['id']);
        $movements->addIndex(['product_id'], 'IDX_MOVEMENTS_PRODUCT');
        $movements->addIndex(['created_by_id'], 'IDX_MOVEMENTS_CREATED_BY');
        $movements->addForeignKeyConstraint('products', ['product_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_MOVEMENTS_PRODUCT');
        $movements->addForeignKeyConstraint('users', ['created_by_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_MOVEMENTS_CREATED_BY');
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('stock_movement')->removeForeignKey('FK_MOVEMENTS_PRODUCT');
        $schema->getTable('stock_movement')->removeForeignKey('FK_MOVEMENTS_CREATED_BY');
        $schema->dropTable('stock_movement');

        $schema->getTable('products')->removeForeignKey('FK_PRODUCTS_BUSINESS');
        $schema->getTable('products')->removeForeignKey('FK_PRODUCTS_CATEGORY');
        $schema->dropTable('products');

        $schema->getTable('categories')->removeForeignKey('FK_CATEGORIES_BUSINESS');
        $schema->dropTable('categories');
    }
}
