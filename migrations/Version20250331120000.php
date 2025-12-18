<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250331120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add customers table and link sales to customers';
    }

    public function up(Schema $schema): void
    {
        $customers = $schema->createTable('customers');
        $customers->addColumn('id', 'integer', ['autoincrement' => true]);
        $customers->addColumn('business_id', 'integer');
        $customers->addColumn('name', 'string', ['length' => 255]);
        $customers->addColumn('document_type', 'string', ['length' => 10, 'notnull' => false]);
        $customers->addColumn('document_number', 'string', ['length' => 50, 'notnull' => false]);
        $customers->addColumn('phone', 'string', ['length' => 50, 'notnull' => false]);
        $customers->addColumn('address', 'string', ['length' => 255, 'notnull' => false]);
        $customers->addColumn('customer_type', 'string', ['length' => 20]);
        $customers->addColumn('is_active', 'boolean');
        $customers->addColumn('created_at', 'datetime_immutable');
        $customers->setPrimaryKey(['id']);
        $customers->addIndex(['business_id'], 'IDX_CUSTOMER_BUSINESS');
        $customers->addForeignKeyConstraint('business', ['business_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_CUSTOMER_BUSINESS');

        $sales = $schema->getTable('sales');
        if (!$sales->hasColumn('customer_id')) {
            $sales->addColumn('customer_id', 'integer', ['notnull' => false]);
            $sales->addIndex(['customer_id'], 'IDX_SALES_CUSTOMER');
            $sales->addForeignKeyConstraint('customers', ['customer_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_SALES_CUSTOMER');
        }
    }

    public function down(Schema $schema): void
    {
        $sales = $schema->getTable('sales');
        if ($sales->hasForeignKey('FK_SALES_CUSTOMER')) {
            $sales->removeForeignKey('FK_SALES_CUSTOMER');
        }
        if ($sales->hasColumn('customer_id')) {
            $sales->dropColumn('customer_id');
        }

        if ($schema->hasTable('customers')) {
            $schema->getTable('customers')->removeForeignKey('FK_CUSTOMER_BUSINESS');
            $schema->dropTable('customers');
        }
    }
}
