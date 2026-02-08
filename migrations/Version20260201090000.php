<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260201090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add public visit tracking for public pages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE public_visit (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, ip VARCHAR(45) NOT NULL, ip_hash VARCHAR(64) NOT NULL, method VARCHAR(10) NOT NULL, route_name VARCHAR(128) NOT NULL, path VARCHAR(255) NOT NULL, query_string LONGTEXT DEFAULT NULL, referer LONGTEXT DEFAULT NULL, user_agent LONGTEXT DEFAULT NULL, status_code SMALLINT NOT NULL, utm_source VARCHAR(128) DEFAULT NULL, utm_medium VARCHAR(128) DEFAULT NULL, utm_campaign VARCHAR(128) DEFAULT NULL, utm_content VARCHAR(128) DEFAULT NULL, utm_term VARCHAR(128) DEFAULT NULL, INDEX idx_public_visit_created_at (created_at), INDEX idx_public_visit_route_name (route_name), INDEX idx_public_visit_ip_hash (ip_hash), INDEX idx_public_visit_utm_source (utm_source), INDEX idx_public_visit_status_code (status_code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE public_visit');
    }
}
