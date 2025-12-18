<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250327120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create business and users tables with initial admin seed';
    }

    public function up(Schema $schema): void
    {
        $business = $schema->createTable('business');
        $business->addColumn('id', 'integer', ['autoincrement' => true]);
        $business->addColumn('name', 'string', ['length' => 255]);
        $business->setPrimaryKey(['id']);

        $users = $schema->createTable('users');
        $users->addColumn('id', 'integer', ['autoincrement' => true]);
        $users->addColumn('business_id', 'integer');
        $users->addColumn('email', 'string', ['length' => 180]);
        $users->addColumn('roles', 'json');
        $users->addColumn('password', 'string', ['length' => 255]);
        $users->addColumn('full_name', 'string', ['length' => 255]);
        $users->setPrimaryKey(['id']);
        $users->addUniqueIndex(['email'], 'UNIQ_1483A5E9E7927C74');
        $users->addIndex(['business_id'], 'IDX_1483A5E9144665A');
        $users->addForeignKeyConstraint('business', ['business_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_1483A5E9144665A');

        $this->addSql("INSERT INTO business (name) VALUES ('Comercio Demo')");
        $this->addSql(sprintf(
            "INSERT INTO users (business_id, email, roles, password, full_name) VALUES (1, 'admin@itastock.test', '[\"ROLE_ADMIN\"]', '%s', 'Administrador')",
            '$2y$12$AU7RffGR0CaFd.ie6Iq1dOUY7n2TM4PXVbmRug3wDaxACJbvYg6qm'
        ));
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('users')->removeForeignKey('FK_1483A5E9144665A');
        $schema->dropTable('users');
        $schema->dropTable('business');
    }
}
