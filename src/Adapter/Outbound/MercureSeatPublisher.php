<?php

namespace App\Adapter\Outbound;

use App\Port\SeatPublisherPort;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Throwable;

readonly class MercureSeatPublisher implements SeatPublisherPort
{
    public function __construct(private HubInterface $hub) {}

    public function publishSeatChanges(string $eventId, array $changes): void
    {
        try {
            $this->hub->publish(new Update(
                "event/$eventId/seats",
                json_encode($changes),
            ));
        } catch (Throwable $e) {
            error_log('[Mercure] Failed to publish: ' . $e->getMessage());
        }
    }
}
