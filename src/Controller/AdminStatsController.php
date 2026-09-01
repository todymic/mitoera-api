<?php

namespace App\Controller;

use App\Entity\Subscription;
use App\Repository\SeatUsageLogRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\SurplusInvoiceRepository;
use App\Repository\UserRepository;
use App\Repository\WorkspaceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Agrégats consommés par le tableau de bord de l'admin.
 */
#[Route('/api/admin/stats')]
#[IsGranted('ROLE_ADMIN')]
class AdminStatsController extends AbstractController
{
    private const DEFAULT_MONTHS = 12;
    private const QUOTA_ALERT_RATIO = 0.8;

    public function __construct(
        private SubscriptionRepository $subscriptions,
        private SurplusInvoiceRepository $surplusInvoices,
        private SeatUsageLogRepository $usageLogs,
        private UserRepository $users,
        private WorkspaceRepository $workspaces,
    ) {}

    #[Route('', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $months = max(1, min(36, $request->query->getInt('months') ?: self::DEFAULT_MONTHS));
        $now    = new \DateTimeImmutable('first day of this month 00:00:00');
        $since  = $now->modify(sprintf('-%d months', $months - 1));

        $seatSeries    = $this->monthlySeries($since, $months, $this->indexSeatMonths(), 0);
        $revenueSeries = $this->monthlySeries($since, $months, $this->indexRevenueMonths($since), 0);

        $byStatus       = $this->subscriptions->countByStatus();
        $pendingSurplus = $this->subscriptions->pendingSurplus();

        return $this->json([
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'currency'    => 'EUR',

            'users' => [
                'total'    => $this->users->count([]),
                'pending'  => $this->users->count(['validated' => false]),
                'inactive' => $this->users->count(['active' => false]),
            ],

            'workspaces' => [
                'total'           => $this->workspaces->count([]),
                'withSubscription' => $this->subscriptions->count([]),
            ],

            'subscriptions' => [
                'total'    => $this->subscriptions->count([]),
                'byStatus' => $this->fillStatuses($byStatus),
                'byPlan'   => array_map(fn (array $p) => [
                    'plan'  => $p['plan'],
                    'label' => Subscription::PLANS[$p['plan']]['label'] ?? $p['plan'],
                    'count' => $p['total'],
                    'quota' => $p['quota'],
                ], $this->subscriptions->countByPlan()),
            ],

            'seats' => [
                'thisMonth'     => end($seatSeries)['value'] ?? 0,
                'previousMonth' => $seatSeries[count($seatSeries) - 2]['value'] ?? 0,
                'total'         => array_sum(array_column($seatSeries, 'value')),
                'monthly'       => $seatSeries,
            ],

            // Seul le surplus est facturé depuis cette base : le montant récurrent
            // des abonnements vit dans Stripe et n'est pas répliqué ici.
            'surplus' => [
                'billedThisMonthCents' => end($revenueSeries)['value'] ?? 0,
                'billedTotalCents'     => array_sum(array_column($revenueSeries, 'value')),
                'pendingSeats'         => $pendingSurplus['seats'],
                'pendingAmountCents'   => $pendingSurplus['amountCents'],
                'monthly'              => $revenueSeries,
            ],

            'topWorkspaces' => $this->subscriptions->usageByWorkspace(8),

            'alerts' => [
                'usersPending'         => $this->users->count(['validated' => false]),
                'subscriptionsPastDue' => ($byStatus[Subscription::STATUS_PAST_DUE] ?? 0)
                    + ($byStatus[Subscription::STATUS_UNPAID] ?? 0),
                'quotaAtRisk'          => $this->subscriptions->countAboveQuotaRatio(self::QUOTA_ALERT_RATIO),
                'quotaAlertRatio'      => self::QUOTA_ALERT_RATIO,
            ],
        ]);
    }

    /**
     * Garantit un point par mois sur la fenêtre demandée, trous compris :
     * un graphe temporel ne doit pas sauter les mois sans donnée.
     *
     * @param array<string, int> $indexed clé "Y-m" => valeur
     * @return array<array{year: int, month: int, label: string, value: int}>
     */
    private function monthlySeries(\DateTimeImmutable $since, int $months, array $indexed, int $default): array
    {
        $series = [];

        for ($i = 0; $i < $months; $i++) {
            $cursor = $since->modify(sprintf('+%d months', $i));
            $key    = $cursor->format('Y-m');

            $series[] = [
                'year'  => (int) $cursor->format('Y'),
                'month' => (int) $cursor->format('n'),
                'label' => $key,
                'value' => $indexed[$key] ?? $default,
            ];
        }

        return $series;
    }

    /** @return array<string, int> */
    private function indexSeatMonths(): array
    {
        $indexed = [];
        foreach ($this->usageLogs->monthlyStats() as $row) {
            $indexed[sprintf('%04d-%02d', $row['year'], $row['month'])] = (int) $row['count'];
        }

        return $indexed;
    }

    /** @return array<string, int> */
    private function indexRevenueMonths(\DateTimeImmutable $since): array
    {
        $indexed = [];
        foreach ($this->surplusInvoices->monthlyTotals($since) as $row) {
            $indexed[sprintf('%04d-%02d', $row['year'], $row['month'])] = $row['amountCents'];
        }

        return $indexed;
    }

    /**
     * Expose tous les statuts connus, y compris ceux à zéro.
     *
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    private function fillStatuses(array $counts): array
    {
        $statuses = [
            Subscription::STATUS_ACTIVE,
            Subscription::STATUS_TRIALING,
            Subscription::STATUS_PAST_DUE,
            Subscription::STATUS_UNPAID,
            Subscription::STATUS_CANCELED,
        ];

        $filled = [];
        foreach ($statuses as $status) {
            $filled[$status] = (int) ($counts[$status] ?? 0);
        }

        return $filled;
    }
}
