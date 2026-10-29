<?php

declare(strict_types=1);

namespace Tests\Souk\Taxes;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Actions\UpdateOrderAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Taxes\Enums\ConfigurationEnum;
use Kanvas\Souk\Taxes\Enums\GatewayAttemptStatusEnum;
use Kanvas\Souk\Taxes\Enums\TaxStatusEnum;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Kanvas\Souk\Taxes\Providers\NullTaxProvider;
use Kanvas\Souk\Taxes\Providers\TaxProviderResolver;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('serial')]
final class OrderTaxTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'ecosystem'];

    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
    }

    protected function tearDown(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, 0);
        $this->kanvasApp->set(ConfigurationEnum::TAX_FAIL_OPEN->value, 0);

        parent::tearDown();
    }

    private function createOrder(array $overrides = []): Order
    {
        $user = auth()->user();

        return Order::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->create($overrides);
    }

    private function makeSnapshot(Order $order, array $overrides = []): OrderTaxSnapshot
    {
        $inputHash = hash('sha256', 'test-hash-' . uniqid());

        $snapshot = new OrderTaxSnapshot();
        $snapshot->apps_id = $this->kanvasApp->getId();
        $snapshot->order_id = $order->id;
        $snapshot->cart_session_id = $order->checkout_token ?? ('cart-' . uniqid());
        $snapshot->kind = 'calculation';
        $snapshot->treatment = 'taxed';
        $snapshot->status = $overrides['status'] ?? TaxStatusEnum::CALCULATED->value;
        $snapshot->provider = $overrides['provider'] ?? 'manual_rate';
        $snapshot->input_hash = $inputHash;
        $snapshot->tax_amount = $overrides['tax_amount'] ?? 18.00;
        $snapshot->taxable_amount = 100.00;
        $snapshot->remaining_reversible_amount = 100.00;
        $snapshot->remaining_reversible_tax = $overrides['tax_amount'] ?? 18.00;
        $snapshot->gateway_attempt_key = hash('sha256', uniqid());
        $snapshot->gateway_attempt_status = GatewayAttemptStatusEnum::PENDING->value;
        $snapshot->souk_tax_engine_enabled = true;
        $snapshot->checkout_payload = [
            'input_hash' => $inputHash,
            'lines' => [
                [
                    'uuid' => 'line-uuid-1',
                    'variant_id' => 1,
                    'product_name' => 'Test Product',
                    'quantity' => 1,
                    'unit_price_net_amount' => 100.00,
                    'tax_amount' => $overrides['tax_amount'] ?? 18.00,
                ],
            ],
            'totals' => [
                'subtotal' => 100.00,
                'tax' => $overrides['tax_amount'] ?? 18.00,
                'gateway_due' => 118.00,
                'payable' => 118.00,
            ],
            'wallet_hold_transfer_id' => null,
            'credit_reservation_id' => null,
            'order_context' => [],
        ];

        $snapshot->saveOrFail();

        return $snapshot;
    }

    public function testPaidOrderUpdateRejectedWithValidationException(): void
    {
        $user = auth()->user();
        $order = $this->createOrder(['payment_status' => PaymentStatusEnum::PAID->value]);

        $this->expectException(ValidationException::class);

        new UpdateOrderAction(
            $order,
            ['fulfillment_status' => 'shipped'],
            $user,
        )->execute();
    }

    public function testUnpaidOrderUpdateSupersedesSnapshot(): void
    {
        $user = auth()->user();
        $order = $this->createOrder([
            'payment_status' => 'unpaid',
            'checkout_token' => 'cart-supersede-' . uniqid(),
        ]);

        $snapshot = $this->makeSnapshot($order, ['status' => TaxStatusEnum::CALCULATED->value]);

        $this->assertSame(TaxStatusEnum::CALCULATED->value, $snapshot->status);

        $updateAction = new UpdateOrderAction(
            $order,
            ['fulfillment_status' => $order->fulfillment_status],
            $user,
        );
        $updateAction->disableWorkflow();
        $updateAction->execute();

        $snapshot->refresh();

        $this->assertSame('superseded', $snapshot->status);
    }

    public function testFreeOrderUsesNullTaxProvider(): void
    {
        // Tests the TaxProviderResolver::resolveFromSnapshot() contract directly:
        // a snapshot with provider='none' (created for zero-total orders) must
        // resolve to NullTaxProvider so no tax calculation or commit is attempted.
        $this->kanvasApp->set(ConfigurationEnum::TAX_ENGINE_ENABLED->value, 1);
        $this->kanvasApp->set(ConfigurationEnum::TAX_FAIL_OPEN->value, 1);

        $snapshot = new OrderTaxSnapshot();
        $snapshot->apps_id = $this->kanvasApp->getId();
        $snapshot->order_id = null;
        $snapshot->cart_session_id = 'free-order-' . uniqid();
        $snapshot->kind = 'calculation';
        $snapshot->treatment = 'zero_rated';
        $snapshot->status = TaxStatusEnum::CALCULATED->value;
        $snapshot->provider = 'none';
        $snapshot->input_hash = hash('sha256', 'free-' . uniqid());
        $snapshot->tax_amount = 0.00;
        $snapshot->taxable_amount = 0.00;
        $snapshot->remaining_reversible_amount = 0.00;
        $snapshot->remaining_reversible_tax = 0.00;
        $snapshot->gateway_attempt_key = hash('sha256', uniqid());
        $snapshot->gateway_attempt_status = GatewayAttemptStatusEnum::PENDING->value;
        $snapshot->souk_tax_engine_enabled = true;
        $snapshot->checkout_payload = [
            'totals' => ['tax' => 0.00, 'gateway_due' => 0.00],
        ];
        $snapshot->saveOrFail();

        $this->assertSame('none', $snapshot->provider);
        $this->assertSame(0.00, (float) $snapshot->tax_amount);

        $resolver = new TaxProviderResolver($this->kanvasApp);
        $provider = $resolver->resolveFromSnapshot($snapshot);

        $this->assertInstanceOf(NullTaxProvider::class, $provider);
    }
}
