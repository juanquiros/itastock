<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250330120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create cash sessions table for opening/closing caja';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('cash_sessions');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('business_id', 'integer');
        $table->addColumn('opened_by_id', 'integer');
        $table->addColumn('opened_at', 'datetime_immutable');
        $table->addColumn('closed_at', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('initial_cash', 'decimal', ['precision' => 10, 'scale' => 2]);
        $table->addColumn('final_cash_counted', 'decimal', ['precision' => 10, 'scale' => 2, 'notnull' => false]);
        $table->addColumn('totals_by_payment_method', 'json');
        $table->setPrimaryKey(['id']);
        $table->addIndex(['business_id'], 'IDX_CASH_SESSION_BUSINESS');
        $table->addIndex(['opened_by_id'], 'IDX_CASH_SESSION_OPENED_BY');
        $table->addForeignKeyConstraint('business', ['business_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_CASH_SESSION_BUSINESS');
        $table->addForeignKeyConstraint('users', ['opened_by_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_CASH_SESSION_OPENED_BY');
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('cash_sessions')->removeForeignKey('FK_CASH_SESSION_BUSINESS');
        $schema->getTable('cash_sessions')->removeForeignKey('FK_CASH_SESSION_OPENED_BY');
        $schema->dropTable('cash_sessions');
    }
}
