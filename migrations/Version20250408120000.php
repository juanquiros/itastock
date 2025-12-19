<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250408120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow fractional stock minimums by changing products.stock_min to decimal(10,3)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE products CHANGE stock_min stock_min NUMERIC(10, 3) DEFAULT '0.000' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products CHANGE stock_min stock_min INT NOT NULL');
    }
}
