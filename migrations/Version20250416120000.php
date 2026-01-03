<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250416120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Mercado Pago subscription link history';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mercado_pago_subscription_links (id INT AUTO_INCREMENT NOT NULL, business_id INT NOT NULL, mp_preapproval_id VARCHAR(128) NOT NULL, status VARCHAR(32) NOT NULL, is_primary TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_mp_subscription_link_preapproval (mp_preapproval_id), INDEX idx_mp_subscription_link_business (business_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE mercado_pago_subscription_links ADD CONSTRAINT FK_MP_SUB_LINK_BUSINESS FOREIGN KEY (business_id) REFERENCES businesses (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mercado_pago_subscription_links DROP FOREIGN KEY FK_MP_SUB_LINK_BUSINESS');
        $this->addSql('DROP TABLE mercado_pago_subscription_links');
    }
}
