<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260522110000 extends AbstractMigration
{
    public function getDescription(): string { return 'Sprint 2A fiscal rules infra'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE fiscal_rules (id INT AUTO_INCREMENT NOT NULL, business_id INT NOT NULL, product_id INT DEFAULT NULL, category_id INT DEFAULT NULL, customer_id INT DEFAULT NULL, name VARCHAR(160) NOT NULL, active TINYINT(1) DEFAULT 1 NOT NULL, component_type VARCHAR(40) NOT NULL, priority INT DEFAULT 100 NOT NULL, applies_to VARCHAR(30) NOT NULL, customer_iva_condition_id INT DEFAULT NULL, jurisdiction VARCHAR(80) DEFAULT NULL, description_template VARCHAR(255) DEFAULT NULL, taxable_base_mode VARCHAR(40) NOT NULL, rate NUMERIC(7, 4) DEFAULT NULL, fixed_amount NUMERIC(12, 2) DEFAULT NULL, min_amount NUMERIC(12, 2) DEFAULT NULL, max_amount NUMERIC(12, 2) DEFAULT NULL, arca_tribute_id INT DEFAULT NULL, report_to_arca TINYINT(1) DEFAULT 1 NOT NULL, affects_total TINYINT(1) DEFAULT 1 NOT NULL, included_in_price TINYINT(1) DEFAULT 0 NOT NULL, starts_at DATE DEFAULT NULL, ends_at DATE DEFAULT NULL, stop_processing TINYINT(1) DEFAULT 0 NOT NULL, metadata JSON DEFAULT NULL, created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", updated_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", INDEX IDX_FISCAL_RULE_BUSINESS (business_id), INDEX IDX_FISCAL_RULE_ACTIVE (active), INDEX IDX_FISCAL_RULE_COMPONENT_TYPE (component_type), INDEX IDX_FISCAL_RULE_APPLIES_TO (applies_to), INDEX IDX_FISCAL_RULE_PRODUCT (product_id), INDEX IDX_FISCAL_RULE_CATEGORY (category_id), INDEX IDX_FISCAL_RULE_CUSTOMER (customer_id), INDEX IDX_FISCAL_RULE_CUSTOMER_IVA (customer_iva_condition_id), INDEX IDX_FISCAL_RULE_PRIORITY (priority), INDEX IDX_FISCAL_RULE_STARTS (starts_at), INDEX IDX_FISCAL_RULE_ENDS (ends_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE fiscal_rules ADD CONSTRAINT FK_FISCAL_RULE_BUSINESS FOREIGN KEY (business_id) REFERENCES business (id)');
        $this->addSql('ALTER TABLE fiscal_rules ADD CONSTRAINT FK_FISCAL_RULE_PRODUCT FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE fiscal_rules ADD CONSTRAINT FK_FISCAL_RULE_CATEGORY FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE fiscal_rules ADD CONSTRAINT FK_FISCAL_RULE_CUSTOMER FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE business_arca_configs ADD automatic_fiscal_rules_enabled TINYINT(1) DEFAULT 0 NOT NULL');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fiscal_rules DROP FOREIGN KEY FK_FISCAL_RULE_BUSINESS');
        $this->addSql('ALTER TABLE fiscal_rules DROP FOREIGN KEY FK_FISCAL_RULE_PRODUCT');
        $this->addSql('ALTER TABLE fiscal_rules DROP FOREIGN KEY FK_FISCAL_RULE_CATEGORY');
        $this->addSql('ALTER TABLE fiscal_rules DROP FOREIGN KEY FK_FISCAL_RULE_CUSTOMER');
        $this->addSql('DROP TABLE fiscal_rules');
        $this->addSql('ALTER TABLE business_arca_configs DROP automatic_fiscal_rules_enabled');
    }
}
