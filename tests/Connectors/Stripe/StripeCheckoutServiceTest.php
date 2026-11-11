<?php

declare(strict_types=1);

namespace Tests\Connectors\Stripe;

use Baka\Contracts\AppInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Kanvas\Connectors\Stripe\Services\StripeCheckoutService;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Stripe\Checkout\Session;
use Tests\Connectors\Stripe\Fakes\FakeStripeClient;
use Tests\TestCase;

/**
 * Covers the tax-snapshot-aware Stripe Checkout Session methods:
 * - createSessionFromSnapshot uses gateway_due, not cart total
 * - automatic_tax is rejected when souk tax engine is active
 * - expireSessionsForSnapshot expires open sessions on supersede
 * - missing destination account is refused
 */
final class StripeCheckoutServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['commerce'];

    private function makeService(FakeStripeClient $client, array $appSettings = []): StripeCheckoutService
    {
        $app = $this->createMock(AppInterface::class);
        $app->method('get')->willReturnCallback(
            fn (string $key) => $appSettings[$key] ?? null
        );

        return new StripeCheckoutService($app, null, $client);
    }

    private function makeSnapshot(float $gatewayDue, ?string $existingSessionId = null): OrderTaxSnapshot
    {
        $snapshot = $this->getMockBuilder(OrderTaxSnapshot::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save'])
            ->getMock();

        $snapshot->method('save')->willReturn(true);
        $snapshot->id = 1;
        $snapshot->uuid = 'test-snapshot-uuid-1234';
        $snapshot->apps_id = 1;
        $snapshot->merchant_reference = 'ORD-001';
        $snapshot->checkout_payload = [
            'totals' => [
                'gateway_due' => $gatewayDue,
            ],
        ];
        $snapshot->stripe_session_id = $existingSessionId;

        return $snapshot;
    }

    public function testCreateSessionUsesGatewayDueFromSnapshot(): void
    {
        $client = new FakeStripeClient();

        $fakeSession = Session::constructFrom([
            'id' => 'cs_test_001',
            'url' => 'https://checkout.stripe.com/pay/cs_test_001',
            'status' => 'open',
        ]);

        $client->getCheckout()->sessions->queueResponse('create', $fakeSession);

        $service = $this->makeService($client);
        $snapshot = $this->makeSnapshot(gatewayDue: 149.99);

        $session = $service->createSessionFromSnapshot(
            $snapshot,
            'https://example.com/success',
            'https://example.com/cancel',
        );

        $this->assertSame('cs_test_001', $session->id);

        $calls = $client->getCheckout()->sessions->getCalls('create');
        $this->assertCount(1, $calls);

        $lineItem = $calls[0]['params']['line_items'][0];
        $this->assertSame(14999, $lineItem['price_data']['unit_amount']);
        $this->assertSame('usd', $lineItem['price_data']['currency']);
        $this->assertSame('https://example.com/success', $calls[0]['params']['success_url']);
        $this->assertSame('https://example.com/cancel', $calls[0]['params']['cancel_url']);
        $this->assertNull($calls[0]['params']['shipping_address_collection']);
    }

    public function testCreateSessionRejectsAutomaticTax(): void
    {
        $client = new FakeStripeClient();
        $service = $this->makeService($client);
        $snapshot = $this->makeSnapshot(gatewayDue: 100.00);

        $this->expectException(ValidationException::class);

        $service->createSessionFromSnapshot(
            $snapshot,
            'https://example.com/success',
            'https://example.com/cancel',
            ['automatic_tax' => true],
        );
    }

    public function testCreateSessionReusesPreviousSessionWhenNotExpired(): void
    {
        $client = new FakeStripeClient();

        $existingSession = Session::constructFrom([
            'id' => 'cs_existing_001',
            'url' => 'https://checkout.stripe.com/pay/cs_existing_001',
            'status' => 'open',
        ]);

        $client->getCheckout()->sessions->queueResponse('retrieve', $existingSession);

        $service = $this->makeService($client);
        $snapshot = $this->makeSnapshot(
            gatewayDue: 100.00,
            existingSessionId: 'cs_existing_001',
        );

        $session = $service->createSessionFromSnapshot(
            $snapshot,
            'https://example.com/success',
            'https://example.com/cancel',
        );

        $this->assertSame('cs_existing_001', $session->id);
        $this->assertCount(0, $client->getCheckout()->sessions->getCalls('create'));
    }

    public function testCreateSessionCreatesNewSessionWhenPreviousIsExpired(): void
    {
        $client = new FakeStripeClient();

        $expiredSession = Session::constructFrom([
            'id' => 'cs_expired_001',
            'status' => 'expired',
        ]);

        $newSession = Session::constructFrom([
            'id' => 'cs_new_001',
            'url' => 'https://checkout.stripe.com/pay/cs_new_001',
            'status' => 'open',
        ]);

        $client->getCheckout()->sessions->queueResponse('retrieve', $expiredSession);
        $client->getCheckout()->sessions->queueResponse('create', $newSession);

        $service = $this->makeService($client);
        $snapshot = $this->makeSnapshot(
            gatewayDue: 100.00,
            existingSessionId: 'cs_expired_001',
        );

        $session = $service->createSessionFromSnapshot(
            $snapshot,
            'https://example.com/success',
            'https://example.com/cancel',
        );

        $this->assertSame('cs_new_001', $session->id);
        $this->assertCount(1, $client->getCheckout()->sessions->getCalls('create'));
    }

    public function testExpireSessionCalledOnSupersede(): void
    {
        $client = new FakeStripeClient();

        $openSession = Session::constructFrom([
            'id' => 'cs_open_001',
            'status' => 'open',
        ]);

        $client->getCheckout()->sessions->queueResponse('retrieve', $openSession);
        $client->getCheckout()->sessions->queueResponse('expire', Session::constructFrom(['id' => 'cs_open_001', 'status' => 'expired']));

        $service = $this->makeService($client);
        $snapshot = $this->makeSnapshot(
            gatewayDue: 100.00,
            existingSessionId: 'cs_open_001',
        );

        $service->expireSessionsForSnapshot($snapshot);

        $expireCalls = $client->getCheckout()->sessions->getCalls('expire');
        $this->assertCount(1, $expireCalls);
        $this->assertEquals(['id' => 'cs_open_001'], $expireCalls[0]['params']);
    }

    public function testExpireSessionSkippedWhenNoSessionId(): void
    {
        $client = new FakeStripeClient();
        $service = $this->makeService($client);
        $snapshot = $this->makeSnapshot(gatewayDue: 100.00);

        $service->expireSessionsForSnapshot($snapshot);

        $this->assertCount(0, $client->getCheckout()->sessions->getCalls('expire'));
    }

    public function testExpireSessionSkippedWhenSessionAlreadyClosed(): void
    {
        $client = new FakeStripeClient();

        $completedSession = Session::constructFrom([
            'id' => 'cs_complete_001',
            'status' => 'complete',
        ]);

        $client->getCheckout()->sessions->queueResponse('retrieve', $completedSession);

        $service = $this->makeService($client);
        $snapshot = $this->makeSnapshot(
            gatewayDue: 100.00,
            existingSessionId: 'cs_complete_001',
        );

        $service->expireSessionsForSnapshot($snapshot);

        $this->assertCount(0, $client->getCheckout()->sessions->getCalls('expire'));
    }

    public function testNoDestinationRefused(): void
    {
        $client = new FakeStripeClient();
        $service = $this->makeService($client, ['STRIPE_SESSION_REQUIRE_DESTINATION' => true]);
        $snapshot = $this->makeSnapshot(gatewayDue: 100.00);

        $this->expectException(ValidationException::class);

        $service->createSessionFromSnapshot(
            $snapshot,
            'https://example.com/success',
            'https://example.com/cancel',
        );
    }

    public function testSnapshotMetadataStoredOnSession(): void
    {
        $client = new FakeStripeClient();

        $fakeSession = Session::constructFrom([
            'id' => 'cs_meta_001',
            'url' => 'https://checkout.stripe.com/pay/cs_meta_001',
            'status' => 'open',
        ]);

        $client->getCheckout()->sessions->queueResponse('create', $fakeSession);

        $service = $this->makeService($client);
        $snapshot = $this->makeSnapshot(gatewayDue: 50.00);

        $service->createSessionFromSnapshot(
            $snapshot,
            'https://example.com/success',
            'https://example.com/cancel',
        );

        $calls = $client->getCheckout()->sessions->getCalls('create');
        $metadata = $calls[0]['params']['metadata'];
        $this->assertSame('test-snapshot-uuid-1234', $metadata['snapshot_uuid']);
    }
}
