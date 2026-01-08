<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250416123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow multiple subscription notification logs by including context hash in unique constraint';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_notification_log DROP INDEX uniq_email_notification_log_subscription');
        $this->addSql('ALTER TABLE email_notification_log ADD UNIQUE INDEX uniq_email_notification_log_subscription (type, recipient_email, subscription_id, context_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_notification_log DROP INDEX uniq_email_notification_log_subscription');
        $this->addSql('ALTER TABLE email_notification_log ADD UNIQUE INDEX uniq_email_notification_log_subscription (type, recipient_email, subscription_id)');
    }
}
