<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
final class Version20260521170000 extends AbstractMigration
{
    public function getDescription(): string { return 'Defensive fix fiscal_components business FK to business(id)'; }
    public function up(Schema $schema): void
    {
        $sm = $this->connection->createSchemaManager();
        if (!$sm->tablesExist(['fiscal_components'])) { return; }
        $table = $sm->introspectTable('fiscal_components');
        foreach ($table->getForeignKeys() as $fk) {
            if (in_array('business_id', $fk->getLocalColumns(), true) && strtolower($fk->getForeignTableName()) !== 'business') {
                $this->addSql('ALTER TABLE fiscal_components DROP FOREIGN KEY '.$fk->getName());
            }
        }
        $table = $sm->introspectTable('fiscal_components');
        $has = false;
        foreach ($table->getForeignKeys() as $fk) {
            if (in_array('business_id', $fk->getLocalColumns(), true) && strtolower($fk->getForeignTableName()) === 'business') { $has = true; }
        }
        if (!$has) {
            $this->addSql('ALTER TABLE fiscal_components ADD CONSTRAINT FK_FISCAL_BUSINESS FOREIGN KEY (business_id) REFERENCES business (id)');
        }
    }
    public function down(Schema $schema): void {}
}
