<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250415120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add email notification logs for email idempotency and auditing';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('email_notification_log');
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('type', 'string', ['length' => 64]);
        $table->addColumn('recipient_email', 'string', ['length' => 180]);
        $table->addColumn('recipient_role', 'string', ['length' => 20]);
        $table->addColumn('business_id', 'integer', ['notnull' => false]);
        $table->addColumn('subscription_id', 'integer', ['notnull' => false]);
        $table->addColumn('period_start', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('period_end', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('context_hash', 'string', ['length' => 64, 'notnull' => false]);
        $table->addColumn('status', 'string', ['length' => 16]);
        $table->addColumn('error_message', 'text', ['notnull' => false]);
        $table->addColumn('sent_at', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('created_at', 'datetime_immutable');
        $table->setPrimaryKey(['id']);
        $table->addIndex(['business_id'], 'IDX_EMAIL_NOTIFICATION_LOG_BUSINESS');
        $table->addIndex(['subscription_id'], 'IDX_EMAIL_NOTIFICATION_LOG_SUBSCRIPTION');
        $table->addUniqueIndex(['type', 'recipient_email', 'period_start', 'period_end'], 'UNIQ_EMAIL_NOTIFICATION_LOG_PERIOD');
        $table->addUniqueIndex(['type', 'recipient_email', 'subscription_id'], 'UNIQ_EMAIL_NOTIFICATION_LOG_SUBSCRIPTION');
        $table->addForeignKeyConstraint('business', ['business_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_EMAIL_NOTIFICATION_LOG_BUSINESS');
        $table->addForeignKeyConstraint('subscriptions', ['subscription_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_EMAIL_NOTIFICATION_LOG_SUBSCRIPTION');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('email_notification_log');
    }
}
