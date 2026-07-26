<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260629202000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scope categories per chart with chart_id foreign key and chart-level unique key';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE categories ADD chart_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_3AF34668BEF83E0A ON categories (chart_id)');
        $this->addSql('ALTER TABLE categories ADD CONSTRAINT FK_3AF34668BEF83E0A FOREIGN KEY (chart_id) REFERENCES charts (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('DROP INDEX IF EXISTS UNIQ_3AF346688A90ABA9');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3AF34668C05050F88A90ABA9 ON categories (chart_id, key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS UNIQ_3AF34668C05050F88A90ABA9');
        $this->addSql('ALTER TABLE categories DROP CONSTRAINT IF EXISTS FK_3AF34668BEF83E0A');
        $this->addSql('DROP INDEX IF EXISTS IDX_3AF34668BEF83E0A');
        $this->addSql('ALTER TABLE categories DROP COLUMN IF EXISTS chart_id');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3AF346688A90ABA9 ON categories (key)');
    }
}

