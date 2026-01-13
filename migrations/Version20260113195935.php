<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260113195935 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add updated_at to products for label filtering';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE products ADD updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql('UPDATE products SET updated_at = NOW() WHERE updated_at IS NULL');
        $this->addSql("ALTER TABLE products CHANGE updated_at updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products DROP updated_at');
    }
}
