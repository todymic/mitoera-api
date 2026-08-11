<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260811193828 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Workspace isolation: create workspaces/workspace_members tables, add workspace_id FK on events/charts/api_keys, migrate existing data to a default workspace per user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE workspace_members (id UUID NOT NULL, role VARCHAR(20) DEFAULT \'member\' NOT NULL, joined_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, workspace_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9D9D39F482D40A1F ON workspace_members (workspace_id)');
        $this->addSql('CREATE INDEX IDX_9D9D39F4A76ED395 ON workspace_members (user_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9D9D39F482D40A1FA76ED395 ON workspace_members (workspace_id, user_id)');
        $this->addSql('CREATE TABLE workspaces (id UUID NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7FE8F3CB989D9B62 ON workspaces (slug)');
        $this->addSql('ALTER TABLE workspace_members ADD CONSTRAINT FK_9D9D39F482D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE workspace_members ADD CONSTRAINT FK_9D9D39F4A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE api_keys ADD workspace_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE api_keys ADD CONSTRAINT FK_9579321F82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_9579321F82D40A1F ON api_keys (workspace_id)');
        $this->addSql('ALTER TABLE charts ADD workspace_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE charts ADD CONSTRAINT FK_C05050F782D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_C05050F782D40A1F ON charts (workspace_id)');
        $this->addSql('ALTER TABLE events ADD workspace_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE events ALTER hold_duration_minutes SET DEFAULT 10');
        $this->addSql('ALTER TABLE events ADD CONSTRAINT FK_5387574A82D40A1F FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_5387574A82D40A1F ON events (workspace_id)');

        // Migration des données existantes :
        // Pour chaque utilisateur existant, créer un workspace et lui rattacher toutes ses données.
        $users = $this->connection->fetchAllAssociative('SELECT id, email, first_name, last_name FROM users');
        foreach ($users as $user) {
            $wsId   = \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
            $name   = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: explode('@', $user['email'])[0];
            $slug   = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
            $slug   = trim($slug, '-') ?: 'workspace';
            $memberId = \Symfony\Component\Uid\Uuid::v7()->toRfc4122();

            $this->addSql('INSERT INTO workspaces (id, name, slug, created_at) VALUES (:id, :name, :slug, NOW())', [
                'id' => $wsId, 'name' => $name, 'slug' => $slug,
            ]);
            $this->addSql('INSERT INTO workspace_members (id, workspace_id, user_id, role, joined_at) VALUES (:id, :ws, :user, \'owner\', NOW())', [
                'id' => $memberId, 'ws' => $wsId, 'user' => $user['id'],
            ]);

            // Rattacher events/charts/api_keys au premier utilisateur trouvé (admin)
            if ($user === $users[0]) {
                $this->addSql('UPDATE events SET workspace_id = :ws WHERE workspace_id IS NULL', ['ws' => $wsId]);
                $this->addSql('UPDATE charts SET workspace_id = :ws WHERE workspace_id IS NULL', ['ws' => $wsId]);
                $this->addSql('UPDATE api_keys SET workspace_id = :ws WHERE workspace_id IS NULL', ['ws' => $wsId]);
            }
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE workspace_members DROP CONSTRAINT FK_9D9D39F482D40A1F');
        $this->addSql('ALTER TABLE workspace_members DROP CONSTRAINT FK_9D9D39F4A76ED395');
        $this->addSql('DROP TABLE workspace_members');
        $this->addSql('DROP TABLE workspaces');
        $this->addSql('ALTER TABLE api_keys DROP CONSTRAINT FK_9579321F82D40A1F');
        $this->addSql('DROP INDEX IDX_9579321F82D40A1F');
        $this->addSql('ALTER TABLE api_keys DROP workspace_id');
        $this->addSql('ALTER TABLE charts DROP CONSTRAINT FK_C05050F782D40A1F');
        $this->addSql('DROP INDEX IDX_C05050F782D40A1F');
        $this->addSql('ALTER TABLE charts DROP workspace_id');
        $this->addSql('ALTER TABLE events DROP CONSTRAINT FK_5387574A82D40A1F');
        $this->addSql('DROP INDEX IDX_5387574A82D40A1F');
        $this->addSql('ALTER TABLE events DROP workspace_id');
        $this->addSql('ALTER TABLE events ALTER hold_duration_minutes SET DEFAULT 15');
    }
}
