<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260902000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Category key: varchar -> integer';
    }

    public function up(Schema $schema): void
    {
        // Remplacer les clés vides par 0 avant le cast
        $this->addSql("UPDATE categories SET key = '0' WHERE key = '' OR key IS NULL");
        $this->addSql('ALTER TABLE categories ALTER COLUMN key TYPE INTEGER USING key::INTEGER');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE categories ALTER COLUMN key TYPE VARCHAR(255) USING key::VARCHAR');
    }
}
