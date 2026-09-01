<?php

namespace App\Controller;

use App\Entity\Subscription;
use App\Entity\Workspace;
use App\Repository\ApiKeyRepository;
use App\Repository\EventRepository;
use App\Repository\SeatUsageRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\SurplusInvoiceRepository;
use App\Repository\WorkspaceInvitationRepository;
use App\Repository\WorkspaceMemberRepository;
use App\Repository\WorkspaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Vue admin transverse sur les workspaces : lecture tous tenants confondus,
 * plus les ajustements d'abonnement qui ne dépendent pas de Stripe.
 */
#[Route('/api/admin/workspaces')]
#[IsGranted('ROLE_ADMIN')]
class AdminWorkspaceController extends AbstractController
{
    private const RECENT_EVENTS = 10;
    private const MAX_QUOTA = 10_000_000;
    private const MAX_SURPLUS_PRICE_CENTS = 100_000;

    public function __construct(
        private WorkspaceRepository $workspaces,
        private SubscriptionRepository $subscriptions,
        private SeatUsageRepository $seatUsages,
        private SurplusInvoiceRepository $surplusInvoices,
        private WorkspaceMemberRepository $members,
        private WorkspaceInvitationRepository $invitations,
        private ApiKeyRepository $apiKeys,
        private EventRepository $events,
        private EntityManagerInterface $em,
    ) {}

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $rows = array_map(function (array $row): array {
            $quota     = (int) $row['quota'];
            $seatsUsed = (int) $row['seats_used'];

            return [
                'id'           => $row['workspace_id'],
                'name'         => $row['workspace_name'],
                'slug'         => $row['slug'],
                'createdAt'    => $this->atom($row['created_at']),
                'membersCount' => (int) $row['members_count'],
                'eventsCount'  => (int) $row['events_count'],
                'subscription' => $row['subscription_id'] === null ? null : [
                    'id'                => $row['subscription_id'],
                    'plan'              => $row['plan'],
                    'planLabel'         => Subscription::PLANS[$row['plan']]['label'] ?? $row['plan'],
                    'status'            => $row['status'],
                    'quota'             => $quota,
                    'surplusPriceCents' => (int) $row['surplus_price_cents'],
                    'periodStart'       => $this->date($row['period_start']),
                    'periodEnd'         => $this->date($row['period_end']),
                    'hasStripeCustomer' => $row['stripe_customer_id'] !== null,
                ],
                'usage' => [
                    'seatsUsed'     => $seatsUsed,
                    'surplusBilled' => (int) $row['surplus_billed'],
                    'surplusTotal'  => max(0, $seatsUsed - $quota),
                    'quota'         => $quota,
                ],
            ];
        }, $this->workspaces->listWithUsage());

        return $this->json(['workspaces' => $rows, 'total' => count($rows)]);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function detail(string $id): JsonResponse
    {
        $workspace = $this->findWorkspace($id);
        if (!$workspace instanceof Workspace) {
            return $workspace;
        }

        $subscription = $this->subscriptions->findByWorkspace($workspace);
        $usage        = $subscription ? $this->seatUsages->findBySubscription($subscription) : null;

        return $this->json([
            'id'        => $workspace->getId()->toRfc4122(),
            'name'      => $workspace->getName(),
            'slug'      => $workspace->getSlug(),
            'createdAt' => $workspace->getCreatedAt()->format(\DateTimeInterface::ATOM),

            'subscription' => $subscription === null ? null : array_merge(
                $subscription->toArray(),
                ['hasStripeCustomer' => $subscription->getStripeCustomerId() !== null]
            ),

            'usage' => [
                'seatsUsed'     => $usage?->getSeatsUsedCumul() ?? 0,
                'surplusBilled' => $usage?->getSurplusBilledCumul() ?? 0,
                'surplusTotal'  => $usage?->getSurplusTotal() ?? 0,
                'surplusToBill' => $usage?->getSurplusToBill() ?? 0,
                'quota'         => $subscription?->getAnnualSeatQuota() ?? 0,
                'updatedAt'     => $usage?->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            ],

            'members' => array_map(fn ($member) => [
                'id'          => $member->getId()->toRfc4122(),
                'role'        => $member->getRole(),
                'joinedAt'    => $member->getJoinedAt()->format(\DateTimeInterface::ATOM),
                'userId'      => $member->getUser()->getId()->toRfc4122(),
                'email'       => $member->getUser()->getEmail(),
                'displayName' => $member->getUser()->getDisplayName(),
            ], $this->members->findByWorkspace($workspace)),

            'invitations' => array_map(fn ($invitation) => [
                'id'        => $invitation->getId()->toRfc4122(),
                'email'     => $invitation->getEmail(),
                'status'    => $invitation->getStatus(),
                'createdAt' => $invitation->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'expiresAt' => $invitation->getExpiresAt()->format(\DateTimeInterface::ATOM),
            ], $this->invitations->findBy(['workspace' => $workspace], ['createdAt' => 'DESC'])),

            // Jamais le secret : seulement l'identifiant public et l'état.
            'apiKeys' => array_map(fn ($key) => [
                'id'         => $key->getId()->toRfc4122(),
                'keyId'      => $key->getKeyId(),
                'name'       => $key->getName(),
                'scope'      => $key->getScope()->value,
                'active'     => $key->isActive(),
                'createdAt'  => $key->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'lastUsedAt' => $key->getLastUsedAt()?->format(\DateTimeInterface::ATOM),
            ], $this->apiKeys->findByWorkspace($workspace)),

            // Borné : un workspace actif peut compter des milliers d'événements.
            'eventsCount' => $this->events->countByWorkspace($workspace),
            'events' => array_map(fn ($event) => [
                'id'         => $event->getId()->toRfc4122(),
                'title'      => $event->getTitle(),
                'identifier' => $event->getIdentifier(),
                'createdAt'  => $event->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ], $this->events->findRecentByWorkspace($workspace, self::RECENT_EVENTS)),

            'surplusInvoices' => $subscription === null ? [] : array_map(fn ($invoice) => [
                'id'          => $invoice->getId()->toRfc4122(),
                'billedMonth' => $invoice->getBilledMonth()->format('Y-m-d'),
                'seatsBilled' => $invoice->getSeatsBilled(),
                'amountCents' => $invoice->getAmountCents(),
                'syncedToStripe' => $invoice->getStripeInvoiceItemId() !== null,
                'createdAt'   => $invoice->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ], $this->surplusInvoices->findBySubscription($subscription)),
        ]);
    }

    /**
     * Ajustement administratif du quota et du prix du surplus.
     *
     * Volontairement limité à ces deux champs : le plan et le statut sont
     * pilotés par Stripe, les changer ici désynchroniserait la facturation.
     */
    #[Route('/{id}/subscription', methods: ['PUT'])]
    public function updateSubscription(string $id, Request $request): JsonResponse
    {
        $workspace = $this->findWorkspace($id);
        if (!$workspace instanceof Workspace) {
            return $workspace;
        }

        $subscription = $this->subscriptions->findByWorkspace($workspace);
        if ($subscription === null) {
            return $this->json(['message' => 'Ce workspace n’a pas d’abonnement.'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['message' => 'Corps de requête invalide.'], Response::HTTP_BAD_REQUEST);
        }

        if (array_key_exists('annualSeatQuota', $data)) {
            $quota = filter_var($data['annualSeatQuota'], FILTER_VALIDATE_INT);
            if ($quota === false || $quota < 0 || $quota > self::MAX_QUOTA) {
                return $this->json(
                    ['message' => sprintf('Quota invalide (attendu entre 0 et %d).', self::MAX_QUOTA)],
                    Response::HTTP_BAD_REQUEST
                );
            }
            $subscription->setAnnualSeatQuota($quota);
        }

        if (array_key_exists('surplusPriceCents', $data)) {
            $price = filter_var($data['surplusPriceCents'], FILTER_VALIDATE_INT);
            if ($price === false || $price < 0 || $price > self::MAX_SURPLUS_PRICE_CENTS) {
                return $this->json(
                    ['message' => sprintf('Prix du surplus invalide (attendu entre 0 et %d centimes).', self::MAX_SURPLUS_PRICE_CENTS)],
                    Response::HTTP_BAD_REQUEST
                );
            }
            $subscription->setSurplusPriceCents($price);
        }

        $subscription->touch();
        $this->em->flush();

        return $this->json(array_merge(
            $subscription->toArray(),
            ['hasStripeCustomer' => $subscription->getStripeCustomerId() !== null]
        ));
    }

    private function findWorkspace(string $id): Workspace|JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return $this->json(['message' => 'Identifiant invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $workspace = $this->workspaces->find(Uuid::fromString($id));

        return $workspace ?? $this->json(['message' => 'Workspace introuvable.'], Response::HTTP_NOT_FOUND);
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
