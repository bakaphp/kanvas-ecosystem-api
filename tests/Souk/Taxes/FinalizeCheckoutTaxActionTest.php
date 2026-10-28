<?php

declare(strict_types=1);

namespace Tests\Souk\Taxes;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\Address;
use Kanvas\Locations\Models\Countries;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Taxes\Actions\FinalizeCheckoutTaxAction;
use Kanvas\Souk\Taxes\Enums\ConfigurationEnum;
use Kanvas\Souk\Taxes\Models\CreditReservation;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('serial')]
final class FinalizeCheckoutTaxActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'ecosystem', 'crm'];

    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->kanvasApp->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, 1);
        $this->kanvasApp->set(ConfigurationEnum::TAX_FAIL_OPEN->value, 1);
    }

    protected function tearDown(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, 0);
        $this->kanvasApp->set(ConfigurationEnum::TAX_FAIL_OPEN->value, 0);

        parent::tearDown();
    }

    private function createShippingAddress(): Address
    {
        $country = Countries::firstOrCreate(['code' => 'DO'], ['name' => 'Dominican Republic']);

        $address = new Address();
        $address->peoples_id = 0;
        $address->address = 'Test Street 1';
        $address->city = 'Santo Domingo';
        $address->state = 'Distrito Nacional';
        $address->zip = '10101';
        $address->countries_id = $country->getKey();
        $address->is_deleted = 0;
        $address->saveOrFail();

        return $address;
    }

    private function createOrderWithTaxableItems(array $overrides = []): Order
    {
        $user = auth()->user();

        $shippingAddress = $this->createShippingAddress();

        $order = Order::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->create(array_merge([
                'checkout_token' => 'test-checkout-' . uniqid(),
                'subtotal_amount' => 100.00,
                'total_gross_amount' => 100.00,
                'total_net_amount' => 100.00,
                'total_payable_amount' => 100.00,
                'tax_amount' => 0.00,
                'wallet_tender' => 0.00,
                'shipping_address_id' => $shippingAddress->getKey(),
            ], $overrides));

        return $order;
    }

    public function testBlocksWhenTaxEngineDisabled(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, 0);

        $order = $this->createOrderWithTaxableItems();

        $result = new FinalizeCheckoutTaxAction($order, $this->kanvasApp)->execute();

        $this->assertFalse($result['taxEnabled']);
        $this->assertNull($result['snapshotUuid']);
        $this->assertNull($result['inputHash']);

        $snapshotCount = OrderTaxSnapshot::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('cart_session_id', $order->checkout_token)
            ->count();

        $this->assertSame(0, $snapshotCount);
    }

    private function tryFinalize(Order $order): ?array
    {
        return new FinalizeCheckoutTaxAction($order, $this->kanvasApp)->execute();
    }

    public function testWritesAttemptKeyAndStatusAndPayloadBeforeGateway(): void
    {
        $order = $this->createOrderWithTaxableItems();

        $result = $this->tryFinalize($order);

        if ($result === null || ! $result['taxEnabled'] || $result['snapshotUuid'] === null) {
            $this->markTestSkipped('No tax routing rule configured — skip payload write check');
        }

        $snapshot = OrderTaxSnapshot::query()
            ->where('uuid', $result['snapshotUuid'])
            ->firstOrFail();

        $this->assertNotNull($snapshot->gateway_attempt_key);
        $this->assertSame('pending', $snapshot->gateway_attempt_status);
        $this->assertNotNull($snapshot->checkout_payload);
        $this->assertIsArray($snapshot->checkout_payload);
    }

    public function testSnapshotHasCartSessionId(): void
    {
        $checkoutToken = 'cart-session-' . uniqid();
        $order = $this->createOrderWithTaxableItems(['checkout_token' => $checkoutToken]);

        $result = $this->tryFinalize($order);

        if ($result === null || ! $result['taxEnabled'] || $result['snapshotUuid'] === null) {
            $this->markTestSkipped('No tax routing rule configured — skip cart_session_id check');
        }

        $snapshot = OrderTaxSnapshot::query()
            ->where('uuid', $result['snapshotUuid'])
            ->firstOrFail();

        $this->assertSame($checkoutToken, $snapshot->cart_session_id);
    }

    public function testReturnsGatewayDueAmount(): void
    {
        $order = $this->createOrderWithTaxableItems();

        $result = $this->tryFinalize($order);

        if ($result === null) {
            $this->markTestSkipped('Shipping address required in test env');
        }

        $this->assertArrayHasKey('gatewayDue', $result);
        $this->assertIsFloat($result['gatewayDue']);

        if ($result['taxEnabled'] && $result['snapshotUuid'] !== null) {
            $snapshot = OrderTaxSnapshot::query()
                ->where('uuid', $result['snapshotUuid'])
                ->firstOrFail();

            $payload = $snapshot->checkout_payload;
            $this->assertEqualsWithDelta((float) $payload['totals']['gateway_due'], $result['gatewayDue'], 0.001);
        }
    }

    public function testTaxDisabledReturnsOrderTotalDueAsGatewayDue(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, 0);

        $order = $this->createOrderWithTaxableItems([
            'total_net_amount' => 150.00,
        ]);

        $result = new FinalizeCheckoutTaxAction($order, $this->kanvasApp)->execute();

        $this->assertSame(150.0, $result['gatewayDue']);
        $this->assertSame(150.0, $result['payable']);
    }

    public function testCheckoutPayloadContainsRequiredKeys(): void
    {
        $order = $this->createOrderWithTaxableItems();

        $result = $this->tryFinalize($order);

        if ($result === null || ! $result['taxEnabled'] || $result['snapshotUuid'] === null) {
            $this->markTestSkipped('No tax routing rule configured — skip payload structure check');
        }

        $snapshot = OrderTaxSnapshot::query()
            ->where('uuid', $result['snapshotUuid'])
            ->firstOrFail();

        $payload = $snapshot->checkout_payload;

        $this->assertArrayHasKey('lines', $payload);
        $this->assertArrayHasKey('totals', $payload);
        $this->assertArrayHasKey('wallet_hold_transfer_id', $payload);
        $this->assertArrayHasKey('credit_reservation_id', $payload);
        $this->assertArrayHasKey('order_context', $payload);

        $totals = $payload['totals'];
        $this->assertArrayHasKey('gateway_due', $totals);
        $this->assertArrayHasKey('payable', $totals);
        $this->assertArrayHasKey('tax', $totals);
    }

    public function testNoCreditReservationCreatedWhenNoApplicableCredit(): void
    {
        $order = $this->createOrderWithTaxableItems();

        $result = $this->tryFinalize($order);

        if ($result === null || ! $result['taxEnabled'] || $result['snapshotUuid'] === null) {
            $this->markTestSkipped('No tax routing rule configured — skip credit reservation check');
        }

        $snapshot = OrderTaxSnapshot::query()
            ->where('uuid', $result['snapshotUuid'])
            ->firstOrFail();

        $payload = $snapshot->checkout_payload;
        $this->assertNull($payload['credit_reservation_id']);

        $reservationCount = CreditReservation::query()
            ->where('snapshot_id', $snapshot->id)
            ->count();

        $this->assertSame(0, $reservationCount);
    }
}
