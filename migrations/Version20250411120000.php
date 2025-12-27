<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250411120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add billing plans and Mercado Pago subscription fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE billing_plans (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(180) NOT NULL, price NUMERIC(10, 2) NOT NULL, currency VARCHAR(10) NOT NULL, frequency INT NOT NULL, frequency_type VARCHAR(20) NOT NULL, is_active TINYINT(1) NOT NULL, mp_preapproval_plan_id VARCHAR(128) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_BILLING_PLANS_MP_PREAPPROVAL_PLAN_ID (mp_preapproval_plan_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE subscriptions ADD mp_preapproval_id VARCHAR(128) DEFAULT NULL, ADD mp_preapproval_plan_id VARCHAR(128) DEFAULT NULL, ADD payer_email VARCHAR(180) DEFAULT NULL, ADD last_synced_at DATETIME DEFAULT NULL, ADD override_mode VARCHAR(16) DEFAULT NULL, ADD override_until DATETIME DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_SUBSCRIPTIONS_MP_PREAPPROVAL_ID ON subscriptions (mp_preapproval_id)');
        $this->addSql('CREATE INDEX IDX_SUBSCRIPTIONS_MP_PREAPPROVAL_PLAN_ID ON subscriptions (mp_preapproval_plan_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE billing_plans');
        $this->addSql('DROP INDEX UNIQ_SUBSCRIPTIONS_MP_PREAPPROVAL_ID ON subscriptions');
        $this->addSql('DROP INDEX IDX_SUBSCRIPTIONS_MP_PREAPPROVAL_PLAN_ID ON subscriptions');
        $this->addSql('ALTER TABLE subscriptions DROP mp_preapproval_id, DROP mp_preapproval_plan_id, DROP payer_email, DROP last_synced_at, DROP override_mode, DROP override_until');
    }
}
