<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\StripeClient;

class StripeService
{
    public const PLANS = [
        'starter'    => ['label' => 'Starter',    'seats' => 500,   'priceEnvKey' => 'STRIPE_PRICE_STARTER'],
        'pro'        => ['label' => 'Pro',         'seats' => 2000,  'priceEnvKey' => 'STRIPE_PRICE_PRO'],
        'enterprise' => ['label' => 'Enterprise',  'seats' => 10000, 'priceEnvKey' => 'STRIPE_PRICE_ENTERPRISE'],
    ];

    private StripeClient $stripe;

    public function __construct(
        private readonly string $secretKey,
        private readonly string $webhookSecret,
        private readonly string $priceStarter,
        private readonly string $pricePro,
        private readonly string $priceEnterprise,
        private readonly EntityManagerInterface $em,
    ) {
        $this->stripe = new StripeClient($this->secretKey);
    }

    // ── customer ─────────────────────────────────────────────────────────────

    public function getOrCreateCustomer(User $user): string
    {
        if ($user->getStripeCustomerId()) {
            return $user->getStripeCustomerId();
        }

        $customer = $this->stripe->customers->create([
            'email'    => $user->getEmail(),
            'name'     => $user->getDisplayName() ?? $user->getEmail(),
            'metadata' => ['userId' => $user->getId()->toRfc4122()],
        ]);

        $user->setStripeCustomerId($customer->id);
        $this->em->flush();

        return $customer->id;
    }

    // ── subscription ─────────────────────────────────────────────────────────

    public function getSubscription(User $user): ?array
    {
        if (!$user->getStripeSubscriptionId()) {
            return null;
        }

        try {
            $sub = $this->stripe->subscriptions->retrieve($user->getStripeSubscriptionId(), [
                'expand' => ['default_payment_method', 'latest_invoice'],
            ]);

            return $this->formatSubscription($sub);
        } catch (\Exception) {
            return null;
        }
    }

    private function formatSubscription(\Stripe\Subscription $sub): array
    {
        $price   = $sub->items->data[0]->price ?? null;
        $planKey = $this->priceIdToPlanKey($price?->id ?? '');
        $pm      = $sub->default_payment_method;

        return [
            'id'               => $sub->id,
            'status'           => $sub->status,
            'planKey'          => $planKey,
            'planLabel'        => self::PLANS[$planKey]['label'] ?? 'Inconnu',
            'seats'            => self::PLANS[$planKey]['seats'] ?? 0,
            'currentPeriodEnd' => $sub->current_period_end,
            'cancelAtPeriodEnd'=> $sub->cancel_at_period_end,
            'card'             => $pm instanceof \Stripe\PaymentMethod ? [
                'brand' => $pm->card->brand,
                'last4' => $pm->card->last4,
                'expMonth' => $pm->card->exp_month,
                'expYear'  => $pm->card->exp_year,
            ] : null,
        ];
    }

    // ── checkout session ─────────────────────────────────────────────────────

    public function createCheckoutSession(User $user, string $planKey, string $successUrl, string $cancelUrl): string
    {
        $priceId    = $this->planKeyToPriceId($planKey);
        $customerId = $this->getOrCreateCustomer($user);

        $params = [
            'customer'   => $customerId,
            'mode'       => 'subscription',
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'success_url' => $successUrl,
            'cancel_url'  => $cancelUrl,
            'subscription_data' => [
                'metadata' => ['userId' => $user->getId()->toRfc4122(), 'planKey' => $planKey],
            ],
        ];

        // If already subscribed → use upgrade/downgrade flow
        if ($user->getStripeSubscriptionId()) {
            $sub = $this->stripe->subscriptions->retrieve($user->getStripeSubscriptionId());
            $this->stripe->subscriptions->update($user->getStripeSubscriptionId(), [
                'items' => [['id' => $sub->items->data[0]->id, 'price' => $priceId]],
                'proration_behavior' => 'always_invoice',
                'metadata' => ['planKey' => $planKey],
            ]);
            $user->setStripePlanKey($planKey);
            $this->em->flush();
            return 'upgraded';
        }

        $session = $this->stripe->checkout->sessions->create($params);
        return $session->url;
    }

    // ── customer portal ───────────────────────────────────────────────────────

    public function createPortalSession(User $user, string $returnUrl): string
    {
        $customerId = $this->getOrCreateCustomer($user);
        $session    = $this->stripe->billingPortal->sessions->create([
            'customer'   => $customerId,
            'return_url' => $returnUrl,
        ]);
        return $session->url;
    }

    // ── invoices ─────────────────────────────────────────────────────────────

    public function getInvoices(User $user, int $limit = 20): array
    {
        if (!$user->getStripeCustomerId()) {
            return [];
        }

        $invoices = $this->stripe->invoices->all([
            'customer' => $user->getStripeCustomerId(),
            'limit'    => $limit,
        ]);

        return array_map(fn(\Stripe\Invoice $inv) => [
            'id'         => $inv->id,
            'number'     => $inv->number,
            'status'     => $inv->status,
            'amount'     => $inv->amount_paid / 100,
            'currency'   => strtoupper($inv->currency),
            'date'       => $inv->created,
            'dueDate'    => $inv->due_date,
            'pdfUrl'     => $inv->invoice_pdf,
            'hostedUrl'  => $inv->hosted_invoice_url,
        ], $invoices->data);
    }

    // ── webhook ───────────────────────────────────────────────────────────────

    public function constructWebhookEvent(string $payload, string $sigHeader): \Stripe\Event
    {
        return \Stripe\Webhook::constructEvent($payload, $sigHeader, $this->webhookSecret);
    }

    public function handleWebhookEvent(\Stripe\Event $event, UserRepository $userRepository): void
    {
        $object = $event->data->object;

        switch ($event->type) {
            case 'customer.subscription.created':
            case 'customer.subscription.updated':
                $userId = $object->metadata['userId'] ?? null;
                if ($userId) {
                    $user = $userRepository->find($userId);
                    if ($user) {
                        $user->setStripeSubscriptionId($object->id);
                        $user->setStripeSubscriptionStatus($object->status);
                        $user->setStripePlanKey($object->metadata['planKey'] ?? null);
                        $this->em->flush();
                    }
                }
                break;

            case 'customer.subscription.deleted':
                $userId = $object->metadata['userId'] ?? null;
                if ($userId) {
                    $user = $userRepository->find($userId);
                    if ($user) {
                        $user->setStripeSubscriptionId(null);
                        $user->setStripeSubscriptionStatus('canceled');
                        $user->setStripePlanKey(null);
                        $this->em->flush();
                    }
                }
                break;
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function planKeyToPriceId(string $planKey): string
    {
        return match ($planKey) {
            'starter'    => $this->priceStarter,
            'pro'        => $this->pricePro,
            'enterprise' => $this->priceEnterprise,
            default      => throw new \InvalidArgumentException("Unknown plan: $planKey"),
        };
    }

    private function priceIdToPlanKey(string $priceId): string
    {
        return match ($priceId) {
            $this->priceStarter    => 'starter',
            $this->pricePro        => 'pro',
            $this->priceEnterprise => 'enterprise',
            default                => 'unknown',
        };
    }
}
