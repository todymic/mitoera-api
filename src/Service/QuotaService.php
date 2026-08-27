<?php

namespace App\Service;

use App\Entity\Subscription;
use App\Entity\SeatUsage;
use App\Entity\SurplusInvoice;
use App\Repository\SeatUsageRepository;
use App\Repository\SurplusInvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\StripeClient;

class QuotaService
{
    public function __construct(
        private readonly SeatUsageRepository    $usageRepo,
        private readonly SurplusInvoiceRepository $surplusRepo,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface        $logger,
        private readonly string                 $stripeSecretKey,
    ) {}

    // ── Consume ───────────────────────────────────────────────────────────────

    /**
     * Called every time a seat is confirmed sold (ticket purchase confirmed).
     * Atomically increments the cumulative counter.
     */
    public function consume(Subscription $sub, int $seats = 1): void
    {
        $usage = $this->usageRepo->incrementAtomic($sub, $seats);

        $prev = $usage->getSeatsUsedCumul() - $seats;
        if ($prev < $sub->getAnnualSeatQuota() && $usage->getSeatsUsedCumul() >= $sub->getAnnualSeatQuota()) {
            // Just crossed the quota threshold — log once (notification email can hook here)
            $this->logger->warning('Quota exceeded', [
                'workspace'  => $sub->getWorkspace()->getId(),
                'plan'       => $sub->getPlan(),
                'quota'      => $sub->getAnnualSeatQuota(),
                'used'       => $usage->getSeatsUsedCumul(),
                'surplus'    => $usage->getSurplusTotal(),
            ]);
        }
    }

    // ── Monthly surplus billing ───────────────────────────────────────────────

    /**
     * Called on the 1st of each month (via cron command) for every active subscription.
     *
     * Formula:
     *   surplus_total    = MAX(0, seats_used_cumul - annual_quota)
     *   surplus_to_bill  = surplus_total - surplus_billed_cumul
     *
     * If surplus_to_bill > 0, an invoice item is added to the next Stripe invoice.
     * The unique constraint on (subscription_id, billed_month) guarantees idempotence.
     */
    public function billMonthlySurplus(Subscription $sub, \DateTimeImmutable $billedMonth): void
    {
        $normalizedMonth = new \DateTimeImmutable($billedMonth->format('Y-m-01'));

        // Idempotence: skip if already billed for this month
        if ($this->surplusRepo->existsForMonth($sub, $normalizedMonth)) {
            $this->logger->info('Surplus already billed for month, skipping', [
                'subscription' => $sub->getId(),
                'month'        => $normalizedMonth->format('Y-m'),
            ]);
            return;
        }

        $usage = $this->usageRepo->findBySubscription($sub);
        if (!$usage) {
            return;
        }

        $surplusToBill = $usage->getSurplusToBill();
        if ($surplusToBill <= 0) {
            return; // quota not exceeded this month
        }

        $amountCents = $surplusToBill * $sub->getSurplusPriceCents();

        $this->logger->info('Billing surplus', [
            'subscription' => $sub->getId(),
            'month'        => $normalizedMonth->format('Y-m'),
            'seats'        => $surplusToBill,
            'amount_cents' => $amountCents,
        ]);

        // Create a Stripe invoice item (added to the next invoice automatically)
        $stripeItemId = null;
        if ($sub->getStripeCustomerId() && $sub->getStripeSubscriptionId()) {
            try {
                $stripe = new StripeClient($this->stripeSecretKey);
                $item   = $stripe->invoiceItems->create([
                    'customer'     => $sub->getStripeCustomerId(),
                    'subscription' => $sub->getStripeSubscriptionId(),
                    'amount'       => $amountCents,
                    'currency'     => 'eur',
                    'description'  => sprintf(
                        '%d sièges surplus — %s',
                        $surplusToBill,
                        $normalizedMonth->format('F Y')
                    ),
                ]);
                $stripeItemId = $item->id;
            } catch (\Exception $e) {
                $this->logger->error('Stripe invoice item creation failed', [
                    'subscription' => $sub->getId(),
                    'error'        => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        // Persist SurplusInvoice + update billed cumul in a single transaction
        $this->em->wrapInTransaction(function () use ($sub, $usage, $normalizedMonth, $surplusToBill, $amountCents, $stripeItemId) {
            $invoice = new SurplusInvoice();
            $invoice->setSubscription($sub);
            $invoice->setBilledMonth($normalizedMonth);
            $invoice->setSeatsBilled($surplusToBill);
            $invoice->setAmountCents($amountCents);
            $invoice->setStripeInvoiceItemId($stripeItemId);
            $this->em->persist($invoice);

            $usage->setSurplusBilledCumul($usage->getSurplusBilledCumul() + $surplusToBill);
            $usage->touch();

            $this->em->flush();
        });
    }

    // ── Annual reset ──────────────────────────────────────────────────────────

    /**
     * Called when invoice.payment_succeeded with billing_reason = subscription_cycle.
     * Resets both cumulative counters for the new annual period.
     */
    public function resetForNewPeriod(Subscription $sub): void
    {
        $usage = $this->usageRepo->findBySubscription($sub);
        if (!$usage) {
            return;
        }

        $usage->setSeatsUsedCumul(0);
        $usage->setSurplusBilledCumul(0);
        $usage->touch();
        $this->em->flush();

        $this->logger->info('Annual quota reset', [
            'subscription' => $sub->getId(),
            'plan'         => $sub->getPlan(),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function getUsageSummary(Subscription $sub): array
    {
        $usage = $this->usageRepo->findBySubscription($sub);

        return [
            'annualQuota'        => $sub->getAnnualSeatQuota(),
            'seatsUsedCumul'     => $usage?->getSeatsUsedCumul() ?? 0,
            'surplusTotal'       => $usage?->getSurplusTotal() ?? 0,
            'surplusBilledCumul' => $usage?->getSurplusBilledCumul() ?? 0,
            'surplusToBill'      => $usage?->getSurplusToBill() ?? 0,
            'percentUsed'        => $sub->getAnnualSeatQuota() > 0
                ? round((($usage?->getSeatsUsedCumul() ?? 0) / $sub->getAnnualSeatQuota()) * 100, 1)
                : 0,
        ];
    }
}
