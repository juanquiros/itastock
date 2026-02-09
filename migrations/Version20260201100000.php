<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260201100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ARCA configuration, invoices, token cache, and VAT rates';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE business_arca_configs (id INT AUTO_INCREMENT NOT NULL, business_id INT NOT NULL, arca_enabled TINYINT(1) NOT NULL, arca_environment VARCHAR(10) NOT NULL, cuit_emisor VARCHAR(20) DEFAULT NULL, cert_pem LONGTEXT DEFAULT NULL, private_key_pem LONGTEXT DEFAULT NULL, passphrase VARCHAR(255) DEFAULT NULL, tax_payer_type VARCHAR(30) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_7E66C9B977F32F0A (business_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE arca_invoices (id INT AUTO_INCREMENT NOT NULL, business_id INT NOT NULL, sale_id INT NOT NULL, created_by_id INT DEFAULT NULL, status VARCHAR(20) NOT NULL, arca_pos_number INT NOT NULL, cbte_tipo VARCHAR(30) NOT NULL, cbte_numero INT DEFAULT NULL, cae VARCHAR(50) DEFAULT NULL, cae_due_date DATE DEFAULT NULL, net_amount NUMERIC(10, 2) NOT NULL, vat_amount NUMERIC(10, 2) NOT NULL, total_amount NUMERIC(10, 2) NOT NULL, items_snapshot JSON DEFAULT NULL, arca_raw_request JSON DEFAULT NULL, arca_raw_response JSON DEFAULT NULL, issued_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_5F9447B56E20FD55 (sale_id), INDEX IDX_5F9447B519EB6921 (business_id), INDEX IDX_5F9447B5B03A8386 (created_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE arca_token_cache (id INT AUTO_INCREMENT NOT NULL, business_id INT NOT NULL, service VARCHAR(30) NOT NULL, environment VARCHAR(10) NOT NULL, token LONGTEXT NOT NULL, sign LONGTEXT NOT NULL, expires_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_arca_token_cache (business_id, service, environment), INDEX IDX_67D07D8919EB6921 (business_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE business_user ADD arca_pos_number INT DEFAULT NULL, ADD arca_mode VARCHAR(20) NOT NULL DEFAULT \'REMITO_ONLY\', ADD arca_enabled_for_this_cashier TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE products ADD iva_rate NUMERIC(5, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE categories ADD iva_rate NUMERIC(5, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE business_arca_configs ADD CONSTRAINT FK_7E66C9B977F32F0A FOREIGN KEY (business_id) REFERENCES business (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE arca_invoices ADD CONSTRAINT FK_5F9447B519EB6921 FOREIGN KEY (business_id) REFERENCES business (id)');
        $this->addSql('ALTER TABLE arca_invoices ADD CONSTRAINT FK_5F9447B56E20FD55 FOREIGN KEY (sale_id) REFERENCES sales (id)');
        $this->addSql('ALTER TABLE arca_invoices ADD CONSTRAINT FK_5F9447B5B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE arca_token_cache ADD CONSTRAINT FK_67D07D8919EB6921 FOREIGN KEY (business_id) REFERENCES business (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business_arca_configs DROP FOREIGN KEY FK_7E66C9B977F32F0A');
        $this->addSql('ALTER TABLE arca_invoices DROP FOREIGN KEY FK_5F9447B519EB6921');
        $this->addSql('ALTER TABLE arca_invoices DROP FOREIGN KEY FK_5F9447B56E20FD55');
        $this->addSql('ALTER TABLE arca_invoices DROP FOREIGN KEY FK_5F9447B5B03A8386');
        $this->addSql('ALTER TABLE arca_token_cache DROP FOREIGN KEY FK_67D07D8919EB6921');
        $this->addSql('DROP TABLE business_arca_configs');
        $this->addSql('DROP TABLE arca_invoices');
        $this->addSql('DROP TABLE arca_token_cache');
        $this->addSql('ALTER TABLE business_user DROP arca_pos_number, DROP arca_mode, DROP arca_enabled_for_this_cashier');
        $this->addSql('ALTER TABLE products DROP iva_rate');
        $this->addSql('ALTER TABLE categories DROP iva_rate');
    }
}
