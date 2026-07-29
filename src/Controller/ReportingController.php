<?php

namespace App\Controller;

use App\Repository\SeatUsageLogRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/reporting')]
#[IsGranted('ROLE_BACKOFFICE')]
class ReportingController extends AbstractController
{
    public function __construct(
        private SeatUsageLogRepository $usageLogRepository,
        private UserRepository $userRepository,
    ) {}

    /**
     * Stats mensuelles globales ou par utilisateur.
     * GET /api/reporting/seats/monthly?userId=&year=
     */
    #[Route('/seats/monthly', methods: ['GET'])]
    public function monthly(Request $request): JsonResponse
    {
        $userId = $request->query->get('userId');
        $year   = $request->query->getInt('year') ?: null;

        $rows = $this->usageLogRepository->monthlyStats($userId, $year);

        return $this->json([
            'data'  => array_map(fn($r) => [
                'year'  => (int) $r['year'],
                'month' => (int) $r['month'],
                'count' => (int) $r['count'],
            ], $rows),
            'total' => $this->usageLogRepository->totalCount($userId, $year),
        ]);
    }

    /**
     * Nombre de sièges utilisés par événement.
     * GET /api/reporting/seats/by-event?userId=&year=&month=
     */
    #[Route('/seats/by-event', methods: ['GET'])]
    public function byEvent(Request $request): JsonResponse
    {
        $userId = $request->query->get('userId');
        $year   = $request->query->getInt('year')  ?: null;
        $month  = $request->query->getInt('month') ?: null;

        $rows = $this->usageLogRepository->statsByEvent($userId, $year, $month);

        return $this->json([
            'data'  => array_map(fn($r) => [
                'eventId'    => $r['eventId'],
                'eventTitle' => $r['eventTitle'],
                'usedSeats'  => (int) $r['count'],
            ], $rows),
            'total' => array_sum(array_column($rows, 'count')),
        ]);
    }

    /**
     * Liste détaillée des sièges utilisés pour un événement.
     * GET /api/reporting/seats/event/{eventId}
     */
    #[Route('/seats/event/{eventId}', methods: ['GET'])]
    public function seatList(string $eventId): JsonResponse
    {
        $seats = $this->usageLogRepository->seatListForEvent($eventId);

        return $this->json([
            'eventId' => $eventId,
            'total'   => count($seats),
            'seats'   => array_map(fn($s) => [
                'seatKey' => $s['seatKey'],
                'reason'  => $s['reason'],
                'usedAt'  => $s['usedAt'] instanceof \DateTimeImmutable
                    ? $s['usedAt']->format(\DateTimeInterface::ATOM)
                    : (string) $s['usedAt'],
            ], $seats),
        ]);
    }

    /**
     * Liste des utilisateurs (workspaces) avec leur total de sièges utilisés.
     * GET /api/reporting/seats/workspaces?year=&month=
     */
    #[Route('/seats/workspaces', methods: ['GET'])]
    public function workspaces(Request $request): JsonResponse
    {
        $year  = $request->query->getInt('year')  ?: null;
        $month = $request->query->getInt('month') ?: null;

        $users = $this->userRepository->findAll();

        $result = [];
        foreach ($users as $user) {
            $userId = $user->getId()->toRfc4122();
            $total  = $this->usageLogRepository->totalCount($userId, $year, $month);
            if ($total > 0) {
                $result[] = [
                    'userId'      => $userId,
                    'displayName' => $user->getDisplayName() ?? $user->getEmail(),
                    'email'       => $user->getEmail(),
                    'usedSeats'   => $total,
                ];
            }
        }

        usort($result, fn($a, $b) => $b['usedSeats'] <=> $a['usedSeats']);

        return $this->json(['workspaces' => $result]);
    }
}
