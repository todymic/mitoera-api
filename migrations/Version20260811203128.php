<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260811203128 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE workspace_invitations (id UUID NOT NULL, email VARCHAR(180) NOT NULL, token VARCHAR(64) NOT NULL, status VARCHAR(20) DEFAULT \'pending\' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, workspace_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B371BAB65F37A13B ON workspace_invitations (token)');
        $this->addSql('CREATE INDEX IDX_B371BAB682D40A1F ON workspace_invitations (workspace_id)');
        $this->addSql('ALTER TABLE workspace_invitations ADD CONSTRAINT FK_B371BAB682D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE workspace_invitations DROP CONSTRAINT FK_B371BAB682D40A1F');
        $this->addSql('DROP TABLE workspace_invitations');
    }
}
