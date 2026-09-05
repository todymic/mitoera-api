<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260905100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace global unique index on events.identifier with a per-workspace compound index';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS "UNIQ_5387574A772E836A"');

        // Add compound unique index: identifier is unique per workspace
        $this->addSql('CREATE UNIQUE INDEX uniq_event_identifier_workspace ON events (identifier, workspace_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_event_identifier_workspace');
        $this->addSql('CREATE UNIQUE INDEX "UNIQ_5387574A772E836A" ON events (identifier)');
    }
}
