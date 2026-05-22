<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20260522121000 extends AbstractMigration
{
    public function getDescription(): string { return 'Defensive FK fix for fiscal_rules references'; }
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('fiscal_rules')) { return; }
        $sm = $this->connection->createSchemaManager();
        $table = $sm->introspectTable('fiscal_rules');
        foreach ($table->getForeignKeys() as $fk) {
            $foreign = strtolower($fk->getForeignTableName());
            if (in_array($foreign, ['product','category','customer'], true)) {
                $this->addSql('ALTER TABLE fiscal_rules DROP FOREIGN KEY '.$fk->getName());
            }
        }
        $table = $sm->introspectTable('fiscal_rules');
        $has = fn(string $name)=>array_key_exists($name, $table->getForeignKeys());
        if (!$has('FK_FISCAL_RULE_PRODUCT')) $this->addSql('ALTER TABLE fiscal_rules ADD CONSTRAINT FK_FISCAL_RULE_PRODUCT FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE');
        if (!$has('FK_FISCAL_RULE_CATEGORY')) $this->addSql('ALTER TABLE fiscal_rules ADD CONSTRAINT FK_FISCAL_RULE_CATEGORY FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE');
        if (!$has('FK_FISCAL_RULE_CUSTOMER')) $this->addSql('ALTER TABLE fiscal_rules ADD CONSTRAINT FK_FISCAL_RULE_CUSTOMER FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE CASCADE');
    }
    public function down(Schema $schema): void {}
}
