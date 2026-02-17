<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260217120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add label export jobs and batches';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE label_export_job (id INT AUTO_INCREMENT NOT NULL, business_id INT NOT NULL, created_by_user_id INT DEFAULT NULL, type VARCHAR(32) NOT NULL, status VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", expires_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", params JSON NOT NULL, base_path VARCHAR(255) NOT NULL, batches_count INT NOT NULL, total_products INT NOT NULL, zip_filename VARCHAR(255) DEFAULT NULL, error_message LONGTEXT DEFAULT NULL, INDEX idx_label_export_job_business_created_at (business_id, created_at), INDEX IDX_A754C93989D9B62 (business_id), INDEX IDX_A754C93E57D335A (created_by_user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE label_export_batch (id INT AUTO_INCREMENT NOT NULL, job_id INT NOT NULL, batch_index INT NOT NULL, from_product_id INT DEFAULT NULL, to_product_id INT DEFAULT NULL, products_count INT NOT NULL, filename VARCHAR(255) NOT NULL, status VARCHAR(16) NOT NULL, INDEX idx_label_export_batch_job_index (job_id, batch_index), INDEX IDX_3AE6369DBA32B32A (job_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE label_export_job ADD CONSTRAINT FK_A754C93989D9B62 FOREIGN KEY (business_id) REFERENCES business (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE label_export_job ADD CONSTRAINT FK_A754C93E57D335A FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE label_export_batch ADD CONSTRAINT FK_3AE6369DBA32B32A FOREIGN KEY (job_id) REFERENCES label_export_job (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE label_export_job DROP FOREIGN KEY FK_A754C93989D9B62');
        $this->addSql('ALTER TABLE label_export_job DROP FOREIGN KEY FK_A754C93E57D335A');
        $this->addSql('ALTER TABLE label_export_batch DROP FOREIGN KEY FK_3AE6369DBA32B32A');
        $this->addSql('DROP TABLE label_export_job');
        $this->addSql('DROP TABLE label_export_batch');
    }
}
