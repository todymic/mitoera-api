<?php

namespace App\Controller;

use App\Repository\SubscriptionEventRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\SurplusInvoiceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Facturation vue de l'admin : le surplus réellement facturé, ce qui reste à
 * facturer, et le journal des webhooks Stripe qui explique les écarts.
 */
#[Route('/api/admin/billing')]
#[IsGranted('ROLE_ADMIN')]
class AdminBillingController extends AbstractController
{
    private const INVOICE_LIMIT = 500;
    private const WEBHOOK_LIMIT = 200;
    private const SYNC_STATES = ['all', 'synced', 'pending'];

    public function __construct(
        private SurplusInvoiceRepository $invoices,
        private SubscriptionEventRepository $events,
        private SubscriptionRepository $subscriptions,
    ) {}

    #[Route('/invoices', methods: ['GET'])]
    public function invoiceList(Request $request): JsonResponse
    {
        $from = $this->parseMonth($request->query->get('from'));
        $to   = $this->parseMonth($request->query->get('to'));
        if ($from === false || $to === false) {
            return $this->json(['message' => 'Mois attendu au format AAAA-MM.'], Response::HTTP_BAD_REQUEST);
        }

        $workspaceId = $request->query->get('workspaceId');
        if ($workspaceId !== null && $workspaceId !== '' && !Uuid::isValid($workspaceId)) {
            return $this->json(['message' => 'Identifiant de workspace invalide.'], Response::HTTP_BAD_REQUEST);
        }
        $workspaceId = ($workspaceId === '' ? null : $workspaceId);

        $sync = (string) $request->query->get('sync', 'all');
        if (!in_array($sync, self::SYNC_STATES, true)) {
            return $this->json(
                ['message' => 'Filtre de synchronisation inconnu (all, synced ou pending).'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $rows = $this->invoices->search($from, $to, $workspaceId, $sync, self::INVOICE_LIMIT);

        $invoices = array_map(fn (array $row) => [
            'id'             => $row['id'],
            'billedMonth'    => $this->date($row['billed_month']),
            'seatsBilled'    => (int) $row['seats_billed'],
            'amountCents'    => (int) $row['amount_cents'],
            'syncedToStripe' => $row['stripe_invoice_item_id'] !== null,
            'createdAt'      => $this->atom($row['created_at']),
            'workspaceId'    => $row['workspace_id'],
            'workspaceName'  => $row['workspace_name'],
            'plan'           => $row['plan'],
        ], $rows);

        $pending = $this->subscriptions->pendingSurplus();

        return $this->json([
            'invoices' => $invoices,
            'totals'   => [
                'count'        => count($invoices),
                'seatsBilled'  => array_sum(array_column($invoices, 'seatsBilled')),
                'amountCents'  => array_sum(array_column($invoices, 'amountCents')),
                'syncedCount'  => count(array_filter($invoices, fn ($i) => $i['syncedToStripe'])),
                'pendingCount' => count(array_filter($invoices, fn ($i) => !$i['syncedToStripe'])),
            ],
            // Constaté mais pas encore facturé : indépendant des filtres,
            // c'est un état courant, pas un historique.
            'notYetBilled' => [
                'seats'       => $pending['seats'],
                'amountCents' => $pending['amountCents'],
            ],
            // Signale une troncature plutôt que de laisser croire à un total exhaustif.
            'truncated' => count($invoices) === self::INVOICE_LIMIT,
            'limit'     => self::INVOICE_LIMIT,
        ]);
    }

    #[Route('/webhooks', methods: ['GET'])]
    public function webhookJournal(Request $request): JsonResponse
    {
        $type = $request->query->get('type');
        $rows = $this->events->journal($type === '' ? null : $type, self::WEBHOOK_LIMIT);

        $events = array_map(fn (array $row) => [
            'id'            => $row['id'],
            'stripeEventId' => $row['stripe_event_id'],
            'type'          => $row['type'],
            'processedAt'   => $this->atom($row['processed_at']),
            'workspaceId'   => $row['workspace_id'],
            'workspaceName' => $row['workspace_name'],
        ], $rows);

        return $this->json([
            'events'    => $events,
            'types'     => $this->events->typeCounts(),
            'truncated' => count($events) === self::WEBHOOK_LIMIT,
            'limit'     => self::WEBHOOK_LIMIT,
        ]);
    }

    /** @return \DateTimeImmutable|null|false false si le format est invalide */
    private function parseMonth(?string $value): \DateTimeImmutable|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}$/', $value) !== 1) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value . '-01');

        return $date === false ? false : $date->setTime(0, 0);
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

    private function date(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d')
            : (new \DateTimeImmutable((string) $value))->format('Y-m-d');
    }
}
