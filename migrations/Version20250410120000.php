<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250410120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sale voiding audit fields and payment references';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE sales ADD status VARCHAR(16) DEFAULT 'CONFIRMED' NOT NULL, ADD voided_at DATETIME DEFAULT NULL, ADD voided_by_id INT DEFAULT NULL, ADD void_reason LONGTEXT DEFAULT NULL");
        $this->addSql('UPDATE sales SET status = \'CONFIRMED\' WHERE status IS NULL');
        $this->addSql('ALTER TABLE sales ADD CONSTRAINT FK_6B62F1AF31BA366C FOREIGN KEY (voided_by_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_6B62F1AF31BA366C ON sales (voided_by_id)');

        $this->addSql('ALTER TABLE payments ADD reference_type VARCHAR(32) DEFAULT NULL, ADD reference_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payments DROP reference_type, DROP reference_id');
        $this->addSql('ALTER TABLE sales DROP FOREIGN KEY FK_6B62F1AF31BA366C');
        $this->addSql('DROP INDEX IDX_6B62F1AF31BA366C ON sales');
        $this->addSql('ALTER TABLE sales DROP status, DROP voided_at, DROP voided_by_id, DROP void_reason');
    }
}
