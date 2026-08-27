<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260827000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add subscription system: subscriptions, seat_usages, surplus_invoices, subscription_events';
    }

    public function up(Schema $schema): void
    {
        // subscriptions — one per workspace, annual prepaid plan
        $this->addSql(<<<'SQL'
            CREATE TABLE subscriptions (
                id                  UUID        NOT NULL,
                workspace_id        UUID        NOT NULL,
                plan                VARCHAR(20) NOT NULL,
                stripe_subscription_id VARCHAR(255) DEFAULT NULL,
                stripe_customer_id  VARCHAR(255) DEFAULT NULL,
                status              VARCHAR(30) NOT NULL DEFAULT 'active',
                annual_seat_quota   INT         NOT NULL,
                surplus_price_cents INT         NOT NULL,
                period_start        DATE        NOT NULL,
                period_end          DATE        NOT NULL,
                created_at          TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at          TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_subscriptions_workspace ON subscriptions (workspace_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_subscriptions_stripe_sub_id ON subscriptions (stripe_subscription_id)');
        $this->addSql('ALTER TABLE subscriptions ADD CONSTRAINT fk_subscriptions_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        // seat_usages — one row per subscription, cumulative annual counters
        $this->addSql(<<<'SQL'
            CREATE TABLE seat_usages (
                id                    UUID NOT NULL,
                subscription_id       UUID NOT NULL,
                seats_used_cumul      INT  NOT NULL DEFAULT 0,
                surplus_billed_cumul  INT  NOT NULL DEFAULT 0,
                updated_at            TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_seat_usage_subscription ON seat_usages (subscription_id)');
        $this->addSql('ALTER TABLE seat_usages ADD CONSTRAINT fk_seat_usages_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        // surplus_invoices — one row per month where surplus was billed
        $this->addSql(<<<'SQL'
            CREATE TABLE surplus_invoices (
                id                      UUID         NOT NULL,
                subscription_id         UUID         NOT NULL,
                billed_month            DATE         NOT NULL,
                seats_billed            INT          NOT NULL,
                amount_cents            INT          NOT NULL,
                stripe_invoice_item_id  VARCHAR(255) DEFAULT NULL,
                created_at              TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_surplus_month ON surplus_invoices (subscription_id, billed_month)');
        $this->addSql('ALTER TABLE surplus_invoices ADD CONSTRAINT fk_surplus_invoices_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        // subscription_events — webhook idempotence log
        $this->addSql(<<<'SQL'
            CREATE TABLE subscription_events (
                id               UUID         NOT NULL,
                subscription_id  UUID         DEFAULT NULL,
                stripe_event_id  VARCHAR(255) NOT NULL,
                type             VARCHAR(255) NOT NULL,
                processed_at     TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_subscription_events_stripe_id ON subscription_events (stripe_event_id)');
        $this->addSql('ALTER TABLE subscription_events ADD CONSTRAINT fk_subscription_events_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription_events DROP CONSTRAINT fk_subscription_events_subscription');
        $this->addSql('ALTER TABLE surplus_invoices DROP CONSTRAINT fk_surplus_invoices_subscription');
        $this->addSql('ALTER TABLE seat_usages DROP CONSTRAINT fk_seat_usages_subscription');
        $this->addSql('ALTER TABLE subscriptions DROP CONSTRAINT fk_subscriptions_workspace');
        $this->addSql('DROP TABLE subscription_events');
        $this->addSql('DROP TABLE surplus_invoices');
        $this->addSql('DROP TABLE seat_usages');
        $this->addSql('DROP TABLE subscriptions');
    }
}
