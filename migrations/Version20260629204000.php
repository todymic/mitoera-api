<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260629204000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalize legacy uppercase seat statuses to lowercase enum backing values';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE event_seats SET status = 'available' WHERE status = 'AVAILABLE'");
        $this->addSql("UPDATE event_seats SET status = 'hold' WHERE status = 'HOLD'");
        $this->addSql("UPDATE event_seats SET status = 'booked' WHERE status = 'BOOKED'");
        $this->addSql("UPDATE event_seats SET status = 'canceled' WHERE status = 'CANCELED'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE event_seats SET status = 'AVAILABLE' WHERE status = 'available'");
        $this->addSql("UPDATE event_seats SET status = 'HOLD' WHERE status = 'hold'");
        $this->addSql("UPDATE event_seats SET status = 'BOOKED' WHERE status = 'booked'");
        $this->addSql("UPDATE event_seats SET status = 'CANCELED' WHERE status = 'canceled'");
    }
}

