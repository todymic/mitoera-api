<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260904000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename plans: mora→plus, soa→max, tsena→base';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE subscriptions SET plan = 'plus'  WHERE plan = 'mora'");
        $this->addSql("UPDATE subscriptions SET plan = 'max'   WHERE plan = 'soa'");
        $this->addSql("UPDATE subscriptions SET plan = 'base'  WHERE plan = 'tsena'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE subscriptions SET plan = 'mora'  WHERE plan = 'plus'");
        $this->addSql("UPDATE subscriptions SET plan = 'soa'   WHERE plan = 'max'");
        $this->addSql("UPDATE subscriptions SET plan = 'tsena' WHERE plan = 'base'");
    }
}
