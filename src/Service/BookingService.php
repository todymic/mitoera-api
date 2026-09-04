<?php

namespace App\Service;

use App\Dto\BookResponse;
use App\Dto\HoldResponse;
use App\Dto\SeatConflictDetail;
use App\Entity\Event;
use App\Entity\EventSeat;
use App\Entity\SeatStatus;
use App\Exception\ResourceNotFoundException;
use App\Exception\SeatNotAvailableException;
use App\Port\SeatPublisherPort;
use App\Repository\AppSettingRepository;
use App\Repository\EventRepository;
use App\Repository\EventSeatRepository;
use App\Repository\SeatUsageLogRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Predis\Client;
use Symfony\Component\Uid\Uuid;

class BookingService
{
    private object $redis;

    public function __construct(
        private EventSeatRepository $eventSeatRepository,
        private EventRepository $eventRepository,
        private EntityManagerInterface $em,
        private SeatPublisherPort $publisher,
        private SeatUsageLogRepository $usageLogRepository,
        private AppSettingRepository $settingRepository,
        string $redisUrl = 'tcp://127.0.0.1:6379',
    ) {
        $this->redis = new Client($redisUrl);
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

    /**
     * Crée les EventSeat manquants (plan mis à jour après liaison) et retourne la liste complète.
     *
     * @param EventSeat[] $seats
     * @return EventSeat[]
     */
    private function autoCreateMissingSeats(Event $event, array $seatKeys, array $seats): array
    {
        $existingKeys = array_map(fn(EventSeat $s) => $s->getSeatKey(), $seats);
        foreach (array_diff($seatKeys, $existingKeys) as $missingKey) {
            $seat = new EventSeat();
            $seat->setEvent($event);
            $seat->setSeatKey($missingKey);
            $seat->setStatus(SeatStatus::AVAILABLE);
            $this->em->persist($seat);
            $seats[] = $seat;
        }
        return $seats;
    }

    /**
     * Vérifie que tous les sièges sont disponibles pour un hold.
     * Lève SeatNotAvailableException si un siège est BOOKED, CANCELED, ou déjà HOLD par un autre token.
     *
     * @param EventSeat[] $seats
     */
    private function assertSeatsAvailableForHold(array $seats, string $holdToken): void
    {
        $conflicts = [];
        foreach ($seats as $seat) {
            $status = $seat->getStatus();
            if ($status === SeatStatus::BOOKED || $status === SeatStatus::CANCELED) {
                $conflicts[] = new SeatConflictDetail($seat->getSeatKey(), $status->value);
            } elseif ($status === SeatStatus::HOLD && $seat->getHoldToken() !== $holdToken) {
                $conflicts[] = new SeatConflictDetail($seat->getSeatKey(), $status->value);
            }
        }
        if ($conflicts !== []) {
            throw new SeatNotAvailableException($conflicts);
        }
    }

    /**
     * Vérifie que tous les sièges sont en HOLD avec le bon token pour pouvoir être bookés.
     * Lève SeatNotAvailableException sinon.
     *
     * @param EventSeat[] $seats
     */
    private function assertSeatsHeldByToken(array $seats, string $holdToken): void
    {
        $conflicts = [];
        foreach ($seats as $seat) {
            $status = $seat->getStatus();
            $isHeldByUs = $status === SeatStatus::HOLD && $seat->getHoldToken() === $holdToken;
            if (!$isHeldByUs) {
                $conflicts[] = new SeatConflictDetail($seat->getSeatKey(), $status->value);
            }
        }
        if ($conflicts !== []) {
            throw new SeatNotAvailableException($conflicts);
        }
    }

    public function holdSeats(Uuid $eventId, array $seatKeys, string $holdToken): HoldResponse
    {
        $seatKeys = array_values(array_unique($seatKeys));

        $event = $this->eventRepository->find($eventId);
        if (!$event) {
            throw new ResourceNotFoundException('Event not found');
        }

        $seats = $this->eventSeatRepository->findByEventIdAndSeatKeyIn($eventId, $seatKeys);
        $seats = $this->autoCreateMissingSeats($event, $seatKeys, $seats);

        $this->assertSeatsAvailableForHold($seats, $holdToken);

        $holdDurationMinutes = (int) $this->settingRepository->get('default_hold_duration_minutes', '10');
        $holdDurationSeconds = $holdDurationMinutes * 60;
        $expiresAt = new DateTimeImmutable("+$holdDurationMinutes minutes");

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
        $seats = $this->autoCreateMissingSeats($event, $seatKeys, $seats);

        $this->assertSeatsHeldByToken($seats, $holdToken);

        foreach ($seats as $seat) {
            $seat->setStatus(SeatStatus::BOOKED);
            $seat->setHoldToken(null);
            $seat->setHeldUntil(null);
            $this->em->persist($seat);
        }
        $this->em->flush();
        $this->publishSeatChanges($eventId, $seats);

        foreach ($seatKeys as $seatKey) {
            $this->usageLogRepository->insertIfNotExists($eventId->toRfc4122(), $seatKey, 'booked');
        }

        $pipe = $this->redis->pipeline();
        foreach ($seatKeys as $seatKey) {
            $pipe->del("hold:$eventId:$seatKey");
        }
        $pipe->del("session_seats:$holdToken");
        $pipe->execute();

        return new BookResponse($seatKeys, $eventId->toRfc4122(), new DateTimeImmutable());
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
                'status'    => $seat->getStatus()->value,
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
                'status'    => $seat->getStatus()->value,
                'holdToken' => $seat->getHoldToken(),
            ];
        }
        return $result;
    }

    public function changeStatus(Uuid $eventId, array $seatKeys, SeatStatus $newStatus): void
    {
        $seatKeys = array_unique($seatKeys);
        if (empty($seatKeys)) {
            throw new InvalidArgumentException('Seat keys list is empty');
        }

        $event = $this->eventRepository->find($eventId);
        if (!$event) {
            throw new ResourceNotFoundException('Event not found');
        }

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

        if ($newStatus !== SeatStatus::HOLD) {
            $pipe = $this->redis->pipeline();
            foreach ($seatKeys as $seatKey) {
                $pipe->del("hold:$eventId:$seatKey");
            }
            $pipe->execute();
        }
    }
}
