<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260702000000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE charts ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'draft'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE charts DROP COLUMN status');
    }
}
