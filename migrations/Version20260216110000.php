<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260216110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add characteristics and search_text fields to products';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products ADD characteristics JSON DEFAULT NULL, ADD search_text LONGTEXT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_PRODUCTS_SEARCH_TEXT ON products (search_text(255))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_PRODUCTS_SEARCH_TEXT ON products');
        $this->addSql('ALTER TABLE products DROP characteristics, DROP search_text');
    }
}
