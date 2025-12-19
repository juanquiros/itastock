<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250404120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add indexes for sales, stock movements, and customer account movements to improve report queries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IDX_SALES_BUSINESS_CREATED_AT ON sales (business_id, created_at)');
        $this->addSql('CREATE INDEX IDX_STOCK_MOVEMENT_PRODUCT_CREATED_AT ON stock_movement (product_id, created_at)');
        $this->addSql('CREATE INDEX IDX_CUSTOMER_ACCOUNT_MOVEMENTS_CUSTOMER_CREATED_AT ON customer_account_movements (customer_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_SALES_BUSINESS_CREATED_AT');
        $this->addSql('DROP INDEX IDX_STOCK_MOVEMENT_PRODUCT_CREATED_AT');
        $this->addSql('DROP INDEX IDX_CUSTOMER_ACCOUNT_MOVEMENTS_CUSTOMER_CREATED_AT');
    }
}
