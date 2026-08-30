<?php

namespace App\Controller;

use App\Entity\Subscription;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\SubscriptionRepository;
use App\Repository\SurplusInvoiceRepository;
use App\Service\QuotaService;
use App\Service\StripeService;
use App\Service\WorkspaceContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/billing')]
class BillingController extends AbstractController
{
    public function __construct(
        private readonly StripeService            $stripe,
        private readonly QuotaService             $quota,
        private readonly SubscriptionRepository   $subscriptionRepo,
        private readonly SurplusInvoiceRepository $surplusRepo,
        private readonly WorkspaceContext         $workspaceContext,
    ) {}

    private function currentUser(): User
    {
        $user = $this->getUser();
        assert($user instanceof User);
        return $user;
    }

    private function currentWorkspace(): Workspace
    {
        $workspace = $this->workspaceContext->getWorkspace();
        if (!$workspace) {
            throw new \RuntimeException('No workspace in context');
        }
        return $workspace;
    }

    /**
     * GET /api/billing/subscription
     * Returns current subscription + quota usage for the workspace.
     */
    #[Route('/subscription', methods: ['GET'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    public function subscription(): JsonResponse
    {
        $workspace = $this->currentWorkspace();
        $sub       = $this->subscriptionRepo->findByWorkspace($workspace);

        if (!$sub) {
            return $this->json([
                'plans'        => Subscription::PLANS,
                'subscription' => null,
            ]);
        }

        return $this->json([
            'plans'          => Subscription::PLANS,
            'subscription'   => $sub->toArray(),
            'usage'          => $this->quota->getUsageSummary($sub),
            'surplusHistory' => array_map(fn($inv) => [
                'month'       => $inv->getBilledMonth()->format('Y-m'),
                'seatsBilled' => $inv->getSeatsBilled(),
                'amountCents' => $inv->getAmountCents(),
                'amountEur'   => round($inv->getAmountCents() / 100, 2),
            ], $this->surplusRepo->findBySubscription($sub)),
        ]);
    }

    /**
     * POST /api/billing/checkout
     * Creates a Stripe Checkout session for an annual mora/soa subscription.
     * Body: { planKey: 'mora'|'soa', successUrl, cancelUrl }
     */
    #[Route('/checkout', methods: ['POST'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    public function checkout(Request $request): JsonResponse
    {
        $data       = json_decode($request->getContent(), true) ?? [];
        $planKey    = $data['planKey']    ?? null;
        $successUrl = $data['successUrl'] ?? null;
        $cancelUrl  = $data['cancelUrl']  ?? null;

        if (!$planKey || !$successUrl || !$cancelUrl) {
            return $this->json(['error' => 'planKey, successUrl and cancelUrl are required'], 400);
        }

        if (!isset(Subscription::PLANS[$planKey])) {
            return $this->json(['error' => 'Invalid plan. Must be mora or soa.'], 400);
        }

        $user      = $this->currentUser();
        $workspace = $this->currentWorkspace();

        try {
            $url = $this->stripe->createCheckoutSession(
                workspace:  $workspace,
                planKey:    $planKey,
                email:      $user->getEmail(),
                name:       $user->getDisplayName() ?? $user->getEmail(),
                successUrl: $successUrl,
                cancelUrl:  $cancelUrl,
            );
            return $this->json(['url' => $url]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/billing/portal
     * Opens the Stripe Customer Portal (card management, cancellation, invoices).
     * Body: { returnUrl }
     */
    #[Route('/portal', methods: ['POST'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    public function portal(Request $request): JsonResponse
    {
        $data      = json_decode($request->getContent(), true) ?? [];
        $returnUrl = $data['returnUrl'] ?? null;

        if (!$returnUrl) {
            return $this->json(['error' => 'returnUrl is required'], 400);
        }

        try {
            $url = $this->stripe->createPortalSession($this->currentWorkspace(), $returnUrl);
            return $this->json(['url' => $url]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/billing/invoices
     * Lists Stripe invoices for the workspace customer.
     */
    #[Route('/invoices', methods: ['GET'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    public function invoices(Request $request): JsonResponse
    {
        $limit    = min((int) $request->query->get('limit', 20), 100);
        $invoices = $this->stripe->getInvoices($this->currentWorkspace(), $limit);

        return $this->json(['invoices' => $invoices]);
    }

    /**
     * POST /api/billing/change-plan
     * Switch the workspace subscription to a different plan.
     * Body: { planKey: 'mora'|'soa'|'tsena', successUrl, cancelUrl }
     */
    #[Route('/change-plan', methods: ['POST'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    public function changePlan(Request $request): JsonResponse
    {
        $data       = json_decode($request->getContent(), true) ?? [];
        $planKey    = $data['planKey']    ?? null;
        $successUrl = $data['successUrl'] ?? null;
        $cancelUrl  = $data['cancelUrl']  ?? null;

        if (!$planKey || !$successUrl || !$cancelUrl) {
            return $this->json(['error' => 'planKey, successUrl and cancelUrl are required'], 400);
        }

        if (!isset(Subscription::PLANS[$planKey])) {
            return $this->json(['error' => 'Invalid plan.'], 400);
        }

        try {
            $url = $this->stripe->changePlan(
                workspace:  $this->currentWorkspace(),
                planKey:    $planKey,
                successUrl: $successUrl,
                cancelUrl:  $cancelUrl,
            );
            return $this->json(['url' => $url]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/billing/webhook
     * Stripe webhook — no auth, signature verified via STRIPE_WEBHOOK_SECRET.
     */
    #[Route('/webhook', methods: ['POST'])]
    public function webhook(Request $request): Response
    {
        $payload   = $request->getContent();
        $sigHeader = $request->headers->get('Stripe-Signature', '');

        try {
            $event = $this->stripe->constructWebhookEvent($payload, $sigHeader);
        } catch (\Exception $e) {
            return new Response('Webhook error: ' . $e->getMessage(), 400);
        }

        try {
            $this->stripe->handleWebhookEvent($event);
        } catch (\Exception $e) {
            return new Response('Handler error: ' . $e->getMessage(), 500);
        }

        return new Response('OK', 200);
    }
}
