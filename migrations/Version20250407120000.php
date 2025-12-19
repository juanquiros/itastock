<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250407120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add product unit fields, allow fractional quantities, and move quantities to decimal with 3 decimals';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE products ADD uom_base VARCHAR(8) DEFAULT 'UNIT' NOT NULL, ADD allows_fractional_qty TINYINT(1) DEFAULT 0 NOT NULL, ADD qty_step NUMERIC(10, 3) DEFAULT NULL, CHANGE stock stock NUMERIC(10, 3) DEFAULT '0.000' NOT NULL");
        $this->addSql('ALTER TABLE sale_items CHANGE qty qty NUMERIC(10, 3) DEFAULT \'0.000\' NOT NULL');
        $this->addSql('ALTER TABLE stock_movement CHANGE qty qty NUMERIC(10, 3) DEFAULT \'0.000\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products DROP uom_base, DROP allows_fractional_qty, DROP qty_step, CHANGE stock stock INT NOT NULL');
        $this->addSql('ALTER TABLE sale_items CHANGE qty qty INT NOT NULL');
        $this->addSql('ALTER TABLE stock_movement CHANGE qty qty INT NOT NULL');
    }
}
