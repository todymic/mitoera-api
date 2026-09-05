<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260905000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove slug column from charts table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE charts DROP COLUMN slug');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE charts ADD COLUMN slug VARCHAR(255) NOT NULL DEFAULT ''");
        $this->addSql('CREATE UNIQUE INDEX UNIQ_989D9B62989D9B62 ON charts (slug)');
    }
}
