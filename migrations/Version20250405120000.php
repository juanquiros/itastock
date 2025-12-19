<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250405120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create public pages, plans, and leads tables for public website';
    }

    public function up(Schema $schema): void
    {
        $publicPages = $schema->createTable('public_pages');
        $publicPages->addColumn('id', 'integer', ['autoincrement' => true]);
        $publicPages->addColumn('slug', 'string', ['length' => 150]);
        $publicPages->addColumn('title', 'string', ['length' => 180]);
        $publicPages->addColumn('meta_description', 'string', ['length' => 255, 'notnull' => false]);
        $publicPages->addColumn('body_html', 'text');
        $publicPages->addColumn('is_published', 'boolean', ['default' => false]);
        $publicPages->addColumn('updated_at', 'datetime_immutable');
        $publicPages->setPrimaryKey(['id']);
        $publicPages->addUniqueIndex(['slug'], 'uniq_public_page_slug');

        $plans = $schema->createTable('plans');
        $plans->addColumn('id', 'integer', ['autoincrement' => true]);
        $plans->addColumn('code', 'string', ['length' => 80]);
        $plans->addColumn('name', 'string', ['length' => 180]);
        $plans->addColumn('price_monthly', 'decimal', ['precision' => 10, 'scale' => 2]);
        $plans->addColumn('currency', 'string', ['length' => 10, 'default' => 'ARS']);
        $plans->addColumn('features_json', 'text', ['notnull' => false]);
        $plans->addColumn('is_active', 'boolean', ['default' => true]);
        $plans->addColumn('is_featured', 'boolean', ['default' => false]);
        $plans->addColumn('sort_order', 'integer', ['default' => 0]);
        $plans->setPrimaryKey(['id']);
        $plans->addUniqueIndex(['code'], 'uniq_plan_code');

        $leads = $schema->createTable('leads');
        $leads->addColumn('id', 'integer', ['autoincrement' => true]);
        $leads->addColumn('name', 'string', ['length' => 150]);
        $leads->addColumn('email', 'string', ['length' => 180]);
        $leads->addColumn('phone', 'string', ['length' => 50, 'notnull' => false]);
        $leads->addColumn('message', 'text');
        $leads->addColumn('created_at', 'datetime_immutable');
        $leads->addColumn('source', 'string', ['length' => 120, 'notnull' => false]);
        $leads->setPrimaryKey(['id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('leads');
        $schema->dropTable('plans');
        $schema->dropTable('public_pages');
    }
}
