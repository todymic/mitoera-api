<?php

namespace App\Repository;

use App\Entity\EventSeat;
use App\Entity\SeatStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<EventSeat>
 *
 * @method EventSeat|null find($id, $lockMode = null, $lockVersion = null)
 * @method EventSeat|null findOneBy(array $criteria, array $orderBy = null)
 * @method EventSeat[]    findAll()
 * @method EventSeat[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EventSeatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventSeat::class);
    }

    /** @return EventSeat[] */
    public function findByEventIdAndSeatKeyIn(Uuid $eventId, array $seatKeys): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.event = :eventId')
            ->andWhere('e.seatKey IN (:seatKeys)')
            ->setParameter('eventId', $eventId)
            ->setParameter('seatKeys', $seatKeys)
            ->getQuery()
            ->getResult();
    }

    /** @return EventSeat[] */
    public function findByEventId(Uuid $eventId): array
    {
        return $this->findBy(['event' => $eventId]);
    }

    /** @return EventSeat[] */
    public function findByStatusAndHeldUntilBefore(SeatStatus $status, \DateTimeImmutable $dateTime): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.status = :status')
            ->andWhere('e.heldUntil < :dateTime')
            ->setParameter('status', $status)
            ->setParameter('dateTime', $dateTime)
            ->getQuery()
            ->getResult();
    }

    /** @return EventSeat[] */
    public function findByEventIdAndStatusAndHeldUntilBefore(Uuid $eventId, SeatStatus $status, \DateTimeImmutable $dateTime): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.event = :eventId')
            ->andWhere('e.status = :status')
            ->andWhere('e.heldUntil < :dateTime')
            ->setParameter('eventId', $eventId)
            ->setParameter('status', $status)
            ->setParameter('dateTime', $dateTime)
            ->getQuery()
            ->getResult();
    }

    public function updateStatusByIds(array $ids, SeatStatus $newStatus): void
    {
        $this->createQueryBuilder('e')
            ->update()
            ->set('e.status', ':newStatus')
            ->set('e.holdToken', ':null')
            ->set('e.heldUntil', ':null')
            ->where('e.id IN (:ids)')
            ->setParameter('newStatus', $newStatus)
            ->setParameter('null', null)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }

    public function updateStatusByEventAndKeys(Uuid $eventId, array $seatKeys, SeatStatus $newStatus): void
    {
        $this->createQueryBuilder('e')
            ->update()
            ->set('e.status', ':newStatus')
            ->set('e.holdToken', ':null')
            ->set('e.heldUntil', ':null')
            ->where('e.event = :eventId')
            ->andWhere('e.seatKey IN (:seatKeys)')
            ->setParameter('newStatus', $newStatus)
            ->setParameter('null', null)
            ->setParameter('eventId', $eventId)
            ->setParameter('seatKeys', $seatKeys)
            ->getQuery()
            ->execute();
    }

    /** @return string[] */
    public function findExistingKeysByEventAndKeys(Uuid $eventId, array $seatKeys): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('e.seatKey')
            ->where('e.event = :eventId')
            ->andWhere('e.seatKey IN (:seatKeys)')
            ->setParameter('eventId', $eventId)
            ->setParameter('seatKeys', $seatKeys)
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'seatKey');
    }
}

