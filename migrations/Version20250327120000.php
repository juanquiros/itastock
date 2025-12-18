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
        $this->addSql('CREATE TABLE business (id SERIAL NOT NULL, name VARCHAR(255) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE users (id SERIAL NOT NULL, business_id INT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, full_name VARCHAR(255) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
        $this->addSql('CREATE INDEX IDX_1483A5E9144665A ON users (business_id)');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E9144665A FOREIGN KEY (business_id) REFERENCES business (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql("INSERT INTO business (name) VALUES ('Comercio Demo')");
        $this->addSql(sprintf(
            \"INSERT INTO users (business_id, email, roles, password, full_name) VALUES (1, 'admin@itastock.test', '[\"ROLE_ADMIN\"]', '%s', 'Administrador')\",
            '$2y$12$AU7RffGR0CaFd.ie6Iq1dOUY7n2TM4PXVbmRug3wDaxACJbvYg6qm'
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP CONSTRAINT FK_1483A5E9144665A');
        $this->addSql('DROP TABLE business');
        $this->addSql('DROP TABLE users');
    }
}
