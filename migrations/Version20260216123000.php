<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260216123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add IVA rate storage to sale items and default generic IVA config to ARCA settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sale_items ADD iva_rate NUMERIC(5, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE business_arca_configs ADD generic_item_iva_enabled TINYINT(1) DEFAULT 1 NOT NULL, ADD generic_item_iva_rate NUMERIC(5, 2) DEFAULT 21.00 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sale_items DROP iva_rate');
        $this->addSql('ALTER TABLE business_arca_configs DROP generic_item_iva_enabled, DROP generic_item_iva_rate');
    }
}
