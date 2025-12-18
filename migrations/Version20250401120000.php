<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250401120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add price lists, items, and customer price list relation';
    }

    public function up(Schema $schema): void
    {
        $priceLists = $schema->createTable('price_lists');
        $priceLists->addColumn('id', 'integer', ['autoincrement' => true]);
        $priceLists->addColumn('business_id', 'integer', ['notnull' => true]);
        $priceLists->addColumn('name', 'string', ['length' => 255]);
        $priceLists->addColumn('is_active', 'boolean', ['default' => true]);
        $priceLists->addColumn('is_default', 'boolean', ['default' => false]);
        $priceLists->addColumn('created_at', 'datetime_immutable');
        $priceLists->setPrimaryKey(['id']);
        $priceLists->addForeignKeyConstraint('businesses', ['business_id'], ['id'], ['onDelete' => 'CASCADE']);

        $priceListItems = $schema->createTable('price_list_items');
        $priceListItems->addColumn('id', 'integer', ['autoincrement' => true]);
        $priceListItems->addColumn('business_id', 'integer', ['notnull' => true]);
        $priceListItems->addColumn('price_list_id', 'integer', ['notnull' => true]);
        $priceListItems->addColumn('product_id', 'integer', ['notnull' => true]);
        $priceListItems->addColumn('price', 'decimal', ['precision' => 10, 'scale' => 2]);
        $priceListItems->setPrimaryKey(['id']);
        $priceListItems->addUniqueIndex(['price_list_id', 'product_id'], 'price_list_product_unique');
        $priceListItems->addForeignKeyConstraint('businesses', ['business_id'], ['id'], ['onDelete' => 'CASCADE']);
        $priceListItems->addForeignKeyConstraint('price_lists', ['price_list_id'], ['id'], ['onDelete' => 'CASCADE']);
        $priceListItems->addForeignKeyConstraint('products', ['product_id'], ['id'], ['onDelete' => 'CASCADE']);

        $customers = $schema->getTable('customers');
        if (!$customers->hasColumn('price_list_id')) {
            $customers->addColumn('price_list_id', 'integer', ['notnull' => false]);
            $customers->addForeignKeyConstraint('price_lists', ['price_list_id'], ['id'], ['onDelete' => 'SET NULL']);
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('customers') && $schema->getTable('customers')->hasColumn('price_list_id')) {
            $schema->getTable('customers')->dropColumn('price_list_id');
        }

        if ($schema->hasTable('price_list_items')) {
            $schema->dropTable('price_list_items');
        }

        if ($schema->hasTable('price_lists')) {
            $schema->dropTable('price_lists');
        }
    }
}
