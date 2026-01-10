<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250416150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add catalog master tables and brand links for products';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE catalog_category (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, slug VARCHAR(150) NOT NULL, is_active TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_catalog_category_slug (slug), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE catalog_brand (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, slug VARCHAR(150) NOT NULL, logo_path VARCHAR(255) DEFAULT NULL, is_active TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_catalog_brand_name (name), UNIQUE INDEX uniq_catalog_brand_slug (slug), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE catalog_product (id INT AUTO_INCREMENT NOT NULL, category_id INT NOT NULL, brand_id INT DEFAULT NULL, name VARCHAR(255) NOT NULL, presentation VARCHAR(100) DEFAULT NULL, barcode VARCHAR(50) DEFAULT NULL, sku VARCHAR(100) DEFAULT NULL, is_active TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_catalog_product_barcode (barcode), INDEX IDX_CATALOG_PRODUCT_CATEGORY (category_id), INDEX IDX_CATALOG_PRODUCT_BRAND (brand_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE catalog_product ADD CONSTRAINT FK_CATALOG_PRODUCT_CATEGORY FOREIGN KEY (category_id) REFERENCES catalog_category (id)');
        $this->addSql('ALTER TABLE catalog_product ADD CONSTRAINT FK_CATALOG_PRODUCT_BRAND FOREIGN KEY (brand_id) REFERENCES catalog_brand (id) ON DELETE SET NULL');

        $this->addSql('CREATE TABLE brand (id INT AUTO_INCREMENT NOT NULL, business_id INT NOT NULL, name VARCHAR(150) NOT NULL, slug VARCHAR(150) NOT NULL, logo_path VARCHAR(255) DEFAULT NULL, is_active TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_brand_business_slug (business_id, slug), UNIQUE INDEX uniq_brand_business_name (business_id, name), INDEX IDX_BRAND_BUSINESS (business_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE brand ADD CONSTRAINT FK_BRAND_BUSINESS FOREIGN KEY (business_id) REFERENCES business (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE categories ADD catalog_category_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE categories ADD CONSTRAINT FK_CATEGORIES_CATALOG_CATEGORY FOREIGN KEY (catalog_category_id) REFERENCES catalog_category (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_CATEGORIES_CATALOG_CATEGORY ON categories (catalog_category_id)');

        $this->addSql('ALTER TABLE products ADD brand_id INT DEFAULT NULL, ADD catalog_product_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD CONSTRAINT FK_PRODUCTS_BRAND FOREIGN KEY (brand_id) REFERENCES brand (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE products ADD CONSTRAINT FK_PRODUCTS_CATALOG_PRODUCT FOREIGN KEY (catalog_product_id) REFERENCES catalog_product (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_PRODUCTS_BRAND ON products (brand_id)');
        $this->addSql('CREATE INDEX IDX_PRODUCTS_CATALOG_PRODUCT ON products (catalog_product_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE products DROP FOREIGN KEY FK_PRODUCTS_BRAND');
        $this->addSql('ALTER TABLE products DROP FOREIGN KEY FK_PRODUCTS_CATALOG_PRODUCT');
        $this->addSql('DROP INDEX IDX_PRODUCTS_BRAND ON products');
        $this->addSql('DROP INDEX IDX_PRODUCTS_CATALOG_PRODUCT ON products');
        $this->addSql('ALTER TABLE products DROP brand_id, DROP catalog_product_id');

        $this->addSql('ALTER TABLE categories DROP FOREIGN KEY FK_CATEGORIES_CATALOG_CATEGORY');
        $this->addSql('DROP INDEX IDX_CATEGORIES_CATALOG_CATEGORY ON categories');
        $this->addSql('ALTER TABLE categories DROP catalog_category_id');

        $this->addSql('ALTER TABLE brand DROP FOREIGN KEY FK_BRAND_BUSINESS');
        $this->addSql('DROP TABLE brand');

        $this->addSql('ALTER TABLE catalog_product DROP FOREIGN KEY FK_CATALOG_PRODUCT_CATEGORY');
        $this->addSql('ALTER TABLE catalog_product DROP FOREIGN KEY FK_CATALOG_PRODUCT_BRAND');
        $this->addSql('DROP TABLE catalog_product');
        $this->addSql('DROP TABLE catalog_brand');
        $this->addSql('DROP TABLE catalog_category');
    }
}
