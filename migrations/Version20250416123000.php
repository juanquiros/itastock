<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250416123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add last_attempt_at to Mercado Pago subscription links.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mercado_pago_subscription_links ADD last_attempt_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE mercado_pago_subscription_links DROP last_attempt_at');
    }
}
