<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250402120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add customer account movements for current account tracking';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('customer_account_movements');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('business_id', 'integer', ['notnull' => true]);
        $table->addColumn('customer_id', 'integer', ['notnull' => true]);
        $table->addColumn('type', 'string', ['length' => 10]);
        $table->addColumn('amount', 'decimal', ['precision' => 10, 'scale' => 2]);
        $table->addColumn('reference_type', 'string', ['length' => 20]);
        $table->addColumn('reference_id', 'integer', ['notnull' => false]);
        $table->addColumn('note', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('created_by_id', 'integer', ['notnull' => false]);
        $table->addColumn('created_at', 'datetime_immutable');
        $table->setPrimaryKey(['id']);
        $table->addIndex(['customer_id'], 'idx_account_customer');
        $table->addIndex(['business_id'], 'idx_account_business');
        $table->addForeignKeyConstraint('businesses', ['business_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint('customers', ['customer_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint('users', ['created_by_id'], ['id'], ['onDelete' => 'SET NULL']);
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('customer_account_movements')) {
            $schema->dropTable('customer_account_movements');
        }
    }
}
