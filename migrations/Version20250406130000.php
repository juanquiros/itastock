<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250406130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add platform roles data, user/business status, leads archive flag, subscriptions, and seed platform admin';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE business ADD status VARCHAR(20) DEFAULT 'ACTIVE' NOT NULL");
        $this->addSql("ALTER TABLE users ADD is_active TINYINT(1) DEFAULT 1 NOT NULL");
        $this->addSql("ALTER TABLE leads ADD is_archived TINYINT(1) DEFAULT 0 NOT NULL");

        $this->addSql('CREATE TABLE subscriptions (id INT AUTO_INCREMENT NOT NULL, business_id INT NOT NULL, plan_id INT NOT NULL, status VARCHAR(20) NOT NULL, start_at DATETIME NOT NULL, end_at DATETIME DEFAULT NULL, trial_ends_at DATETIME DEFAULT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_A3C664D4AEB81C6F (business_id), INDEX IDX_A3C664D45E237E06 (plan_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE subscriptions ADD CONSTRAINT FK_A3C664D4AEB81C6F FOREIGN KEY (business_id) REFERENCES business (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE subscriptions ADD CONSTRAINT FK_A3C664D45E237E06 FOREIGN KEY (plan_id) REFERENCES plans (id)');

        $this->addSql("INSERT INTO plans (code, name, price_monthly, currency, features_json, is_active, is_featured, sort_order) VALUES ('demo-basic', 'Demo Básico', 0, 'ARS', '[\"Incluye TRIAL de 14 días\"]', 1, 1, 0) ON DUPLICATE KEY UPDATE code = code");

        $this->addSql("SET @demoBusinessId := (SELECT id FROM business ORDER BY id ASC LIMIT 1)");
        $this->addSql("SET @demoPlanId := (SELECT id FROM plans WHERE code = 'demo-basic' LIMIT 1)");
        $this->addSql("INSERT INTO subscriptions (business_id, plan_id, status, start_at, trial_ends_at, notes, created_at, updated_at) VALUES (@demoBusinessId, @demoPlanId, 'TRIAL', NOW(), DATE_ADD(NOW(), INTERVAL 14 DAY), 'Demo inicial', NOW(), NOW())");

        $this->addSql("INSERT INTO business (name, created_at, status) VALUES ('ItaStock Plataforma', NOW(), 'ACTIVE')");
        $this->addSql("SET @platformBusinessId := LAST_INSERT_ID()");
        $this->addSql("INSERT INTO users (business_id, email, roles, password, full_name, is_active) VALUES (@platformBusinessId, 'platform@itastock.local', '[\"ROLE_PLATFORM_ADMIN\"]', '$2y$12$guF6i3YjMnAm.bt1cdEw9Oyy8ZzjevVXeq.PCdWcNd9DR5ROrXLlS', 'Platform Admin', 1)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscriptions DROP FOREIGN KEY FK_A3C664D4AEB81C6F');
        $this->addSql('ALTER TABLE subscriptions DROP FOREIGN KEY FK_A3C664D45E237E06');
        $this->addSql('DROP TABLE subscriptions');

        $this->addSql("ALTER TABLE business DROP status");
        $this->addSql("ALTER TABLE users DROP is_active");
        $this->addSql("ALTER TABLE leads DROP is_archived");

        $this->addSql("DELETE FROM users WHERE email = 'platform@itastock.local'");
        $this->addSql("DELETE FROM business WHERE name = 'ItaStock Plataforma'");
    }
}
