<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260114120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add business user memberships and Mercado Pago payer email on business';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE business_user (id INT AUTO_INCREMENT NOT NULL, business_id INT NOT NULL, user_id INT NOT NULL, role VARCHAR(30) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX uniq_business_user (business_id, user_id), INDEX idx_business_user_business (business_id), INDEX idx_business_user_user (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE business_user ADD CONSTRAINT FK_BUSINESS_USER_BUSINESS FOREIGN KEY (business_id) REFERENCES business (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE business_user ADD CONSTRAINT FK_BUSINESS_USER_USER FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE business ADD mercado_pago_payer_email VARCHAR(180) DEFAULT NULL');
        $this->addSql('INSERT INTO business_user (business_id, user_id, role, is_active, created_at, updated_at)
            SELECT u.business_id,
                u.id,
                CASE
                    WHEN u.roles LIKE \'%ROLE_ADMIN%\' OR u.roles LIKE \'%ROLE_BUSINESS_ADMIN%\' THEN \'ADMIN\'
                    ELSE \'SELLER\'
                END,
                1,
                NOW(),
                NOW()
            FROM users u
            WHERE u.business_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE business_user DROP FOREIGN KEY FK_BUSINESS_USER_BUSINESS');
        $this->addSql('ALTER TABLE business_user DROP FOREIGN KEY FK_BUSINESS_USER_USER');
        $this->addSql('DROP TABLE business_user');
        $this->addSql('ALTER TABLE business DROP mercado_pago_payer_email');
    }
}
