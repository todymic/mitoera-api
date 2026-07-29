<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260729053849 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE seat_usage_logs (id UUID NOT NULL, seat_key VARCHAR(255) NOT NULL, reason VARCHAR(255) NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, event_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6F4C7EF571F7E88B ON seat_usage_logs (event_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_usage_event_seat ON seat_usage_logs (event_id, seat_key)');
        $this->addSql('ALTER TABLE seat_usage_logs ADD CONSTRAINT FK_6F4C7EF571F7E88B FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE events ADD owner_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE events ADD CONSTRAINT FK_5387574A7E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_5387574A7E3C61F9 ON events (owner_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE seat_usage_logs DROP CONSTRAINT FK_6F4C7EF571F7E88B');
        $this->addSql('DROP TABLE seat_usage_logs');
        $this->addSql('ALTER TABLE events DROP CONSTRAINT FK_5387574A7E3C61F9');
        $this->addSql('DROP INDEX IDX_5387574A7E3C61F9');
        $this->addSql('ALTER TABLE events DROP owner_id');
    }
}
