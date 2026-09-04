<?php

namespace App\Tests\Controller;

use App\Entity\SeatUsage;
use App\Entity\Subscription;
use App\Service\StripeService;
use PHPUnit\Framework\Attributes\DataProvider;

class BillingControllerTest extends AbstractApiTestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function mockStripe(array $methods = []): StripeService&\PHPUnit\Framework\MockObject\MockObject
    {
        $mock = $this->createMock(StripeService::class);

        if (!array_key_exists('createCheckoutSession', $methods)) {
            $mock->method('createCheckoutSession')->willReturn('https://checkout.stripe.com/fake');
        }
        if (!array_key_exists('createPortalSession', $methods)) {
            $mock->method('createPortalSession')->willReturn('https://billing.stripe.com/portal/fake');
        }
        if (!array_key_exists('getInvoices', $methods)) {
            $mock->method('getInvoices')->willReturn([]);
        }
        if (!array_key_exists('changePlan', $methods)) {
            $mock->method('changePlan')->willReturn('https://checkout.stripe.com/fake-change');
        }

        foreach ($methods as $method => $return) {
            if ($return instanceof \Exception) {
                $mock->method($method)->willThrowException($return);
            } else {
                $mock->method($method)->willReturn($return);
            }
        }

        return $mock;
    }

    private function registerStripe(\PHPUnit\Framework\MockObject\MockObject $mock): void
    {
        static::getContainer()->set(StripeService::class, $mock);
    }

    private function createSubscription(\App\Entity\Workspace $workspace, string $plan = 'plus'): Subscription
    {
        $sub = new Subscription();
        $sub->setWorkspace($workspace);
        $sub->setPlan($plan);
        $sub->setStatus(Subscription::STATUS_ACTIVE);
        $sub->setAnnualSeatQuota(2500);
        $sub->setSurplusPriceCents(15);
        $sub->setPeriodStart(new \DateTimeImmutable('2024-01-01'));
        $sub->setPeriodEnd(new \DateTimeImmutable('2024-12-31'));

        $usage = new SeatUsage();
        $usage->setSubscription($sub);
        $usage->setSeatsUsedCumul(1000);
        $usage->setSurplusBilledCumul(0);

        $this->em->persist($sub);
        $this->em->persist($usage);
        $this->em->flush();

        return $sub;
    }

    // ── GET /api/billing/subscription ─────────────────────────────────────────

    public function testSubscriptionRequiresAuth(): void
    {
        $this->jsonRequest('GET', '/api/billing/subscription');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testSubscriptionReturnsPlansAndNullWhenNoSub(): void
    {
        $this->registerStripe($this->mockStripe());
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('GET', '/api/billing/subscription', headers: $this->authHeaders($user, $workspace));

        $this->assertJsonStatus(200);
        $data = $this->responseData();

        $this->assertArrayHasKey('plans', $data);
        $this->assertArrayHasKey('base', $data['plans']);
        $this->assertArrayHasKey('plus', $data['plans']);
        $this->assertArrayHasKey('max', $data['plans']);
        $this->assertNull($data['subscription']);
    }

    public function testSubscriptionReturnsSubAndUsageWhenActive(): void
    {
        $this->registerStripe($this->mockStripe());
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);
        $this->createSubscription($workspace);

        $this->jsonRequest('GET', '/api/billing/subscription', headers: $this->authHeaders($user, $workspace));

        $this->assertJsonStatus(200);
        $data = $this->responseData();

        $this->assertNotNull($data['subscription']);
        $this->assertSame('plus', $data['subscription']['plan']);
        $this->assertSame(Subscription::STATUS_ACTIVE, $data['subscription']['status']);

        $this->assertArrayHasKey('usage', $data);
        $this->assertSame(2500, $data['usage']['annualQuota']);
        $this->assertSame(1000, $data['usage']['seatsUsedCumul']);

        $this->assertIsArray($data['surplusHistory']);
    }

    public function testSubscriptionRequiresWorkspaceContext(): void
    {
        $this->registerStripe($this->mockStripe());
        $user = $this->createUser();

        $this->jsonRequest('GET', '/api/billing/subscription', headers: $this->authHeaders($user));

        $this->assertResponseStatusCodeSame(500);
    }

    // ── POST /api/billing/checkout ────────────────────────────────────────────

    public function testCheckoutRequiresAuth(): void
    {
        $this->jsonRequest('POST', '/api/billing/checkout', ['planKey' => 'plus', 'successUrl' => 'https://example.com/ok', 'cancelUrl' => 'https://example.com/cancel']);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testCheckoutReturnsBadRequestWhenParamsMissing(): void
    {
        $this->registerStripe($this->mockStripe());
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('POST', '/api/billing/checkout', ['planKey' => 'plus'], $this->authHeaders($user, $workspace));

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('required', $this->responseData()['error']);
    }

    public function testCheckoutReturnsBadRequestForInvalidPlan(): void
    {
        $this->registerStripe($this->mockStripe());
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('POST', '/api/billing/checkout', [
            'planKey'    => 'enterprise',
            'successUrl' => 'https://app.test/ok',
            'cancelUrl'  => 'https://app.test/cancel',
        ], $this->authHeaders($user, $workspace));

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('Invalid plan', $this->responseData()['error']);
    }

    public function testCheckoutReturnsStripeUrl(): void
    {
        $this->registerStripe($this->mockStripe(['createCheckoutSession' => 'https://checkout.stripe.com/pay/cs_test_fake']));
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('POST', '/api/billing/checkout', [
            'planKey'    => 'plus',
            'successUrl' => 'https://app.test/success',
            'cancelUrl'  => 'https://app.test/cancel',
        ], $this->authHeaders($user, $workspace));

        $this->assertJsonStatus(200);
        $this->assertArrayHasKey('url', $this->responseData());
        $this->assertStringStartsWith('https://checkout.stripe.com', $this->responseData()['url']);
    }

    /**
     * Base plan: Stripe returns a setup-mode checkout URL (not success directly).
     */
    public function testCheckoutBasePlanReturnsSetupUrl(): void
    {
        $setupUrl = 'https://checkout.stripe.com/c/pay/cs_test_setup_fake';
        $this->registerStripe($this->mockStripe(['createCheckoutSession' => $setupUrl]));
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('POST', '/api/billing/checkout', [
            'planKey'    => 'base',
            'successUrl' => 'https://app.test/subscription?success=1',
            'cancelUrl'  => 'https://app.test/subscription',
        ], $this->authHeaders($user, $workspace));

        $this->assertJsonStatus(200);
        $data = $this->responseData();
        $this->assertArrayHasKey('url', $data);
        $this->assertSame($setupUrl, $data['url']);
    }

    #[DataProvider('planKeyProvider')]
    public function testCheckoutAllPlansAccepted(string $planKey): void
    {
        $this->registerStripe($this->mockStripe());
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('POST', '/api/billing/checkout', [
            'planKey'    => $planKey,
            'successUrl' => 'https://app.test/ok',
            'cancelUrl'  => 'https://app.test/cancel',
        ], $this->authHeaders($user, $workspace));

        $this->assertResponseStatusCodeSame(200, "Plan $planKey should be accepted");
    }

    public static function planKeyProvider(): array
    {
        return [['base'], ['plus'], ['max']];
    }

    public function testCheckoutReturns500WhenStripeThrows(): void
    {
        $this->registerStripe($this->mockStripe([
            'createCheckoutSession' => new \RuntimeException('Stripe connection failed'),
        ]));
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('POST', '/api/billing/checkout', [
            'planKey'    => 'plus',
            'successUrl' => 'https://app.test/ok',
            'cancelUrl'  => 'https://app.test/cancel',
        ], $this->authHeaders($user, $workspace));

        $this->assertResponseStatusCodeSame(500);
    }

    // ── POST /api/billing/portal ──────────────────────────────────────────────

    public function testPortalRequiresAuth(): void
    {
        $this->jsonRequest('POST', '/api/billing/portal', ['returnUrl' => 'https://app.test']);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testPortalReturnsBadRequestWhenNoReturnUrl(): void
    {
        $this->registerStripe($this->mockStripe());
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('POST', '/api/billing/portal', [], $this->authHeaders($user, $workspace));

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('returnUrl', $this->responseData()['error']);
    }

    public function testPortalReturnsPortalUrl(): void
    {
        $this->registerStripe($this->mockStripe(['createPortalSession' => 'https://billing.stripe.com/p/session_fake']));
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('POST', '/api/billing/portal', ['returnUrl' => 'https://app.test/billing'], $this->authHeaders($user, $workspace));

        $this->assertJsonStatus(200);
        $this->assertStringStartsWith('https://billing.stripe.com', $this->responseData()['url']);
    }

    // ── GET /api/billing/invoices ─────────────────────────────────────────────

    public function testInvoicesRequiresAuth(): void
    {
        $this->jsonRequest('GET', '/api/billing/invoices');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testInvoicesReturnsArray(): void
    {
        $this->registerStripe($this->mockStripe(['getInvoices' => [
            ['id' => 'in_fake', 'amount_due' => 30000, 'status' => 'paid'],
        ]]));
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('GET', '/api/billing/invoices', headers: $this->authHeaders($user, $workspace));

        $this->assertJsonStatus(200);
        $data = $this->responseData();
        $this->assertArrayHasKey('invoices', $data);
        $this->assertIsArray($data['invoices']);
        $this->assertCount(1, $data['invoices']);
        $this->assertSame('in_fake', $data['invoices'][0]['id']);
    }

    public function testInvoicesReturnsEmptyArrayWhenNone(): void
    {
        $this->registerStripe($this->mockStripe(['getInvoices' => []]));
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('GET', '/api/billing/invoices', headers: $this->authHeaders($user, $workspace));

        $this->assertJsonStatus(200);
        $this->assertSame([], $this->responseData()['invoices']);
    }

    // ── POST /api/billing/webhook ─────────────────────────────────────────────

    private function fakeStripeEvent(string $type, string $id): \Stripe\Event
    {
        return \Stripe\Event::constructFrom([
            'id'      => $id,
            'type'    => $type,
            'object'  => 'event',
            'data'    => ['object' => []],
            'livemode' => false,
        ]);
    }

    public function testWebhookReturnsBadRequestOnInvalidSignature(): void
    {
        $stripe = $this->mockStripe();
        $stripe->method('constructWebhookEvent')
            ->willThrowException(new \Exception('No signatures found matching the expected signature for payload'));
        $this->registerStripe($stripe);

        $this->client->request('POST', '/api/billing/webhook', [], [], [
            'CONTENT_TYPE'          => 'application/json',
            'HTTP_Stripe-Signature' => 'bad_sig',
        ], '{"type":"test","id":"evt_fake"}');

        $this->assertResponseStatusCodeSame(400);
    }

    public function testWebhookReturns200OnValidSubscriptionEvent(): void
    {
        $fakeEvent = $this->fakeStripeEvent('checkout.session.completed', 'evt_sub_fake');

        $stripe = $this->mockStripe();
        $stripe->method('constructWebhookEvent')->willReturn($fakeEvent);
        $this->registerStripe($stripe);

        $this->client->request('POST', '/api/billing/webhook', [], [], [
            'CONTENT_TYPE'          => 'application/json',
            'HTTP_Stripe-Signature' => 't=1234567890,v1=fake',
        ], json_encode(['type' => 'checkout.session.completed', 'id' => 'evt_sub_fake']));

        $this->assertResponseStatusCodeSame(200);
        $this->assertSame('OK', $this->client->getResponse()->getContent());
    }

    public function testWebhookReturns200OnSetupCompletedEvent(): void
    {
        $fakeEvent = $this->fakeStripeEvent('checkout.session.completed', 'evt_setup_fake');

        $stripe = $this->mockStripe();
        $stripe->method('constructWebhookEvent')->willReturn($fakeEvent);
        $this->registerStripe($stripe);

        $this->client->request('POST', '/api/billing/webhook', [], [], [
            'CONTENT_TYPE'          => 'application/json',
            'HTTP_Stripe-Signature' => 't=1234567890,v1=fake',
        ], json_encode(['type' => 'checkout.session.completed', 'id' => 'evt_setup_fake']));

        $this->assertResponseStatusCodeSame(200);
    }

    public function testWebhookReturns500WhenHandlerThrows(): void
    {
        $fakeEvent = $this->fakeStripeEvent('invoice.payment_succeeded', 'evt_handler_fail');

        $stripe = $this->mockStripe();
        $stripe->method('constructWebhookEvent')->willReturn($fakeEvent);
        $stripe->method('handleWebhookEvent')->willThrowException(new \RuntimeException('DB write failed'));
        $this->registerStripe($stripe);

        $this->client->request('POST', '/api/billing/webhook', [], [], [
            'CONTENT_TYPE'          => 'application/json',
            'HTTP_Stripe-Signature' => 't=1234567890,v1=fake',
        ], json_encode(['id' => 'evt_handler_fail']));

        $this->assertResponseStatusCodeSame(500);
    }

    // ── POST /api/billing/change-plan ─────────────────────────────────────────

    public function testChangePlanRequiresAuth(): void
    {
        $this->jsonRequest('POST', '/api/billing/change-plan', [
            'planKey'    => 'max',
            'successUrl' => 'https://app.test/ok',
            'cancelUrl'  => 'https://app.test/cancel',
        ]);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testChangePlanReturnsBadRequestWhenParamsMissing(): void
    {
        $this->registerStripe($this->mockStripe());
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('POST', '/api/billing/change-plan', ['planKey' => 'max'], $this->authHeaders($user, $workspace));

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('required', $this->responseData()['error']);
    }

    public function testChangePlanReturnsBadRequestForInvalidPlan(): void
    {
        $this->registerStripe($this->mockStripe());
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('POST', '/api/billing/change-plan', [
            'planKey'    => 'enterprise',
            'successUrl' => 'https://app.test/ok',
            'cancelUrl'  => 'https://app.test/cancel',
        ], $this->authHeaders($user, $workspace));

        $this->assertResponseStatusCodeSame(400);
        $this->assertStringContainsString('Invalid plan', $this->responseData()['error']);
    }

    public function testChangePlanPlusToMaxReturnsUrl(): void
    {
        $this->registerStripe($this->mockStripe(['changePlan' => 'https://checkout.stripe.com/pay/cs_change_fake']));
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);
        $this->createSubscription($workspace, 'plus');

        $this->jsonRequest('POST', '/api/billing/change-plan', [
            'planKey'    => 'max',
            'successUrl' => 'https://app.test/ok',
            'cancelUrl'  => 'https://app.test/cancel',
        ], $this->authHeaders($user, $workspace));

        $this->assertJsonStatus(200);
        $this->assertArrayHasKey('url', $this->responseData());
        $this->assertStringStartsWith('https://checkout.stripe.com', $this->responseData()['url']);
    }

    public function testChangePlanToBaseReturnsSuccessUrl(): void
    {
        $this->registerStripe($this->mockStripe(['changePlan' => 'https://app.test/ok']));
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);
        $this->createSubscription($workspace, 'plus');

        $this->jsonRequest('POST', '/api/billing/change-plan', [
            'planKey'    => 'base',
            'successUrl' => 'https://app.test/ok',
            'cancelUrl'  => 'https://app.test/cancel',
        ], $this->authHeaders($user, $workspace));

        $this->assertJsonStatus(200);
        $this->assertArrayHasKey('url', $this->responseData());
    }

    public function testChangePlanReturns500WhenStripeThrows(): void
    {
        $this->registerStripe($this->mockStripe([
            'changePlan' => new \RuntimeException('Stripe error'),
        ]));
        $user      = $this->createUser();
        $workspace = $this->createWorkspaceForUser($user);

        $this->jsonRequest('POST', '/api/billing/change-plan', [
            'planKey'    => 'max',
            'successUrl' => 'https://app.test/ok',
            'cancelUrl'  => 'https://app.test/cancel',
        ], $this->authHeaders($user, $workspace));

        $this->assertResponseStatusCodeSame(500);
    }
}
