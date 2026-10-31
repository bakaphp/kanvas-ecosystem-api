<?php

declare(strict_types=1);

namespace Tests\Connectors\Stripe;

use Baka\Contracts\AppInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Kanvas\Connectors\Stripe\Enums\CustomFieldEnum;
use Kanvas\Connectors\Stripe\Services\StripePaymentLinkService;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Stripe\PaymentLink;
use Tests\Connectors\Stripe\Fakes\FakeStripeClient;
use Tests\TestCase;

/**
 * Covers the tax-snapshot-aware Stripe Payment Link methods:
 * - createLinkFromSnapshot uses gateway_due, not cart total
 * - automatic_tax is rejected when souk tax engine is active
 * - STRIPE_PAYMENT_LINK_TAX_REVISION custom field is stored with snapshot uuid
 * - deactivateLinkFromSnapshot calls paymentLinks->update with active: false
 * - shipping_address_collection is not set on snapshot-based links
 */
final class StripePaymentLinkServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['commerce'];

    private function makeService(FakeStripeClient $client, array $appSettings = []): StripePaymentLinkService
    {
        $app = $this->createMock(AppInterface::class);
        $app->method('get')->willReturnCallback(
            fn (string $key) => $appSettings[$key] ?? null
        );

        return new StripePaymentLinkService($app, null, $client);
    }

    private function makeSnapshot(float $gatewayDue, ?string $existingLinkId = null): OrderTaxSnapshot
    {
        $snapshot = $this->getMockBuilder(OrderTaxSnapshot::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save'])
            ->getMock();

        $snapshot->method('save')->willReturn(true);
        $snapshot->id = 1;
        $snapshot->uuid = 'test-snapshot-uuid-5678';
        $snapshot->apps_id = 1;
        $snapshot->merchant_reference = 'ORD-002';
        $snapshot->checkout_payload = [
            'totals' => [
                'gateway_due' => $gatewayDue,
            ],
        ];
        $snapshot->stripe_payment_link_id = $existingLinkId;

        return $snapshot;
    }

    public function testCreateLinkFromSnapshotUsesGatewayDue(): void
    {
        $client = new FakeStripeClient();

        $fakeLink = PaymentLink::constructFrom([
            'id' => 'plink_test_001',
            'url' => 'https://buy.stripe.com/test_001',
            'active' => true,
        ]);

        $client->getPaymentLinks()->queueResponse('create', $fakeLink);

        $service = $this->makeService($client);
        $snapshot = $this->makeSnapshot(gatewayDue: 199.99);

        $link = $service->createLinkFromSnapshot($snapshot);

        $this->assertSame('plink_test_001', $link->id);

        $calls = $client->getPaymentLinks()->getCalls('create');
        $this->assertCount(1, $calls);

        $lineItem = $calls[0]['params']['line_items'][0];
        $this->assertSame(19999, $lineItem['price_data']['unit_amount']);
        $this->assertSame('usd', $lineItem['price_data']['currency']);
    }

    public function testCreateLinkRejectsAutomaticTax(): void
    {
        $client = new FakeStripeClient();
        $service = $this->makeService($client);
        $snapshot = $this->makeSnapshot(gatewayDue: 100.00);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('automatic_tax_not_allowed_with_souk_tax');

        $service->createLinkFromSnapshot($snapshot, ['automatic_tax' => true]);
    }

    public function testCreateLinkStoresTaxRevisionCustomField(): void
    {
        $client = new FakeStripeClient();

        $fakeLink = PaymentLink::constructFrom([
            'id' => 'plink_test_002',
            'url' => 'https://buy.stripe.com/test_002',
            'active' => true,
        ]);

        $client->getPaymentLinks()->queueResponse('create', $fakeLink);

        $service = $this->makeService($client);
        $snapshot = $this->makeSnapshot(gatewayDue: 50.00);

        $service->createLinkFromSnapshot($snapshot);

        $calls = $client->getPaymentLinks()->getCalls('create');
        $this->assertCount(1, $calls);

        $customFields = $calls[0]['params']['custom_fields'];
        $this->assertNotEmpty($customFields);

        $taxRevisionField = collect($customFields)->firstWhere(
            'key',
            CustomFieldEnum::STRIPE_PAYMENT_LINK_TAX_REVISION->value
        );

        $this->assertNotNull($taxRevisionField);
        $this->assertSame(
            mb_substr($snapshot->uuid, 0, 50),
            $taxRevisionField['text']['default_value']
        );
    }

    public function testDeactivateLinkCallsPaymentLinksUpdate(): void
    {
        $client = new FakeStripeClient();

        $fakeLink = PaymentLink::constructFrom([
            'id' => 'plink_test_003',
            'url' => 'https://buy.stripe.com/test_003',
            'active' => false,
        ]);

        $client->getPaymentLinks()->queueResponse('update', $fakeLink);

        $service = $this->makeService($client);
        $snapshot = $this->makeSnapshot(
            gatewayDue: 100.00,
            existingLinkId: 'plink_test_003',
        );

        $service->deactivateLinkFromSnapshot($snapshot);

        $updateCalls = $client->getPaymentLinks()->getCalls('update');
        $this->assertCount(1, $updateCalls);
        $this->assertSame('plink_test_003', $updateCalls[0]['params']['id']);
        $this->assertFalse($updateCalls[0]['params']['active']);
    }

    public function testCreateLinkShippingCollectionIsOff(): void
    {
        $client = new FakeStripeClient();

        $fakeLink = PaymentLink::constructFrom([
            'id' => 'plink_test_004',
            'url' => 'https://buy.stripe.com/test_004',
            'active' => true,
        ]);

        $client->getPaymentLinks()->queueResponse('create', $fakeLink);

        $service = $this->makeService($client);
        $snapshot = $this->makeSnapshot(gatewayDue: 75.00);

        $service->createLinkFromSnapshot($snapshot);

        $calls = $client->getPaymentLinks()->getCalls('create');
        $this->assertCount(1, $calls);

        $this->assertArrayNotHasKey('shipping_address_collection', $calls[0]['params']);
    }

    public function testDeactivateLinkSkippedWhenNoLinkId(): void
    {
        $client = new FakeStripeClient();
        $service = $this->makeService($client);
        $snapshot = $this->makeSnapshot(gatewayDue: 100.00);

        $service->deactivateLinkFromSnapshot($snapshot);

        $this->assertCount(0, $client->getPaymentLinks()->getCalls('update'));
    }

    public function testCreateLinkReusesExistingLinkWhenPresent(): void
    {
        $client = new FakeStripeClient();

        $existingLink = PaymentLink::constructFrom([
            'id' => 'plink_existing_001',
            'url' => 'https://buy.stripe.com/existing_001',
            'active' => true,
        ]);

        $client->getPaymentLinks()->queueResponse('retrieve', $existingLink);

        $service = $this->makeService($client);
        $snapshot = $this->makeSnapshot(
            gatewayDue: 100.00,
            existingLinkId: 'plink_existing_001',
        );

        $link = $service->createLinkFromSnapshot($snapshot);

        $this->assertSame('plink_existing_001', $link->id);
        $this->assertCount(0, $client->getPaymentLinks()->getCalls('create'));
    }
}
