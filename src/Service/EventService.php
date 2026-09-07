<?php

namespace App\Service;

use App\Dto\EventDetailResponse;
use App\Dto\EventRequest;
use App\Dto\EventResponse;
use App\Dto\EventSeatStatusDto;
use App\Entity\Chart;
use App\Entity\Event;
use App\Entity\EventSeat;
use App\Entity\SeatStatus;
use App\Entity\Workspace;
use App\Exception\DuplicateKeyException;
use App\Exception\ResourceNotFoundException;
use App\Repository\CategoryRepository;
use App\Repository\ChartRepository;
use App\Repository\EventRepository;
use App\Port\SeatPublisherPort;
use App\Repository\EventSeatRepository;
use App\Repository\SeatUsageLogRepository;
use Doctrine\ORM\EntityManagerInterface;

class EventService
{
    public function __construct(
        private EventRepository $eventRepository,
        private ChartRepository $chartRepository,
        private EventSeatRepository $eventSeatRepository,
        private CategoryRepository $categoryRepository,
        private EntityManagerInterface $em,
        private SeatPublisherPort $publisher,
        private WorkspaceContext $workspaceContext,
        private SeatUsageLogRepository $usageLogRepository,
        private string $mercurePublicUrl = '',
    ) {
    }

    public function create(EventRequest $request): EventResponse
    {
        $workspace = $this->workspaceContext->getWorkspace();

        if ($workspace) {
            $existing = $this->eventRepository->findByIdentifierAndWorkspace($request->identifier, $workspace);
            if ($existing) {
                throw new DuplicateKeyException("Event with identifier '$request->identifier' already exists");
            }
        }

        $event = new Event();
        $event->setTitle($request->title);
        $event->setIdentifier($request->identifier);
        $event->setWorkspace($workspace);

        if ($request->chartId) {
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $request->chartId)) {
                throw new \InvalidArgumentException("Invalid chartId format");
            }
            $chart = $this->chartRepository->find($request->chartId);
            if (!$chart) {
                throw new ResourceNotFoundException('Chart not found');
            }
            $event->setChart($chart);
            $this->initializeSeats($event, $chart);
        }

        $this->em->persist($event);
        try {
            $this->em->flush();
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            throw new DuplicateKeyException("Event with identifier '$request->identifier' already exists");
        }

        return $this->toResponse($event);
    }

    public function findAll(): array
    {
        $workspace = $this->workspaceContext->getWorkspace();
        if (!$workspace) {
            return [];
        }
        $events = $this->eventRepository->findByWorkspace($workspace);
        return array_map(fn(Event $event) => $this->toResponse($event), $events);
    }

    public function findByIdentifier(string $identifier): ?Event
    {
        return $this->eventRepository->findByIdentifier($identifier);
    }

    public function findByIdentifierAndWorkspace(string $identifier, Workspace $workspace): ?Event
    {
        return $this->eventRepository->findByIdentifierAndWorkspace($identifier, $workspace);
    }

    public function findById(string $id): EventDetailResponse
    {
        $event = $this->eventRepository->find($id);
        if (!$event) {
            throw new ResourceNotFoundException('Event not found');
        }
        return $this->toDetailResponse($event);
    }

    public function update(string $id, EventRequest $request): EventResponse
    {
        $event = $this->eventRepository->find($id);
        if (!$event) {
            throw new ResourceNotFoundException('Event not found');
        }

        if ($request->identifier !== $event->getIdentifier()) {
            // Scoped like create() and like the (identifier, workspace_id)
            // unique index — the global lookup used here rejected an
            // identifier that was free in this workspace but taken in
            // another one.
            $workspace = $this->workspaceContext->getWorkspace();
            $existing = $workspace
                ? $this->eventRepository->findByIdentifierAndWorkspace($request->identifier, $workspace)
                : $this->eventRepository->findByIdentifier($request->identifier);

            if ($existing) {
                throw new DuplicateKeyException("Event with identifier '$request->identifier' already exists");
            }
        }

        $event->setTitle($request->title);
        $event->setIdentifier($request->identifier);

        $this->em->persist($event);
        $this->em->flush();

        return $this->toResponse($event);
    }

    public function delete(string $id): void
    {
        $event = $this->eventRepository->find($id);
        if (!$event) {
            throw new ResourceNotFoundException('Event not found');
        }

        $this->em->remove($event);
        $this->em->flush();
    }

    public function linkChart(string $eventId, string $chartId): EventResponse
    {
        $event = $this->eventRepository->find($eventId);
        if (!$event) {
            throw new ResourceNotFoundException('Event not found');
        }

        $chart = $this->chartRepository->find($chartId);
        if (!$chart) {
            throw new ResourceNotFoundException('Chart not found');
        }

        // Remove existing seats
        foreach ($event->getSeats() as $seat) {
            $this->em->remove($seat);
        }

        $event->setChart($chart);
        $this->initializeSeats($event, $chart);

        $this->em->persist($event);
        $this->em->flush();

        return $this->toResponse($event);
    }

    public function getSeatStatuses(string $eventId, array $seatKeys = []): array
    {
        $event = $this->eventRepository->find($eventId);
        if (!$event) {
            throw new ResourceNotFoundException('Event not found');
        }

        $seats = $seatKeys !== []
            ? $this->eventSeatRepository->findByEventIdAndSeatKeyIn($event->getId(), $seatKeys)
            : $this->eventSeatRepository->findByEventId($event->getId());

        if ($seatKeys !== []) {
            // Auto-create missing seats: the published plan may have tables/seats that
            // were never selected before, so no EventSeat row exists yet for them
            // (same convention as BookingService::holdSeats()/bookSeats()).
            $existingKeys = array_map(fn(EventSeat $s) => $s->getSeatKey(), $seats);
            foreach (array_diff($seatKeys, $existingKeys) as $missingKey) {
                $seat = new EventSeat();
                $seat->setEvent($event);
                $seat->setSeatKey($missingKey);
                $seat->setStatus(SeatStatus::AVAILABLE);
                $this->em->persist($seat);
                $seats[] = $seat;
            }
            $this->em->flush();
        }

        $indexed = [];
        foreach ($seats as $seat) {
            $indexed[$seat->getSeatKey()] = [
                'status'    => $seat->getStatus()->value,
                'holdToken' => $seat->getHoldToken(),
            ];
        }

        return $indexed;
    }

    public function bulkUpdateSeatStatus(string $eventId, array $seatKeys, string $status): void
    {
        if (empty($seatKeys)) return;
        $seatStatus = SeatStatus::from($status);
        $event = $this->eventRepository->find($eventId);
        if (!$event) throw new ResourceNotFoundException('Event not found');

        $this->eventSeatRepository->updateStatusByEventAndKeys($event->getId(), $seatKeys, $seatStatus);

        $existingKeys = $this->eventSeatRepository->findExistingKeysByEventAndKeys($event->getId(), $seatKeys);
        foreach (array_diff($seatKeys, $existingKeys) as $key) {
            $seat = new EventSeat();
            $seat->setEvent($event);
            $seat->setSeatKey($key);
            $seat->setStatus($seatStatus);
            $this->em->persist($seat);
        }
        $this->em->flush();

        $changes = array_map(fn(string $k) => ['seatKey' => $k, 'status' => $status], $seatKeys);
        $this->publisher->publishSeatChanges($eventId, $changes);

        if ($seatStatus === SeatStatus::BOOKED) {
            foreach ($seatKeys as $seatKey) {
                $this->usageLogRepository->insertIfNotExists($eventId, $seatKey, 'booked');
            }
        }
    }

    private function initializeSeats(Event $event, Chart $chart): void
    {
        $objects = $chart->getObjects();
        $this->createSeatsFromObjects($event, $objects);
    }

    private function createSeatsFromObjects(Event $event, array $objects): void
    {
        foreach ($objects as $object) {
            $internalType = is_array($object) ? ($object['_type'] ?? null) : ($object->_type ?? null);
            $type = is_array($object) ? ($object['type'] ?? null) : ($object->type ?? null);
            $key  = is_array($object) ? ($object['key']  ?? null) : ($object->key  ?? null);

            // Bloc de sièges nominatifs (format BO admin)
            if ($internalType === 'seatRow') {
                foreach ($this->seatRowKeys($object) as $seatKey) {
                    $seat = new EventSeat();
                    $seat->setEvent($event);
                    $seat->setSeatKey($seatKey);
                    $seat->setStatus(SeatStatus::AVAILABLE);
                    $this->em->persist($seat);
                }
                continue;
            }

            // Section de tables (plusieurs tables dans une section)
            if ($internalType === 'tableSection') {
                $section     = is_array($object) ? ($object['section'] ?? $object['label'] ?? $key ?? 'TS') : ($object->section ?? $object->label ?? $key ?? 'TS');
                $tableCount  = (is_array($object) ? ($object['tableCount'] ?? 3) : ($object->tableCount ?? 3))
                             * (is_array($object) ? ($object['tableRows'] ?? 1)  : ($object->tableRows  ?? 1));
                $seatsPerTable = is_array($object) ? ($object['seatsPerTable'] ?? 6) : ($object->seatsPerTable ?? 6);
                $disabled    = is_array($object) ? ($object['disabledSeats'] ?? []) : ($object->disabledSeats ?? []);
                for ($ti = 1; $ti <= $tableCount; $ti++) {
                    for ($si = 1; $si <= $seatsPerTable; $si++) {
                        $disabledKey = ($ti - 1) . '-' . ($si - 1);
                        if (in_array($disabledKey, $disabled)) continue;
                        $seat = new EventSeat();
                        $seat->setEvent($event);
                        $seat->setSeatKey("--");
                        $seat->setStatus(SeatStatus::AVAILABLE);
                        $this->em->persist($seat);
                    }
                }
                continue;
            }

            // Table avec sièges autour (format BO admin)
            if ($internalType === 'tableZone') {
                $section   = is_array($object) ? ($object['section'] ?? $object['label'] ?? $key ?? 'T') : ($object->section ?? $object->label ?? $key ?? 'T');
                $seatCount = is_array($object) ? ($object['seatCount'] ?? 6) : ($object->seatCount ?? 6);
                for ($i = 1; $i <= $seatCount; $i++) {
                    $seat = new EventSeat();
                    $seat->setEvent($event);
                    $seat->setSeatKey("-");
                    $seat->setStatus(SeatStatus::AVAILABLE);
                    $this->em->persist($seat);
                }
                continue;
            }

            // Formats legacy
            if ($type === 'seat') {
                $seat = new EventSeat();
                $seat->setEvent($event);
                $seat->setSeatKey($key);
                $seat->setStatus(SeatStatus::AVAILABLE);
                $this->em->persist($seat);
            } elseif ($type === 'section' || $type === 'row') {
                $children = is_array($object) ? ($object['children'] ?? []) : ($object->children ?? []);
                if (!empty($children)) {
                    $this->createSeatsFromObjects($event, $children);
                }
            } elseif ($type === 'table') {
                $seatCount = is_array($object) ? ($object['seatCount'] ?? 0) : ($object->seatCount ?? 0);
                for ($i = 1; $i <= $seatCount; $i++) {
                    $seat = new EventSeat();
                    $seat->setEvent($event);
                    $seat->setSeatKey("-");
                    $seat->setStatus(SeatStatus::AVAILABLE);
                    $this->em->persist($seat);
                }
            }
        }
    }

    /**
     * Les clés de sièges produites par un bloc.
     *
     * Contrat partagé avec le module JS du back-office
     * (mitoera-bo/src/services/seatPlan.js) et le renderer acheteur
     * (js-src/seat-plan.js). Les trois rejouent tests/fixtures/seat-plan.json :
     * modifier l'une sans les autres casse au moins un test.
     *
     * @return string[]
     */
    public function seatRowKeys(mixed $object): array
    {
        $o        = $this->toArray($object);
        $section  = $o['section'] ?? $o['label'] ?? $o['key'] ?? 'S';
        $rows     = (int) ($o['rows'] ?? 1);
        $cols     = (int) ($o['cols'] ?? 1);
        $rowFmt   = $o['rowFormat']    ?? 'A-Z';
        $rowDir   = $o['rowDirection'] ?? 'normal';
        $colFmt   = $o['colFormat']    ?? '1-9';
        $colDir   = $o['colDirection'] ?? 'normal';
        $disabled = $this->toArray($o['disabledSeats'] ?? []);
        $deleted  = $this->toArray($o['deletedSeats']  ?? []);
        $rowOver  = $this->toArray($o['rowOverrides']  ?? []);

        $keys = [];
        for ($r = 0; $r < $rows; $r++) {
            // Réglages propres à la rangée : nombre de sièges, libellé et départ
            // de numérotation. Les ignorer ferait diverger ces clés de celles
            // affichées sur le plan.
            $ov         = $this->toArray($rowOver[$r] ?? $rowOver[(string) $r] ?? []);
            $rowCols    = isset($ov['cols'])       ? (int) $ov['cols']       : $cols;
            $colStartAt = isset($ov['colStartAt']) ? (int) $ov['colStartAt'] : 0;
            $rowLabel   = isset($ov['label']) && $ov['label'] !== null && $ov['label'] !== ''
                ? (string) $ov['label']
                : $this->axisLabel($r, $rows, $rowFmt, $rowDir);

            for ($c = 0; $c < $rowCols; $c++) {
                $posKey = "$r-$c";
                if (in_array($posKey, $disabled)) continue;
                if (in_array($posKey, $deleted)) continue;
                $keys[] = "$section-$rowLabel-" . $this->axisLabel($c, $rowCols, $colFmt, $colDir, $colStartAt);
            }
        }
        return $keys;
    }

    /** Les objets du plan arrivent tantôt en tableau associatif, tantôt en stdClass. */
    private function toArray(mixed $value): array
    {
        if (is_array($value))  return $value;
        if (is_object($value)) return (array) $value;
        return [];
    }

    private function axisLabel(int $index, int $total, string $format, string $direction, int $startAt = 0): string
    {
        $i = ($direction === 'reversed' ? max(0, $total - 1 - $index) : $index) + $startAt;
        return match ($format) {
            'A-Z'  => $this->toLetters($i, true),
            'a-z'  => $this->toLetters($i, false),
            'I-X'  => $this->toRoman($i),
            default => (string) ($i + 1),
        };
    }

    private function toLetters(int $n, bool $upper): string
    {
        $label = '';
        $x = $n;
        do {
            $char = chr(($upper ? 65 : 97) + ($x % 26));
            $label = $char . $label;
            $x = (int) floor($x / 26) - 1;
        } while ($x >= 0);
        return $label;
    }

    private function toRoman(int $n): string
    {
        $num = $n + 1;
        $result = '';
        $map = [[1000,'M'],[900,'CM'],[500,'D'],[400,'CD'],[100,'C'],[90,'XC'],
                 [50,'L'],[40,'XL'],[10,'X'],[9,'IX'],[5,'V'],[4,'IV'],[1,'I']];
        foreach ($map as [$value, $symbol]) {
            while ($num >= $value) { $result .= $symbol; $num -= $value; }
        }
        return $result ?: (string)($n + 1);
    }

private function toResponse(Event $event): EventResponse
    {
        return new EventResponse(
            $event->getId(),
            $event->getTitle(),
            $event->getIdentifier(),
            $event->getChart()?->getId(),
            $event->getChart()?->getName(),
            $event->getCreatedAt(),
        );
    }

    private function toDetailResponse(Event $event): EventDetailResponse
    {
        $seats = $this->eventSeatRepository->findByEventId($event->getId());
        $seatDtos = array_map(fn(EventSeat $seat) =>
            new EventSeatStatusDto($seat->getSeatKey(), $seat->getStatus()->value, $seat->getHoldToken()),
            $seats
        );

        $chart = $event->getChart();

        $categories = [];
        if ($chart) {
            foreach ($this->categoryRepository->findAllByChart($chart) as $cat) {
                $categories[] = ['id' => (string)$cat->getId(), 'name' => $cat->getName(), 'color' => $cat->getColor()];
            }
        }

        return new EventDetailResponse(
            $event->getId(),
            $event->getTitle(),
            $event->getIdentifier(),
            $chart?->getId(),
            $chart?->getName(),
            $event->getCreatedAt(),
            $seatDtos,
            $chart?->getObjectsJson() ?? [],
            $chart?->getPublishedSnapshot(),
            $categories,
            $this->mercurePublicUrl ?: null,
        );
    }
}

