<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260217180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add async label export jobs/batches tables and messenger queue table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", available_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", delivered_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", INDEX IDX_75EA56E0FB7336F0 (queue_name), INDEX IDX_75EA56E0E3BD61CE (available_at), INDEX IDX_75EA56E016BA31DB (delivered_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE label_export_jobs (id INT AUTO_INCREMENT NOT NULL, business_id INT NOT NULL, created_by_id INT NOT NULL, status VARCHAR(16) NOT NULL, total_products INT NOT NULL, total_batches INT NOT NULL, done_batches INT NOT NULL, progress_percent INT NOT NULL, progress_text VARCHAR(255) NOT NULL, batch_size INT NOT NULL, created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", started_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", finished_at DATETIME DEFAULT NULL COMMENT "(DC2Type:datetime_immutable)", expires_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", zip_filename VARCHAR(255) DEFAULT NULL, error_message LONGTEXT DEFAULT NULL, filters JSON NOT NULL, INDEX IDX_795D1C8A8902B4B (business_id), INDEX IDX_795D1C8B03A8386 (created_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE label_export_batches (id INT AUTO_INCREMENT NOT NULL, job_id INT NOT NULL, batch_index INT NOT NULL, product_count INT NOT NULL, filename VARCHAR(255) DEFAULT NULL, status VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL COMMENT "(DC2Type:datetime_immutable)", INDEX IDX_7E527778BE04EA9 (job_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE label_export_jobs ADD CONSTRAINT FK_795D1C8A8902B4B FOREIGN KEY (business_id) REFERENCES businesses (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE label_export_jobs ADD CONSTRAINT FK_795D1C8B03A8386 FOREIGN KEY (created_by_id) REFERENCES users (id)');
        $this->addSql('ALTER TABLE label_export_batches ADD CONSTRAINT FK_7E527778BE04EA9 FOREIGN KEY (job_id) REFERENCES label_export_jobs (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE label_export_batches DROP FOREIGN KEY FK_7E527778BE04EA9');
        $this->addSql('ALTER TABLE label_export_jobs DROP FOREIGN KEY FK_795D1C8A8902B4B');
        $this->addSql('ALTER TABLE label_export_jobs DROP FOREIGN KEY FK_795D1C8B03A8386');
        $this->addSql('DROP TABLE label_export_batches');
        $this->addSql('DROP TABLE label_export_jobs');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
