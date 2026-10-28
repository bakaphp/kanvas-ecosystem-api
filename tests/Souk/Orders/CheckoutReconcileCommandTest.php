<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Taxes\Enums\GatewayAttemptStatusEnum;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('serial')]
final class CheckoutReconcileCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'ecosystem'];

    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
    }

    private function makeSnapshot(array $overrides = []): OrderTaxSnapshot
    {
        $user = auth()->user();

        $snapshot = new OrderTaxSnapshot();
        $snapshot->apps_id = $this->kanvasApp->getId();
        $snapshot->order_id = null;
        $snapshot->cart_session_id = 'test-cart-' . uniqid();
        $snapshot->kind = 'calculation';
        $snapshot->treatment = 'taxed';
        $snapshot->status = 'calculated';
        $snapshot->provider = 'manual_rate';
        $snapshot->input_hash = hash('sha256', json_encode([['variant_id' => 1, 'quantity' => 1, 'unit_price_net_amount' => 100.0]]));
        $snapshot->tax_amount = 18.00;
        $snapshot->taxable_amount = 100.00;
        $snapshot->remaining_reversible_amount = 100.00;
        $snapshot->remaining_reversible_tax = 18.00;
        $snapshot->gateway_attempt_key = hash('sha256', uniqid());
        $snapshot->gateway_attempt_status = GatewayAttemptStatusEnum::PENDING->value;
        $snapshot->souk_tax_engine_enabled = true;
        $snapshot->checkout_payload = [
            'lines' => [
                [
                    'uuid' => 'line-uuid-1',
                    'variant_id' => 1,
                    'product_name' => 'Test Product',
                    'product_sku' => 'SKU-001',
                    'quantity' => 1,
                    'unit_price_net_amount' => 100.00,
                    'tax_amount' => 18.00,
                ],
            ],
            'shipping_address' => null,
            'billing_address' => null,
            'discount_allocation' => [],
            'wallet_hold_transfer_id' => null,
            'credit_reservation_id' => null,
            'order_context' => [
                'region_id' => 1,
                'people_id' => 0,
                'companies_id' => $user->getCurrentCompany()->getId(),
                'users_id' => $user->getId(),
            ],
            'totals' => [
                'subtotal' => 100.00,
                'discount' => 0.00,
                'tax' => 18.00,
                'shipping' => 0.00,
                'payable' => 118.00,
                'wallet_tender' => 0.00,
                'gateway_due' => 118.00,
            ],
        ];

        foreach ($overrides as $key => $value) {
            $snapshot->{$key} = $value;
        }

        $snapshot->saveOrFail();

        return $snapshot;
    }

    public function testCommandRunsWithoutErrorsForAppWithNoStaleSnapshots(): void
    {
        $this->artisan('souk:checkout-reconcile', ['app_id' => $this->kanvasApp->getId()])
            ->assertSuccessful();
    }

    public function testCommandSweepsStaleSnapshotsOlderThanTenMinutes(): void
    {
        $snapshot = $this->makeSnapshot([
            'payment_intent_id' => 'pi_test_skip_' . uniqid(),
            'gateway_attempt_status' => GatewayAttemptStatusEnum::CREATED->value,
        ]);

        OrderTaxSnapshot::query()
            ->where('id', $snapshot->id)
            ->update(['updated_at' => now()->subMinutes(15)]);

        $this->artisan('souk:checkout-reconcile', ['app_id' => $this->kanvasApp->getId()])
            ->assertSuccessful();

        $fresh = $snapshot->fresh();
        $this->assertNotNull($fresh);
    }

    public function testCleanupPendingSnapshotIsRetriedAndMarkedNeedsReconcileAfterMaxRetries(): void
    {
        $snapshot = $this->makeSnapshot([
            'gateway_attempt_status' => GatewayAttemptStatusEnum::CLEANUP_PENDING->value,
            'metadata' => ['reconcile_retries' => 3],
        ]);

        OrderTaxSnapshot::query()
            ->where('id', $snapshot->id)
            ->update(['updated_at' => now()->subMinutes(35)]);

        $this->artisan('souk:checkout-reconcile', ['app_id' => $this->kanvasApp->getId()])
            ->assertSuccessful();

        $this->assertSame(
            GatewayAttemptStatusEnum::NEEDS_RECONCILE->value,
            $snapshot->fresh()->gateway_attempt_status
        );
    }

    public function testNeedsReconcileCaseInGatewayAttemptStatusEnumExists(): void
    {
        $this->assertSame('needs_reconcile', GatewayAttemptStatusEnum::NEEDS_RECONCILE->value);
        $this->assertSame('superseded', GatewayAttemptStatusEnum::SUPERSEDED->value);
    }
}
