<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250416124000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove subscription email notification unique constraint';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_notification_log DROP INDEX uniq_email_notification_log_subscription');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_notification_log ADD UNIQUE INDEX uniq_email_notification_log_subscription (type, recipient_email, subscription_id)');
    }
}
