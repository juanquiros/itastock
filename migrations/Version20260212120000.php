<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260212120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add receiver IVA condition fields for ARCA invoices, customers, and config';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business_arca_configs ADD default_receiver_iva_condition_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE customers ADD iva_condition_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE arca_invoices ADD receiver_mode VARCHAR(20) DEFAULT NULL, ADD receiver_iva_condition_id INT DEFAULT NULL, ADD receiver_customer_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE arca_invoices ADD CONSTRAINT FK_5F9447B5E8F44F75 FOREIGN KEY (receiver_customer_id) REFERENCES customers (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_5F9447B5E8F44F75 ON arca_invoices (receiver_customer_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE arca_invoices DROP FOREIGN KEY FK_5F9447B5E8F44F75');
        $this->addSql('DROP INDEX IDX_5F9447B5E8F44F75 ON arca_invoices');
        $this->addSql('ALTER TABLE arca_invoices DROP receiver_mode, DROP receiver_iva_condition_id, DROP receiver_customer_id');
        $this->addSql('ALTER TABLE customers DROP iva_condition_id');
        $this->addSql('ALTER TABLE business_arca_configs DROP default_receiver_iva_condition_id');
    }
}
