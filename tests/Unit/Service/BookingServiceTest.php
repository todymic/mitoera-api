<?php

namespace App\Tests\Unit\Service;

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
use App\Service\BookingService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[\PHPUnit\Framework\Attributes\CoversClass(BookingService::class)]
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class BookingServiceTest extends TestCase
{
    private EventSeatRepository&MockObject    $seatRepo;
    private EventRepository&MockObject        $eventRepo;
    private EntityManagerInterface&MockObject $em;
    private BookingService                    $service;

    private Uuid  $eventId;
    private Event $event;

    protected function setUp(): void
    {
        // createMock() uniquement là où on pose des expects() — createStub() ailleurs.
        $this->seatRepo  = $this->createMock(EventSeatRepository::class);
        $this->eventRepo = $this->createMock(EventRepository::class);
        $this->em        = $this->createMock(EntityManagerInterface::class);

        $settingRepo = $this->createStub(AppSettingRepository::class);
        $settingRepo->method('get')->willReturn('10');

        // Predis Client et Pipeline passent par __call — stubs anonymes.
        $redisStub = new class {
            public function __call(string $name, array $args): null { return null; }
            public function pipeline(): object {
                return new class {
                    public function __call(string $name, array $args): void {}
                    public function execute(): void {}
                };
            }
        };

        $this->eventId = Uuid::v4();
        $this->event   = new Event();
        $this->eventRepo->method('find')->willReturn($this->event);

        $this->service = new BookingService(
            eventSeatRepository: $this->seatRepo,
            eventRepository:     $this->eventRepo,
            em:                  $this->em,
            publisher:           $this->createStub(SeatPublisherPort::class),
            usageLogRepository:  $this->createStub(SeatUsageLogRepository::class),
            settingRepository:   $settingRepo,
        );

        (new \ReflectionProperty(BookingService::class, 'redis'))->setValue($this->service, $redisStub);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeSeat(string $key, SeatStatus $status, ?string $holdToken = null): EventSeat
    {
        $seat = new EventSeat();
        $seat->setSeatKey($key);
        $seat->setStatus($status);
        $seat->setHoldToken($holdToken);
        $seat->setEvent($this->event);
        return $seat;
    }

    // -------------------------------------------------------------------------
    // holdSeats — guards
    // -------------------------------------------------------------------------

    public function testHoldAvailableSeatSucceeds(): void
    {
        $seat = $this->makeSeat('A-A-1', SeatStatus::AVAILABLE);
        $this->seatRepo->method('findByEventIdAndSeatKeyIn')->willReturn([$seat]);
        $this->em->expects($this->atLeastOnce())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $response = $this->service->holdSeats($this->eventId, ['A-A-1'], 'token-abc');

        $this->assertSame('token-abc', $response->holdToken);
        $this->assertSame(SeatStatus::HOLD, $seat->getStatus());
    }

    public function testHoldReHoldSameSeatSameTokenSucceeds(): void
    {
        // Le même client rafraîchit son hold
        $seat = $this->makeSeat('A-A-1', SeatStatus::HOLD, 'token-abc');
        $this->seatRepo->method('findByEventIdAndSeatKeyIn')->willReturn([$seat]);

        $response = $this->service->holdSeats($this->eventId, ['A-A-1'], 'token-abc');

        $this->assertSame('token-abc', $response->holdToken);
    }

    public function testHoldBookedSeatThrows(): void
    {
        $seat = $this->makeSeat('A-A-1', SeatStatus::BOOKED);
        $this->seatRepo->method('findByEventIdAndSeatKeyIn')->willReturn([$seat]);

        $this->expectException(SeatNotAvailableException::class);
        $this->service->holdSeats($this->eventId, ['A-A-1'], 'token-abc');
    }

    public function testHoldCanceledSeatThrows(): void
    {
        $seat = $this->makeSeat('A-A-1', SeatStatus::CANCELED);
        $this->seatRepo->method('findByEventIdAndSeatKeyIn')->willReturn([$seat]);

        $this->expectException(SeatNotAvailableException::class);
        $this->service->holdSeats($this->eventId, ['A-A-1'], 'token-abc');
    }

    public function testHoldSeatHeldByOtherTokenThrows(): void
    {
        $seat = $this->makeSeat('A-A-1', SeatStatus::HOLD, 'other-token');
        $this->seatRepo->method('findByEventIdAndSeatKeyIn')->willReturn([$seat]);

        $this->expectException(SeatNotAvailableException::class);
        $this->service->holdSeats($this->eventId, ['A-A-1'], 'token-abc');
    }

    public function testHoldPartialConflictReportsAllConflicts(): void
    {
        $seats = [
            $this->makeSeat('A-A-1', SeatStatus::AVAILABLE),
            $this->makeSeat('A-A-2', SeatStatus::BOOKED),
            $this->makeSeat('A-A-3', SeatStatus::HOLD, 'other-token'),
        ];
        $this->seatRepo->method('findByEventIdAndSeatKeyIn')->willReturn($seats);

        try {
            $this->service->holdSeats($this->eventId, ['A-A-1', 'A-A-2', 'A-A-3'], 'token-abc');
            $this->fail('SeatNotAvailableException attendue');
        } catch (SeatNotAvailableException $e) {
            $this->assertCount(2, $e->conflicts);
            $keys = array_map(fn(SeatConflictDetail $c) => $c->seatKey, $e->conflicts);
            $this->assertContains('A-A-2', $keys);
            $this->assertContains('A-A-3', $keys);
        }
    }

    public function testHoldEventNotFoundThrows(): void
    {
        $this->eventRepo = $this->createMock(EventRepository::class);
        $this->eventRepo->method('find')->willReturn(null);

        $ref = new \ReflectionProperty(BookingService::class, 'eventRepository');
        $ref->setValue($this->service, $this->eventRepo);

        $this->expectException(ResourceNotFoundException::class);
        $this->service->holdSeats($this->eventId, ['A-A-1'], 'token-abc');
    }

    // -------------------------------------------------------------------------
    // bookSeats — guards
    // -------------------------------------------------------------------------

    public function testBookSeatHeldByCorrectTokenSucceeds(): void
    {
        $seat = $this->makeSeat('A-A-1', SeatStatus::HOLD, 'token-abc');
        $this->seatRepo->method('findByEventIdAndSeatKeyIn')->willReturn([$seat]);
        $this->em->expects($this->once())->method('flush');

        $response = $this->service->bookSeats($this->eventId, ['A-A-1'], 'token-abc');

        $this->assertContains('A-A-1', $response->bookedSeats);
        $this->assertSame(SeatStatus::BOOKED, $seat->getStatus());
        $this->assertNull($seat->getHoldToken());
    }

    public function testBookAvailableSeatThrows(): void
    {
        $seat = $this->makeSeat('A-A-1', SeatStatus::AVAILABLE);
        $this->seatRepo->method('findByEventIdAndSeatKeyIn')->willReturn([$seat]);

        $this->expectException(SeatNotAvailableException::class);
        $this->service->bookSeats($this->eventId, ['A-A-1'], 'token-abc');
    }

    public function testBookAlreadyBookedSeatThrows(): void
    {
        $seat = $this->makeSeat('A-A-1', SeatStatus::BOOKED);
        $this->seatRepo->method('findByEventIdAndSeatKeyIn')->willReturn([$seat]);

        $this->expectException(SeatNotAvailableException::class);
        $this->service->bookSeats($this->eventId, ['A-A-1'], 'token-abc');
    }

    public function testBookCanceledSeatThrows(): void
    {
        $seat = $this->makeSeat('A-A-1', SeatStatus::CANCELED);
        $this->seatRepo->method('findByEventIdAndSeatKeyIn')->willReturn([$seat]);

        $this->expectException(SeatNotAvailableException::class);
        $this->service->bookSeats($this->eventId, ['A-A-1'], 'token-abc');
    }

    public function testBookSeatHeldByOtherTokenThrows(): void
    {
        $seat = $this->makeSeat('A-A-1', SeatStatus::HOLD, 'other-token');
        $this->seatRepo->method('findByEventIdAndSeatKeyIn')->willReturn([$seat]);

        $this->expectException(SeatNotAvailableException::class);
        $this->service->bookSeats($this->eventId, ['A-A-1'], 'token-abc');
    }

    public function testBookConflictContainsSeatKey(): void
    {
        $seats = [
            $this->makeSeat('A-A-1', SeatStatus::HOLD, 'token-abc'),
            $this->makeSeat('A-A-2', SeatStatus::AVAILABLE),
        ];
        $this->seatRepo->method('findByEventIdAndSeatKeyIn')->willReturn($seats);

        try {
            $this->service->bookSeats($this->eventId, ['A-A-1', 'A-A-2'], 'token-abc');
            $this->fail('SeatNotAvailableException attendue');
        } catch (SeatNotAvailableException $e) {
            $this->assertCount(1, $e->conflicts);
            $this->assertSame('A-A-2', $e->conflicts[0]->seatKey);
            $this->assertSame('available', $e->conflicts[0]->currentStatus);
        }
    }

    // -------------------------------------------------------------------------
    // Dédoublonnage
    // -------------------------------------------------------------------------

    public function testDuplicateSeatKeysAreDeduplicatedBeforeHold(): void
    {
        $seat = $this->makeSeat('A-A-1', SeatStatus::AVAILABLE);
        $this->seatRepo
            ->expects($this->once())
            ->method('findByEventIdAndSeatKeyIn')
            ->with($this->eventId, ['A-A-1'])
            ->willReturn([$seat]);

        $this->service->holdSeats($this->eventId, ['A-A-1', 'A-A-1', 'A-A-1'], 'token-abc');
    }
}
