<?php

namespace App\Adapter\Outbound;

use App\Port\SeatPublisherPort;
use DateTimeImmutable;
use Kreait\Firebase\Contract\Firestore;
use Kreait\Firebase\Factory;
use Throwable;

/**
 * Publie les statuts de sièges dans Cloud Firestore via le SDK kreait/firebase-php.
 * Structure : events/{eventId}/seats/{seatKey}
 */
readonly class FirestoreSeatPublisher implements SeatPublisherPort
{
    private Firestore $firestoreService;

    public function __construct(string $credentialsPath, string $projectId)
    {
        $this->firestoreService = new Factory()
            ->withServiceAccount($credentialsPath)
            ->withProjectId($projectId)
            ->createFirestore();
    }

    public function publishSeatChanges(string $eventId, array $changes): void
    {
        if (empty($changes)) {
            return;
        }

        $db = $this->firestoreService->database();
        $batch = $db->batch();

        foreach ($changes as $change) {
            $seatKey = $change['seatKey'];
            $doc = $db->collection('events')
                ->document($eventId)
                ->collection('seats')
                ->document($seatKey);

            $batch->set($doc, [
                'seatKey'   => $seatKey,
                'status'    => $change['status'],
                'updatedAt' => new DateTimeImmutable(),
            ], ['merge' => true]);
        }

        try {
            $batch->commit();
        } catch (Throwable $e) {
            error_log('[Firestore] Failed to publish: ' . $e->getMessage());
        }
    }
}
