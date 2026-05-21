<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20260521143000 extends AbstractMigration
{
    public function getDescription(): string { return 'Sprint 1B fiscal config and purchase fiscal columns'; }
    public function up(Schema $schema): void {
        $this->addSql('ALTER TABLE business_arca_configs ADD fiscal_components_enabled TINYINT(1) DEFAULT 0 NOT NULL, ADD manual_fiscal_components_enabled TINYINT(1) DEFAULT 0 NOT NULL, ADD fiscal_transparency_enabled TINYINT(1) DEFAULT 0 NOT NULL, ADD default_iibb_jurisdiction VARCHAR(80) DEFAULT NULL, ADD default_arca_tribute_internal_tax_id INT DEFAULT NULL, ADD default_arca_tribute_iibb_perception_id INT DEFAULT NULL, ADD default_arca_tribute_vat_perception_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_invoices ADD fiscal_components_total NUMERIC(12,2) DEFAULT 0.00 NOT NULL, ADD internal_taxes_total NUMERIC(12,2) DEFAULT 0.00 NOT NULL, ADD perceptions_total NUMERIC(12,2) DEFAULT 0.00 NOT NULL, ADD other_taxes_total NUMERIC(12,2) DEFAULT 0.00 NOT NULL, ADD fiscal_components_snapshot JSON DEFAULT NULL');
    }
    public function down(Schema $schema): void {
        $this->addSql('ALTER TABLE business_arca_configs DROP fiscal_components_enabled, DROP manual_fiscal_components_enabled, DROP fiscal_transparency_enabled, DROP default_iibb_jurisdiction, DROP default_arca_tribute_internal_tax_id, DROP default_arca_tribute_iibb_perception_id, DROP default_arca_tribute_vat_perception_id');
        $this->addSql('ALTER TABLE purchase_invoices DROP fiscal_components_total, DROP internal_taxes_total, DROP perceptions_total, DROP other_taxes_total, DROP fiscal_components_snapshot');
    }
}
