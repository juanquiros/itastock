<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250415123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add email preferences for business and user overrides';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('email_preferences');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('business_id', 'integer');
        $table->addColumn('user_id', 'integer', ['notnull' => false]);
        $table->addColumn('enabled', 'boolean', ['default' => true]);
        $table->addColumn('subscription_alerts_enabled', 'boolean', ['default' => true]);
        $table->addColumn('report_daily_enabled', 'boolean', ['default' => false]);
        $table->addColumn('report_weekly_enabled', 'boolean', ['default' => true]);
        $table->addColumn('report_monthly_enabled', 'boolean', ['default' => true]);
        $table->addColumn('report_annual_enabled', 'boolean', ['default' => false]);
        $table->addColumn('delivery_hour', 'integer', ['default' => 8]);
        $table->addColumn('delivery_minute', 'integer', ['default' => 0]);
        $table->addColumn('timezone', 'string', ['length' => 64, 'default' => 'America/Argentina/Buenos_Aires']);
        $table->addColumn('created_at', 'datetime_immutable');
        $table->addColumn('updated_at', 'datetime_immutable');
        $table->setPrimaryKey(['id']);
        $table->addIndex(['business_id'], 'IDX_EMAIL_PREFERENCES_BUSINESS');
        $table->addIndex(['user_id'], 'IDX_EMAIL_PREFERENCES_USER');
        $table->addUniqueIndex(['business_id', 'user_id'], 'UNIQ_EMAIL_PREFERENCES_BUSINESS_USER');
        $table->addForeignKeyConstraint('business', ['business_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_EMAIL_PREFERENCES_BUSINESS');
        $table->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_EMAIL_PREFERENCES_USER');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('email_preferences');
    }
}
