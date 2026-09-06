<?php

namespace App\Controller;

use App\Repository\ChartRepository;
use App\Repository\EventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Vue opérationnelle transverse : les événements et les plans de salle de tous
 * les workspaces, que les contrôleurs applicatifs ne montrent que tenant par tenant.
 */
#[Route('/api/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminOperationsController extends AbstractController
{
    private const EVENT_LIMIT = 200;
    private const CHART_LIMIT = 200;

    public function __construct(
        private EventRepository $events,
        private ChartRepository $charts,
    ) {}

    #[Route('/events', methods: ['GET'])]
    public function eventList(Request $request): JsonResponse
    {
        $workspaceId = $this->workspaceFilter($request);
        if ($workspaceId instanceof JsonResponse) {
            return $workspaceId;
        }

        $rows = $this->events->adminList(
            $workspaceId,
            $request->query->get('search'),
            self::EVENT_LIMIT
        );

        $events = array_map(function (array $row): array {
            $total  = (int) $row['seats_total'];
            $booked = (int) $row['seats_booked'];

            return [
                'id'                  => $row['id'],
                'title'               => $row['title'],
                'identifier'          => $row['identifier'],
                'createdAt'           => $this->atom($row['created_at']),
                'holdDurationMinutes' => (int) $row['hold_duration_minutes'],
                'workspaceId'         => $row['workspace_id'],
                'workspaceName'       => $row['workspace_name'],
                'chartId'             => $row['chart_id'],
                'chartName'           => $row['chart_name'],
                'ownerEmail'          => $row['owner_email'],
                'ownerName'           => $row['owner_name'],
                'seats' => [
                    'total'     => $total,
                    'booked'    => $booked,
                    'held'      => (int) $row['seats_held'],
                    'available' => (int) $row['seats_available'],
                    'canceled'  => (int) $row['seats_canceled'],
                ],
                // Sans plan de salle publié, un événement n'a pas encore de sièges :
                // le taux n'a alors pas de sens et vaut null plutôt que zéro.
                'occupancy' => $total > 0 ? round($booked / $total, 4) : null,
            ];
        }, $rows);

        return $this->json([
            'events'    => $events,
            'total'     => count($events),
            'truncated' => count($events) === self::EVENT_LIMIT,
            'limit'     => self::EVENT_LIMIT,
        ]);
    }

    #[Route('/charts', methods: ['GET'])]
    public function chartList(Request $request): JsonResponse
    {
        $workspaceId = $this->workspaceFilter($request);
        if ($workspaceId instanceof JsonResponse) {
            return $workspaceId;
        }

        $rows = $this->charts->adminList($workspaceId, self::CHART_LIMIT);

        $charts = array_map(function (array $row): array {
            $categories = json_decode((string) $row['categories'], true) ?: [];

            return [
                'id'              => $row['id'],
                'name'            => $row['name'],
                'status'          => $row['status'],
                'pendingChanges'  => (bool) $row['pending_changes'],
                'createdAt'       => $this->atom($row['created_at']),
                'updatedAt'       => $this->atom($row['updated_at']),
                'workspaceId'     => $row['workspace_id'],
                'workspaceName'   => $row['workspace_name'],
                'eventsCount'     => (int) $row['events_count'],
                'categoriesCount' => (int) $row['categories_count'],
                'categories'      => $categories,
            ];
        }, $rows);

        return $this->json([
            'charts'    => $charts,
            'total'     => count($charts),
            'truncated' => count($charts) === self::CHART_LIMIT,
            'limit'     => self::CHART_LIMIT,
        ]);
    }

    private function workspaceFilter(Request $request): string|null|JsonResponse
    {
        $workspaceId = $request->query->get('workspaceId');

        if ($workspaceId === null || $workspaceId === '') {
            return null;
        }
        if (!Uuid::isValid($workspaceId)) {
            return $this->json(['message' => 'Identifiant de workspace invalide.'], Response::HTTP_BAD_REQUEST);
        }

        return $workspaceId;
    }

    private function atom(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? $value->format(\DateTimeInterface::ATOM)
            : (new \DateTimeImmutable((string) $value))->format(\DateTimeInterface::ATOM);
    }
}
