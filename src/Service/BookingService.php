<?php

namespace App\Service;

use App\Dto\BookResponse;
use App\Dto\HoldResponse;
use App\Dto\SeatConflictDetail;
use App\Entity\EventSeat;
use App\Entity\SeatStatus;
use App\Exception\ResourceNotFoundException;
use App\Exception\SeatNotAvailableException;
use App\Repository\EventRepository;
use App\Repository\EventSeatRepository;
use App\Repository\SeatUsageLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Port\SeatPublisherPort;
use Predis\Client;
use Symfony\Component\Uid\Uuid;

class BookingService
{
    private Client $redis;
    private int $holdDurationMinutes;

    public function __construct(
        private EventSeatRepository $eventSeatRepository,
        private EventRepository $eventRepository,
        private EntityManagerInterface $em,
        private SeatPublisherPort $publisher,
        private SeatUsageLogRepository $usageLogRepository,
        string $redisUrl = 'tcp://127.0.0.1:6379',
        int $holdDurationMinutes = 10,
    ) {
        $this->redis = new Client($redisUrl);
        $this->holdDurationMinutes = $holdDurationMinutes;
    }

    public function publishRawSeatUpdates(Uuid $eventId, array $updates): void
    {
        if (empty($updates)) {
            return;
        }

        $this->redis->publish("seats:$eventId", json_encode($updates));
        $this->publisher->publishSeatChanges((string) $eventId, $updates);
    }

    private function publishSeatChanges(Uuid $eventId, array $seats): void
    {
        $changes = array_map(fn(EventSeat $s) => [
            'seatKey' => $s->getSeatKey(),
            'status'  => $s->getStatus()->value,
        ], $seats);

        $this->redis->publish("seats:$eventId", json_encode($changes));
        $this->publisher->publishSeatChanges((string) $eventId, $changes);
    }

    public function holdSeats(Uuid $eventId, array $seatKeys, string $holdToken): HoldResponse
    {
        $seatKeys = array_values(array_unique($seatKeys));

        $event = $this->eventRepository->find($eventId);
        if (!$event) {
            throw new ResourceNotFoundException('Event not found');
        }

        $seats = $this->eventSeatRepository->findByEventIdAndSeatKeyIn($eventId, $seatKeys);

        // Auto-create missing EventSeat rows (plan may have been updated after event was linked)
        $existingKeys = array_map(fn(EventSeat $s) => $s->getSeatKey(), $seats);
        foreach (array_diff($seatKeys, $existingKeys) as $missingKey) {
            $seat = new EventSeat();
            $seat->setEvent($event);
            $seat->setSeatKey($missingKey);
            $seat->setStatus(SeatStatus::AVAILABLE);
            $this->em->persist($seat);
            $seats[] = $seat;
        }

        $holdDurationMinutes = $event->getHoldDurationMinutes();
        $holdDurationSeconds = $holdDurationMinutes * 60;
        $expiresAt = new \DateTimeImmutable("+$holdDurationMinutes minutes");

        $pipe = $this->redis->pipeline();
        foreach ($seatKeys as $seatKey) {
            $pipe->setex("hold:$eventId:$seatKey", $holdDurationSeconds, $holdToken);
        }
        $pipe->setex("session_seats:$holdToken", $holdDurationSeconds, json_encode($seatKeys));
        $pipe->execute();

        foreach ($seats as $seat) {
            $seat->setStatus(SeatStatus::HOLD);
            $seat->setHoldToken($holdToken);
            $seat->setHeldUntil($expiresAt);
            $this->em->persist($seat);
        }
        $this->em->flush();
        $this->publishSeatChanges($eventId, $seats);

        // Audit : comptabilise chaque siège la première fois qu'il est hold
        foreach ($seatKeys as $seatKey) {
            $this->usageLogRepository->insertIfNotExists($eventId->toRfc4122(), $seatKey, 'hold');
        }

        return new HoldResponse($holdToken, $seatKeys, $expiresAt, $holdDurationSeconds);
    }

    public function bookSeats(Uuid $eventId, array $seatKeys, string $holdToken): BookResponse
    {
        $seatKeys = array_values(array_unique($seatKeys));

        $event = $this->eventRepository->find($eventId);
        if (!$event) {
            throw new ResourceNotFoundException('Event not found');
        }

        $seats = $this->eventSeatRepository->findByEventIdAndSeatKeyIn($eventId, $seatKeys);
        $existingKeys = array_map(fn(EventSeat $s) => $s->getSeatKey(), $seats);
        foreach (array_diff($seatKeys, $existingKeys) as $missingKey) {
            $seat = new EventSeat();
            $seat->setEvent($event);
            $seat->setSeatKey($missingKey);
            $seat->setStatus(SeatStatus::AVAILABLE);
            $this->em->persist($seat);
            $seats[] = $seat;
        }

        foreach ($seats as $seat) {
            $seat->setStatus(SeatStatus::BOOKED);
            $seat->setHoldToken(null);
            $seat->setHeldUntil(null);
            $this->em->persist($seat);
        }
        $this->em->flush();
        $this->publishSeatChanges($eventId, $seats);

        // Audit : comptabilise chaque siège la première fois qu'il est booked
        // (INSERT IGNORE : si déjà compté au hold, ne compte pas une deuxième fois)
        foreach ($seatKeys as $seatKey) {
            $this->usageLogRepository->insertIfNotExists($eventId->toRfc4122(), $seatKey, 'booked');
        }

        $pipe = $this->redis->pipeline();
        foreach ($seatKeys as $seatKey) {
            $pipe->del("hold:$eventId:$seatKey");
        }
        $pipe->del("session_seats:$holdToken");
        $pipe->execute();

        return new BookResponse($seatKeys, $eventId->toRfc4122(), new \DateTimeImmutable());
    }

    public function releaseSeats(Uuid $eventId, array $seatKeys, string $holdToken): void
    {
        $seatKeys = array_values(array_unique($seatKeys));

        $seats = $this->eventSeatRepository->findByEventIdAndSeatKeyIn($eventId, $seatKeys);

        foreach ($seats as $seat) {
            $seat->setStatus(SeatStatus::AVAILABLE);
            $seat->setHoldToken(null);
            $seat->setHeldUntil(null);
            $this->em->persist($seat);
        }
        $this->em->flush();
        $this->publishSeatChanges($eventId, $seats);

        $pipe = $this->redis->pipeline();
        foreach ($seatKeys as $seatKey) {
            $pipe->del("hold:$eventId:$seatKey");
        }
        $pipe->del("session_seats:$holdToken");
        $pipe->execute();
    }

    /** @return array<string, array{status: string, holdToken: string|null}> */
    public function getSeatStatuses(Uuid $eventId, array $seatKeys): array
    {
        $seats = $this->eventSeatRepository->findByEventIdAndSeatKeyIn($eventId, $seatKeys);
        $result = [];
        foreach ($seats as $seat) {
            $result[$seat->getSeatKey()] = [
                'status' => $seat->getStatus()->value,
                'holdToken' => $seat->getHoldToken(),
            ];
        }
        return $result;
    }

    /** @return array<string, array{status: string, holdToken: string|null}> */
    public function getAllSeatStatuses(Uuid $eventId): array
    {
        $seats = $this->eventSeatRepository->findByEventId($eventId);
        $result = [];
        foreach ($seats as $seat) {
            $result[$seat->getSeatKey()] = [
                'status' => $seat->getStatus()->value,
                'holdToken' => $seat->getHoldToken(),
            ];
        }
        return $result;
    }

    public function changeStatus(Uuid $eventId, array $seatKeys, SeatStatus $newStatus): void
    {
        // Deduplicate
        $seatKeys = array_unique($seatKeys);
        if (empty($seatKeys)) {
            throw new \InvalidArgumentException('Seat keys list is empty');
        }

        // Verify event exists
        $event = $this->eventRepository->find($eventId);
        if (!$event) {
            throw new ResourceNotFoundException('Event not found');
        }

        // Change status
        $seats = $this->eventSeatRepository->findByEventIdAndSeatKeyIn($eventId, $seatKeys);

        foreach ($seats as $seat) {
            $seat->setStatus($newStatus);
            if ($newStatus !== SeatStatus::HOLD) {
                $seat->setHoldToken(null);
                $seat->setHeldUntil(null);
            }
            $this->em->persist($seat);
        }
        $this->em->flush();
        $this->publishSeatChanges($eventId, $seats);

        // Clean Redis if releasing
        if ($newStatus !== SeatStatus::HOLD) {
            $pipe = $this->redis->pipeline();
            foreach ($seatKeys as $seatKey) {
                $redisKey = "hold:$eventId:$seatKey";
                $pipe->del($redisKey);
            }
            $pipe->execute();
        }
    }
}

