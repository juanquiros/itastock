<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250415140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pending subscription changes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE pending_subscription_changes (id INT AUTO_INCREMENT NOT NULL, business_id INT NOT NULL, current_subscription_id INT NOT NULL, target_billing_plan_id INT NOT NULL, type VARCHAR(16) NOT NULL, status VARCHAR(24) NOT NULL, effective_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', mp_preapproval_id VARCHAR(128) DEFAULT NULL, init_point VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', paid_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', applied_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_pending_subscription_change_business (business_id), INDEX idx_pending_subscription_change_status (status), INDEX IDX_D2B5A594A6D16811 (current_subscription_id), INDEX IDX_D2B5A594D2D0C4E0 (target_billing_plan_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE pending_subscription_changes ADD CONSTRAINT FK_D2B5A59432C8A3DE FOREIGN KEY (business_id) REFERENCES businesses (id)');
        $this->addSql('ALTER TABLE pending_subscription_changes ADD CONSTRAINT FK_D2B5A594A6D16811 FOREIGN KEY (current_subscription_id) REFERENCES subscriptions (id)');
        $this->addSql('ALTER TABLE pending_subscription_changes ADD CONSTRAINT FK_D2B5A594D2D0C4E0 FOREIGN KEY (target_billing_plan_id) REFERENCES billing_plans (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pending_subscription_changes DROP FOREIGN KEY FK_D2B5A59432C8A3DE');
        $this->addSql('ALTER TABLE pending_subscription_changes DROP FOREIGN KEY FK_D2B5A594A6D16811');
        $this->addSql('ALTER TABLE pending_subscription_changes DROP FOREIGN KEY FK_D2B5A594D2D0C4E0');
        $this->addSql('DROP TABLE pending_subscription_changes');
    }
}
