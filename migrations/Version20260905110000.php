<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260905110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop global unique index on events.identifier (PostgreSQL stores names lowercase)';
    }

    public function up(Schema $schema): void
    {
        // The previous migration used a quoted uppercase name which PostgreSQL didn't match.
        // PostgreSQL stores unquoted index names in lowercase.
        $this->addSql('DROP INDEX IF EXISTS uniq_5387574a772e836a');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_5387574a772e836a ON events (identifier)');
    }
}
