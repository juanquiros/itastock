<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260422120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Harden email notification idempotency with persistent idempotency key unique index';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_notification_log ADD idempotency_key VARCHAR(128) DEFAULT NULL');
        $this->addSql("UPDATE email_notification_log SET idempotency_key = SHA2(CONCAT_WS('|', type, LOWER(recipient_email), CASE WHEN period_start IS NOT NULL AND period_end IS NOT NULL THEN CONCAT('period:', DATE_FORMAT(period_start, '%Y-%m-%dT%H:%i:%s'), ':', DATE_FORMAT(period_end, '%Y-%m-%dT%H:%i:%s')) WHEN subscription_id IS NOT NULL THEN CONCAT('subscription:', subscription_id) ELSE 'none' END, COALESCE(context_hash, 'no-context'), CONCAT('legacy-id:', id)), 256)");
        $this->addSql('ALTER TABLE email_notification_log MODIFY idempotency_key VARCHAR(128) NOT NULL');
        $this->addSql('ALTER TABLE email_notification_log ADD UNIQUE INDEX uniq_email_notification_log_idempotency (idempotency_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_notification_log DROP INDEX uniq_email_notification_log_idempotency');
        $this->addSql('ALTER TABLE email_notification_log DROP COLUMN idempotency_key');
    }
}
