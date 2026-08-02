<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\StripeService;
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
        private readonly StripeService $stripe,
        private readonly UserRepository $userRepository,
    ) {}

    private function currentUser(): User
    {
        $user = $this->getUser();
        assert($user instanceof User);
        return $user;
    }

    /**
     * GET /api/billing/subscription
     * Current subscription info for the logged-in user.
     */
    #[Route('/subscription', methods: ['GET'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    public function subscription(): JsonResponse
    {
        $user = $this->currentUser();
        try {
            $sub = $this->stripe->getSubscription($user);
        } catch (\Exception) {
            $sub = null;
        }

        return $this->json([
            'plans'        => StripeService::PLANS,
            'subscription' => $sub,
            'planKey'      => $user->getStripePlanKey(),
            'status'       => $user->getStripeSubscriptionStatus(),
        ]);
    }

    /**
     * POST /api/billing/checkout
     * Creates a Stripe Checkout session for a subscription.
     * Body: { planKey: 'starter'|'pro'|'enterprise', successUrl, cancelUrl }
     */
    #[Route('/checkout', methods: ['POST'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    public function checkout(Request $request): JsonResponse
    {
        $data       = json_decode($request->getContent(), true);
        $planKey    = $data['planKey']    ?? null;
        $successUrl = $data['successUrl'] ?? null;
        $cancelUrl  = $data['cancelUrl']  ?? null;

        if (!$planKey || !$successUrl || !$cancelUrl) {
            return $this->json(['error' => 'planKey, successUrl and cancelUrl are required'], 400);
        }

        if (!isset(StripeService::PLANS[$planKey])) {
            return $this->json(['error' => 'Invalid plan'], 400);
        }

        try {
            $url = $this->stripe->createCheckoutSession($this->currentUser(), $planKey, $successUrl, $cancelUrl);
            return $this->json(['url' => $url]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/billing/portal
     * Creates a Stripe Customer Portal session (card management, cancel, etc.)
     */
    #[Route('/portal', methods: ['POST'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    public function portal(Request $request): JsonResponse
    {
        $data      = json_decode($request->getContent(), true);
        $returnUrl = $data['returnUrl'] ?? null;

        if (!$returnUrl) {
            return $this->json(['error' => 'returnUrl is required'], 400);
        }

        try {
            $url = $this->stripe->createPortalSession($this->currentUser(), $returnUrl);
            return $this->json(['url' => $url]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/billing/invoices
     * List invoices from Stripe for the current user.
     */
    #[Route('/invoices', methods: ['GET'])]
    #[IsGranted('ROLE_BACKOFFICE')]
    public function invoices(Request $request): JsonResponse
    {
        $limit = min((int) ($request->query->get('limit', 20)), 100);
        try {
            $invoices = $this->stripe->getInvoices($this->currentUser(), $limit);
        } catch (\Exception $e) {
            $invoices = [];
        }

        return $this->json(['invoices' => $invoices]);
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

        $this->stripe->handleWebhookEvent($event, $this->userRepository);

        return new Response('OK', 200);
    }
}
