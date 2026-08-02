<?php

namespace App\Port;

/**
 * Port (outbound) — publication des changements de statut de siège.
 * Les adapters (Mercure, Firestore, …) implémentent ce contrat.
 */
interface SeatPublisherPort
{
    /**
     * @param list<array{seatKey: string, status: string}> $changes
     */
    public function publishSeatChanges(string $eventId, array $changes): void;
}
