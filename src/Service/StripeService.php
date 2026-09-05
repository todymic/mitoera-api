<?php

namespace App\Service;

use App\Entity\Subscription;
use App\Entity\SeatUsage;
use App\Entity\SubscriptionEvent;
use App\Entity\Workspace;
use App\Repository\SubscriptionRepository;
use App\Repository\SubscriptionEventRepository;
use App\Repository\SeatUsageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\StripeClient;

class StripeService
{
    private StripeClient $stripe;

    public function __construct(
        private readonly string                      $secretKey,
        private readonly string                      $webhookSecret,
        private readonly string                      $pricePlus,
        private readonly string                      $priceMax,
        private readonly EntityManagerInterface      $em,
        private readonly SubscriptionRepository      $subscriptionRepo,
        private readonly SubscriptionEventRepository $eventRepo,
        private readonly SeatUsageRepository         $usageRepo,
        private readonly LoggerInterface             $logger,
    ) {
        $this->stripe = new StripeClient($this->secretKey);
    }

    // ── Customer ──────────────────────────────────────────────────────────────

    public function getOrCreateCustomer(Workspace $workspace, string $email, string $name): string
    {
        $sub = $this->subscriptionRepo->findByWorkspace($workspace);
        if ($sub?->getStripeCustomerId()) {
            return $sub->getStripeCustomerId();
        }

        $customer = $this->stripe->customers->create([
            'email'    => $email,
            'name'     => $name,
            'metadata' => ['workspaceId' => $workspace->getId()->toRfc4122()],
        ]);

        return $customer->id;
    }

    // ── Checkout ──────────────────────────────────────────────────────────────

    /**
     * Creates an annual Stripe Checkout session for a mora/soa plan.
     * billing_cycle_anchor is set to the 1st of the current month so the annual
     * period always starts on the 1st regardless of the actual subscription day.
     */
    public function createCheckoutSession(
        Workspace $workspace,
        string    $planKey,
        string    $email,
        string    $name,
        string    $successUrl,
        string    $cancelUrl,
    ): string {
        if (!isset(Subscription::PLANS[$planKey])) {
            throw new \InvalidArgumentException("Unknown plan: $planKey");
        }

        // Base = pay-per-use: collect card via Stripe Checkout (setup mode), activate on webhook
        if ($planKey === Subscription::PLAN_BASE) {
            $customerId = $this->getOrCreateCustomer($workspace, $email, $name);

            $session = $this->stripe->checkout->sessions->create([
                'customer'             => $customerId,
                'mode'                 => 'setup',
                'payment_method_types' => ['card'],
                'metadata'             => [
                    'workspaceId' => $workspace->getId()->toRfc4122(),
                    'planKey'     => Subscription::PLAN_BASE,
                ],
                'success_url' => $successUrl,
                'cancel_url'  => $cancelUrl,
            ]);

            return $session->url;
        }

        $customerId = $this->getOrCreateCustomer($workspace, $email, $name);
        $priceId    = $this->planKeyToPriceId($planKey);

        // Anchor billing to the 1st of next month (current month's 1st is already past)
        $billingAnchor = (new \DateTimeImmutable('first day of next month midnight'))->getTimestamp();

        $session = $this->stripe->checkout->sessions->create([
            'customer'   => $customerId,
            'mode'       => 'subscription',
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'subscription_data' => [
                'billing_cycle_anchor' => $billingAnchor,
                'proration_behavior'   => 'none',
                'metadata'             => [
                    'workspaceId' => $workspace->getId()->toRfc4122(),
                    'planKey'     => $planKey,
                ],
            ],
            'success_url' => $successUrl,
            'cancel_url'  => $cancelUrl,
        ]);

        return $session->url;
    }

    /**
     * Switch an existing Stripe subscription to a new plan (mora ↔ soa).
     * For tsena, cancels the Stripe subscription and activates locally.
     * Returns the checkout URL (for tsena: successUrl directly).
     */
    public function changePlan(
        Workspace $workspace,
        string    $planKey,
        string    $successUrl,
        string    $cancelUrl,
    ): string {
        if (!isset(Subscription::PLANS[$planKey])) {
            throw new \InvalidArgumentException("Unknown plan: $planKey");
        }

        $sub = $this->subscriptionRepo->findByWorkspace($workspace);
        if (!$sub) {
            throw new \RuntimeException('No subscription found');
        }

        if ($sub->getPlan() === $planKey) {
            return $successUrl;
        }

        // Switching TO base: cancel Stripe sub + activate locally
        if ($planKey === Subscription::PLAN_BASE) {
            if ($sub->getStripeSubscriptionId()) {
                $this->stripe->subscriptions->cancel($sub->getStripeSubscriptionId());
            }
            $this->activateBase($workspace, $sub->getStripeCustomerId());
            return $successUrl;
        }

        // Switching FROM tsena (no Stripe sub) OR between mora/soa
        if (!$sub->getStripeSubscriptionId()) {
            // Coming from tsena: need a full checkout
            $user = $workspace->getOwner();
            return $this->createCheckoutSession(
                $workspace,
                $planKey,
                $user?->getEmail() ?? '',
                $user?->getDisplayName() ?? '',
                $successUrl,
                $cancelUrl,
            );
        }

        // mora ↔ soa: update Stripe subscription items
        $stripeSub = $this->stripe->subscriptions->retrieve($sub->getStripeSubscriptionId());
        $itemId    = $stripeSub->items->data[0]->id;
        $priceId   = $this->planKeyToPriceId($planKey);

        $this->stripe->subscriptions->update($sub->getStripeSubscriptionId(), [
            'items'              => [['id' => $itemId, 'price' => $priceId]],
            'proration_behavior' => 'create_prorations',
            'metadata'           => ['planKey' => $planKey],
        ]);

        // Local update (webhook will also fire but this is immediate)
        $planConfig = Subscription::PLANS[$planKey];
        $sub->setPlan($planKey);
        $sub->setAnnualSeatQuota($planConfig['annual_seat_quota']);
        $sub->setSurplusPriceCents($planConfig['surplus_price_cents']);
        $sub->touch();
        $this->em->flush();

        return $successUrl;
    }

    // ── Portal ────────────────────────────────────────────────────────────────

    public function createPortalSession(Workspace $workspace, string $returnUrl): string
    {
        $sub = $this->subscriptionRepo->findByWorkspace($workspace);
        if (!$sub?->getStripeCustomerId()) {
            throw new \RuntimeException('No Stripe customer found for this workspace');
        }

        $session = $this->stripe->billingPortal->sessions->create([
            'customer'   => $sub->getStripeCustomerId(),
            'return_url' => $returnUrl,
        ]);

        return $session->url;
    }

    // ── Invoices ─────────────────────────────────────────────────────────────

    public function getInvoices(Workspace $workspace, int $limit = 20): array
    {
        $sub = $this->subscriptionRepo->findByWorkspace($workspace);
        if (!$sub?->getStripeCustomerId()) {
            return [];
        }

        $invoices = $this->stripe->invoices->all([
            'customer' => $sub->getStripeCustomerId(),
            'limit'    => $limit,
        ]);

        return array_map(fn(\Stripe\Invoice $inv) => [
            'id'         => $inv->id,
            'number'     => $inv->number,
            'status'     => $inv->status,
            'amount'     => $inv->amount_paid / 100,
            'currency'   => strtoupper($inv->currency),
            'date'       => $inv->created,
            'pdfUrl'     => $inv->invoice_pdf,
            'hostedUrl'  => $inv->hosted_invoice_url,
        ], $invoices->data);
    }

    // ── Webhook ───────────────────────────────────────────────────────────────

    public function constructWebhookEvent(string $payload, string $sigHeader): \Stripe\Event
    {
        return \Stripe\Webhook::constructEvent($payload, $sigHeader, $this->webhookSecret);
    }

    public function handleWebhookEvent(\Stripe\Event $event): void
    {
        // Idempotence: skip already-processed events
        if ($this->eventRepo->hasBeenProcessed($event->id)) {
            $this->logger->info('Stripe event already processed, skipping', ['event_id' => $event->id]);
            return;
        }

        $object = $event->data->object;

        try {
            match ($event->type) {
                'checkout.session.completed'       => $this->onCheckoutCompleted($object),
                'customer.subscription.updated'    => $this->onSubscriptionUpdated($object),
                'customer.subscription.deleted'    => $this->onSubscriptionDeleted($object),
                'invoice.payment_succeeded'        => $this->onInvoicePaymentSucceeded($object),
                'invoice.payment_failed'           => $this->onInvoicePaymentFailed($object),
                default                            => null,
            };
        } catch (\Exception $e) {
            $this->logger->error('Webhook handler error', [
                'event_type' => $event->type,
                'event_id'   => $event->id,
                'error'      => $e->getMessage(),
            ]);
            throw $e;
        }

        // Mark event as processed
        $subEntity = isset($object->metadata['workspaceId'])
            ? $this->findSubscriptionByWorkspaceId($object->metadata['workspaceId'] ?? '')
            : null;

        $eventEntity = new SubscriptionEvent();
        $eventEntity->setStripeEventId($event->id);
        $eventEntity->setType($event->type);
        $eventEntity->setSubscription($subEntity);
        $this->em->persist($eventEntity);
        $this->em->flush();
    }

    // ── Webhook handlers ──────────────────────────────────────────────────────

    private function onCheckoutCompleted(\Stripe\Session $session): void
    {
        if ($session->mode === 'setup') {
            $this->onSetupCheckoutCompleted($session);
            return;
        }

        if ($session->mode !== 'subscription') {
            return;
        }

        $workspaceId = $session->subscription_data?->metadata['workspaceId']
            ?? $session->metadata['workspaceId']
            ?? null;
        $planKey     = $session->subscription_data?->metadata['planKey']
            ?? $session->metadata['planKey']
            ?? null;

        if (!$workspaceId || !$planKey) {
            $this->logger->warning('checkout.session.completed: missing workspaceId or planKey metadata');
            return;
        }

        $stripeSubId = is_string($session->subscription)
            ? $session->subscription
            : $session->subscription->id;

        $stripeSub = $this->stripe->subscriptions->retrieve($stripeSubId);

        $workspace = $this->em->getReference(Workspace::class, $workspaceId);

        $planConfig  = Subscription::PLANS[$planKey];
        $periodStart = \DateTimeImmutable::createFromFormat('U', (string) $stripeSub->current_period_start)
            ->modify('first day of this month');
        $periodEnd   = \DateTimeImmutable::createFromFormat('U', (string) $stripeSub->current_period_end);

        // Create or update Subscription entity
        $sub = $this->subscriptionRepo->findByWorkspace($workspace) ?? new Subscription();
        $sub->setWorkspace($workspace);
        $sub->setPlan($planKey);
        $sub->setStripeSubscriptionId($stripeSub->id);
        $sub->setStripeCustomerId($stripeSub->customer);
        $sub->setStatus($stripeSub->status);
        $sub->setAnnualSeatQuota($planConfig['annual_seat_quota']);
        $sub->setSurplusPriceCents($planConfig['surplus_price_cents']);
        $sub->setPeriodStart($periodStart);
        $sub->setPeriodEnd($periodEnd);
        $sub->touch();
        $this->em->persist($sub);
        $this->em->flush();

        // Initialize SeatUsage counter
        if (!$this->usageRepo->findBySubscription($sub)) {
            $usage = new SeatUsage();
            $usage->setSubscription($sub);
            $this->em->persist($usage);
            $this->em->flush();
        }
    }

    private function onSetupCheckoutCompleted(\Stripe\Session $session): void
    {
        $workspaceId = $session->metadata['workspaceId'] ?? null;
        $planKey     = $session->metadata['planKey'] ?? null;

        if (!$workspaceId || $planKey !== Subscription::PLAN_BASE) {
            $this->logger->warning('checkout.session.completed (setup): missing or unexpected metadata', [
                'workspaceId' => $workspaceId,
                'planKey'     => $planKey,
            ]);
            return;
        }

        $customerId  = is_string($session->customer) ? $session->customer : $session->customer->id;
        $setupIntent = $this->stripe->setupIntents->retrieve(
            is_string($session->setup_intent) ? $session->setup_intent : $session->setup_intent->id
        );

        $paymentMethodId = is_string($setupIntent->payment_method)
            ? $setupIntent->payment_method
            : $setupIntent->payment_method->id;

        // Set as default payment method for future invoices
        $this->stripe->customers->update($customerId, [
            'invoice_settings' => ['default_payment_method' => $paymentMethodId],
        ]);

        $workspace = $this->em->getReference(Workspace::class, $workspaceId);
        $this->activateBase($workspace, $customerId);
    }

    private function onSubscriptionUpdated(\Stripe\Subscription $stripeSub): void
    {
        $sub = $this->subscriptionRepo->findByStripeSubscriptionId($stripeSub->id);
        if (!$sub) {
            return;
        }

        $planKey    = $stripeSub->metadata['planKey'] ?? $sub->getPlan();
        $planConfig = Subscription::PLANS[$planKey] ?? null;

        $sub->setStatus($stripeSub->status);
        if ($planConfig) {
            $sub->setPlan($planKey);
            $sub->setAnnualSeatQuota($planConfig['annual_seat_quota']);
            $sub->setSurplusPriceCents($planConfig['surplus_price_cents']);
        }
        $sub->setPeriodEnd(
            \DateTimeImmutable::createFromFormat('U', (string) $stripeSub->current_period_end)
        );
        $sub->touch();
        $this->em->flush();
    }

    private function onSubscriptionDeleted(\Stripe\Subscription $stripeSub): void
    {
        $sub = $this->subscriptionRepo->findByStripeSubscriptionId($stripeSub->id);
        if (!$sub) {
            return;
        }

        $sub->setStatus(Subscription::STATUS_CANCELED);
        $sub->touch();
        $this->em->flush();
    }

    private function onInvoicePaymentSucceeded(\Stripe\Invoice $invoice): void
    {
        if (!$invoice->subscription) {
            return;
        }

        $stripeSubId = is_string($invoice->subscription)
            ? $invoice->subscription
            : $invoice->subscription->id;

        $sub = $this->subscriptionRepo->findByStripeSubscriptionId($stripeSubId);
        if (!$sub) {
            return;
        }

        $sub->setStatus(Subscription::STATUS_ACTIVE);

        // Annual renewal: reset quota counters
        if ($invoice->billing_reason === 'subscription_cycle') {
            $usage = $this->usageRepo->findBySubscription($sub);
            if ($usage) {
                $usage->setSeatsUsedCumul(0);
                $usage->setSurplusBilledCumul(0);
                $usage->touch();
            }

            // Update period dates from Stripe
            $stripeSub = $this->stripe->subscriptions->retrieve($stripeSubId);
            $sub->setPeriodStart(
                \DateTimeImmutable::createFromFormat('U', (string) $stripeSub->current_period_start)
                    ->modify('first day of this month')
            );
            $sub->setPeriodEnd(
                \DateTimeImmutable::createFromFormat('U', (string) $stripeSub->current_period_end)
            );

            $this->logger->info('Annual quota reset after renewal', ['subscription' => $sub->getId()]);
        }

        $sub->touch();
        $this->em->flush();
    }

    private function onInvoicePaymentFailed(\Stripe\Invoice $invoice): void
    {
        if (!$invoice->subscription) {
            return;
        }

        $stripeSubId = is_string($invoice->subscription)
            ? $invoice->subscription
            : $invoice->subscription->id;

        $sub = $this->subscriptionRepo->findByStripeSubscriptionId($stripeSubId);
        if (!$sub) {
            return;
        }

        $sub->setStatus(Subscription::STATUS_PAST_DUE);
        $sub->touch();
        $this->em->flush();

        $this->logger->warning('Invoice payment failed', [
            'subscription' => $sub->getId(),
            'workspace'    => $sub->getWorkspace()->getId(),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function activateBase(Workspace $workspace, ?string $customerId): void
    {
        $planConfig  = Subscription::PLANS[Subscription::PLAN_BASE];
        $now         = new \DateTimeImmutable();
        $periodStart = new \DateTimeImmutable('first day of this month');
        $periodEnd   = new \DateTimeImmutable('last day of this month');

        $sub = $this->subscriptionRepo->findByWorkspace($workspace) ?? new Subscription();
        $sub->setWorkspace($workspace);
        $sub->setPlan(Subscription::PLAN_BASE);
        $sub->setStripeSubscriptionId(null);
        $sub->setStripeCustomerId($customerId);
        $sub->setStatus(Subscription::STATUS_ACTIVE);
        $sub->setAnnualSeatQuota($planConfig['annual_seat_quota']);
        $sub->setSurplusPriceCents($planConfig['surplus_price_cents']);
        $sub->setPeriodStart($periodStart);
        $sub->setPeriodEnd($periodEnd);
        $sub->touch();
        $this->em->persist($sub);
        $this->em->flush();

        if (!$this->usageRepo->findBySubscription($sub)) {
            $usage = new SeatUsage();
            $usage->setSubscription($sub);
            $this->em->persist($usage);
            $this->em->flush();
        }
    }

    private function planKeyToPriceId(string $planKey): string
    {
        return match ($planKey) {
            Subscription::PLAN_PLUS => $this->pricePlus,
            Subscription::PLAN_MAX  => $this->priceMax,
            default => throw new \InvalidArgumentException("Unknown plan: $planKey"),
        };
    }

    private function findSubscriptionByWorkspaceId(string $workspaceId): ?Subscription
    {
        if (!$workspaceId) {
            return null;
        }
        try {
            $workspace = $this->em->getReference(Workspace::class, $workspaceId);
            return $this->subscriptionRepo->findByWorkspace($workspace);
        } catch (\Exception) {
            return null;
        }
    }
}
