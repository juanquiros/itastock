<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250327121500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reset password token fields to users';
    }

    public function up(Schema $schema): void
    {
        $users = $schema->getTable('users');
        $users->addColumn('reset_token', 'string', ['length' => 64, 'notnull' => false]);
        $users->addColumn('reset_requested_at', 'datetime_immutable', ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $users = $schema->getTable('users');
        $users->dropColumn('reset_token');
        $users->dropColumn('reset_requested_at');
    }
}
