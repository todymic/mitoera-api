<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260906000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout table refresh_token';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE refresh_token (
                id UUID NOT NULL DEFAULT gen_random_uuid(),
                user_id UUID NOT NULL,
                token VARCHAR(128) NOT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C74F21955F37A13B ON refresh_token (token)');
        $this->addSql('CREATE INDEX IDX_C74F2195A76ED395 ON refresh_token (user_id)');
        $this->addSql('ALTER TABLE refresh_token ADD CONSTRAINT FK_user FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('COMMENT ON COLUMN refresh_token.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN refresh_token.user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN refresh_token.expires_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE refresh_token DROP CONSTRAINT FK_user');
        $this->addSql('DROP TABLE refresh_token');
    }
}
