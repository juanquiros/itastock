<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260521120000 extends AbstractMigration
{
    public function getDescription(): string { return 'Sprint 1 fiscal base manual'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE fiscal_components (id INT AUTO_INCREMENT NOT NULL, business_id INT NOT NULL, sale_id INT DEFAULT NULL, purchase_invoice_id INT DEFAULT NULL, source_type VARCHAR(30) NOT NULL, component_type VARCHAR(40) NOT NULL, mode VARCHAR(30) NOT NULL, description VARCHAR(255) NOT NULL, jurisdiction VARCHAR(80) DEFAULT NULL, arca_tribute_id INT DEFAULT NULL, taxable_base NUMERIC(12, 2) DEFAULT 0.00 NOT NULL, rate NUMERIC(7, 4) DEFAULT NULL, amount NUMERIC(12, 2) DEFAULT 0.00 NOT NULL, affects_total TINYINT(1) DEFAULT 1 NOT NULL, report_to_arca TINYINT(1) DEFAULT 1 NOT NULL, included_in_price TINYINT(1) DEFAULT 0 NOT NULL, metadata JSON DEFAULT NULL, created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", updated_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", INDEX IDX_FISCAL_BUSINESS (business_id), INDEX IDX_FISCAL_SALE (sale_id), INDEX IDX_FISCAL_PURCHASE (purchase_invoice_id), INDEX IDX_FISCAL_SOURCE (source_type), INDEX IDX_FISCAL_TYPE (component_type), INDEX IDX_FISCAL_ARCA (arca_tribute_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE fiscal_components ADD CONSTRAINT FK_FISCAL_BUSINESS FOREIGN KEY (business_id) REFERENCES businesses (id)');
        $this->addSql('ALTER TABLE fiscal_components ADD CONSTRAINT FK_FISCAL_SALE FOREIGN KEY (sale_id) REFERENCES sales (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE fiscal_components ADD CONSTRAINT FK_FISCAL_PURCHASE FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sales ADD fiscal_components_total NUMERIC(12, 2) DEFAULT 0.00 NOT NULL, ADD taxable_amount NUMERIC(12, 2) DEFAULT NULL, ADD vat_amount NUMERIC(12, 2) DEFAULT NULL, ADD exempt_amount NUMERIC(12, 2) DEFAULT NULL, ADD non_taxed_amount NUMERIC(12, 2) DEFAULT NULL, ADD fiscal_components_snapshot JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE arca_invoices ADD fiscal_components_total NUMERIC(12, 2) DEFAULT 0.00 NOT NULL, ADD fiscal_components_snapshot JSON DEFAULT NULL, ADD exempt_amount NUMERIC(12, 2) DEFAULT 0.00 NOT NULL, ADD non_taxed_amount NUMERIC(12, 2) DEFAULT 0.00 NOT NULL');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fiscal_components DROP FOREIGN KEY FK_FISCAL_BUSINESS');
        $this->addSql('ALTER TABLE fiscal_components DROP FOREIGN KEY FK_FISCAL_SALE');
        $this->addSql('ALTER TABLE fiscal_components DROP FOREIGN KEY FK_FISCAL_PURCHASE');
        $this->addSql('DROP TABLE fiscal_components');
        $this->addSql('ALTER TABLE sales DROP fiscal_components_total, DROP taxable_amount, DROP vat_amount, DROP exempt_amount, DROP non_taxed_amount, DROP fiscal_components_snapshot');
        $this->addSql('ALTER TABLE arca_invoices DROP fiscal_components_total, DROP fiscal_components_snapshot, DROP exempt_amount, DROP non_taxed_amount');
    }
}
