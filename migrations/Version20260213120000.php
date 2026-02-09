<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260213120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ARCA credit notes and auto invoice flag for business users';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business_user ADD arca_auto_issue_invoice TINYINT(1) NOT NULL DEFAULT 0');
        $this->addSql('CREATE TABLE arca_credit_notes (id INT AUTO_INCREMENT NOT NULL, business_id INT NOT NULL, sale_id INT NOT NULL, related_invoice_id INT NOT NULL, created_by_id INT DEFAULT NULL, status VARCHAR(20) NOT NULL, arca_pos_number INT NOT NULL, cbte_tipo VARCHAR(30) NOT NULL, cbte_numero INT DEFAULT NULL, cae VARCHAR(50) DEFAULT NULL, cae_due_date DATE DEFAULT NULL, net_amount NUMERIC(10, 2) NOT NULL, vat_amount NUMERIC(10, 2) NOT NULL, total_amount NUMERIC(10, 2) NOT NULL, items_snapshot JSON DEFAULT NULL, arca_raw_request JSON DEFAULT NULL, arca_raw_response JSON DEFAULT NULL, issued_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, reason VARCHAR(255) DEFAULT NULL, UNIQUE INDEX UNIQ_4C8086EB6E20FD55 (sale_id), INDEX IDX_4C8086EB19EB6921 (business_id), INDEX IDX_4C8086EB28829D7 (related_invoice_id), INDEX IDX_4C8086EBB03A8386 (created_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE arca_credit_notes ADD CONSTRAINT FK_4C8086EB19EB6921 FOREIGN KEY (business_id) REFERENCES business (id)');
        $this->addSql('ALTER TABLE arca_credit_notes ADD CONSTRAINT FK_4C8086EB6E20FD55 FOREIGN KEY (sale_id) REFERENCES sales (id)');
        $this->addSql('ALTER TABLE arca_credit_notes ADD CONSTRAINT FK_4C8086EB28829D7 FOREIGN KEY (related_invoice_id) REFERENCES arca_invoices (id)');
        $this->addSql('ALTER TABLE arca_credit_notes ADD CONSTRAINT FK_4C8086EBB03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE arca_credit_notes DROP FOREIGN KEY FK_4C8086EB19EB6921');
        $this->addSql('ALTER TABLE arca_credit_notes DROP FOREIGN KEY FK_4C8086EB6E20FD55');
        $this->addSql('ALTER TABLE arca_credit_notes DROP FOREIGN KEY FK_4C8086EB28829D7');
        $this->addSql('ALTER TABLE arca_credit_notes DROP FOREIGN KEY FK_4C8086EBB03A8386');
        $this->addSql('DROP TABLE arca_credit_notes');
        $this->addSql('ALTER TABLE business_user DROP arca_auto_issue_invoice');
    }
}
