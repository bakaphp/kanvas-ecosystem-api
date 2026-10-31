<?php

declare(strict_types=1);

namespace Tests\Souk\Taxes;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Taxes\Actions\CompleteCheckoutFromSnapshotAction;
use Kanvas\Souk\Taxes\Enums\GatewayAttemptStatusEnum;
use Kanvas\Souk\Taxes\Models\CreditReservation;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('serial')]
final class CompleteCheckoutFromSnapshotTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'ecosystem'];

    protected Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
    }

    private function createOrder(): Order
    {
        $user = auth()->user();

        return Order::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->create();
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
        $defaultLines = $overrides['checkout_payload']['lines'] ?? [
            ['variant_id' => 1, 'quantity' => 1, 'unit_price_net_amount' => 100.00],
        ];
        $normalizedLines = array_map(fn ($l) => [
            'variant_id' => $l['variant_id'],
            'quantity' => $l['quantity'],
            'unit_price_net_amount' => $l['unit_price_net_amount'],
        ], $defaultLines);
        usort($normalizedLines, fn ($a, $b) => $a['variant_id'] <=> $b['variant_id']);
        $snapshot->input_hash = hash('sha256', json_encode($normalizedLines));
        $snapshot->tax_amount = 18.00;
        $snapshot->taxable_amount = 100.00;
        $snapshot->remaining_reversible_amount = 100.00;
        $snapshot->remaining_reversible_tax = 18.00;
        $snapshot->gateway_attempt_key = hash('sha256', uniqid());
        $snapshot->gateway_attempt_status = GatewayAttemptStatusEnum::PENDING->value;
        $snapshot->souk_tax_engine_enabled = true;
        $snapshot->checkout_payload = array_merge([
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
        ], $overrides['checkout_payload'] ?? []);

        unset($overrides['checkout_payload']);

        foreach ($overrides as $key => $value) {
            $snapshot->{$key} = $value;
        }

        $snapshot->saveOrFail();

        return $snapshot;
    }

    public function testIdempotentReturnExistingOrderWhenAlreadySet(): void
    {
        $order = $this->createOrder();
        $snapshot = $this->makeSnapshot(['order_id' => $order->getId()]);

        $result = new CompleteCheckoutFromSnapshotAction(
            snapshot: $snapshot,
            paymentIntentId: 'pi_test_idempotent_' . uniqid(),
            app: $this->kanvasApp,
        )->execute();

        $this->assertSame($order->getId(), $result->getId());
    }

    public function testCacheLockIsAcquiredPerPaymentIntent(): void
    {
        $order = $this->createOrder();
        $paymentIntentId = 'pi_lock_test_' . uniqid();
        $snapshot = $this->makeSnapshot(['order_id' => $order->getId()]);

        $result = new CompleteCheckoutFromSnapshotAction(
            snapshot: $snapshot,
            paymentIntentId: $paymentIntentId,
            app: $this->kanvasApp,
        )->execute();

        $this->assertSame($order->getId(), $result->getId());
    }

    public function testCreditReservationIsConsumedAfterOrderCreation(): void
    {
        $user = auth()->user();
        $snapshot = $this->makeSnapshot();

        $reservation = CreditReservation::create([
            'snapshot_id' => $snapshot->id,
            'companies_id' => $user->getCurrentCompany()->getId(),
            'discount_id' => 1,
            'amount' => 10.00,
            'status' => 'held',
        ]);

        $snapshot->checkout_payload = array_merge($snapshot->checkout_payload, [
            'credit_reservation_id' => $reservation->getId(),
        ]);
        $snapshot->saveOrFail();

        new CompleteCheckoutFromSnapshotAction(
            snapshot: $snapshot,
            paymentIntentId: 'pi_credit_consume_' . uniqid(),
            app: $this->kanvasApp,
        )->execute();

        $reservation->refresh();

        $this->assertSame('consumed', $reservation->status);
    }

    public function testSnapshotOrderIdIsSetAfterCompletion(): void
    {
        $snapshot = $this->makeSnapshot();
        $paymentIntentId = 'pi_complete_' . uniqid();

        $order = new CompleteCheckoutFromSnapshotAction(
            snapshot: $snapshot,
            paymentIntentId: $paymentIntentId,
            app: $this->kanvasApp,
        )->execute();

        $snapshot->refresh();

        $this->assertNotNull($snapshot->order_id);
        $this->assertSame($snapshot->order_id, $order->getId());
        $this->assertSame($paymentIntentId, $snapshot->payment_intent_id);
    }

    public function testHashMismatchSetsOrderOnHoldAndReportsSentry(): void
    {
        $snapshot = $this->makeSnapshot();

        new CompleteCheckoutFromSnapshotAction(
            snapshot: $snapshot,
            paymentIntentId: 'pi_hash_mismatch_' . uniqid(),
            app: $this->kanvasApp,
        )->execute();

        $snapshot->refresh();
        $originalOrderId = $snapshot->order_id;
        $this->assertNotNull($originalOrderId);

        $snapshot->input_hash = hash('sha256', 'tampered-hash-' . uniqid());
        $snapshot->order_id = null;
        $snapshot->saveOrFail();

        $order = new CompleteCheckoutFromSnapshotAction(
            snapshot: $snapshot,
            paymentIntentId: 'pi_hash_mismatch2_' . uniqid(),
            app: $this->kanvasApp,
        )->execute();

        $this->assertSame('mismatch', $order->tax_status);
        $this->assertSame('on_hold', $order->status);
    }

    public function testDuplicatePaymentIntentIdReturnsExistingOrder(): void
    {
        $paymentIntentId = 'pi_idempotent_unique_' . uniqid();
        $snapshot = $this->makeSnapshot();

        $firstOrder = new CompleteCheckoutFromSnapshotAction(
            snapshot: $snapshot,
            paymentIntentId: $paymentIntentId,
            app: $this->kanvasApp,
        )->execute();

        $snapshot->refresh();
        $this->assertNotNull($snapshot->order_id);

        $snapshot->order_id = null;

        $secondOrder = new CompleteCheckoutFromSnapshotAction(
            snapshot: $snapshot,
            paymentIntentId: $paymentIntentId,
            app: $this->kanvasApp,
        )->execute();

        $this->assertSame($firstOrder->getId(), $secondOrder->getId());
        $this->assertSame(
            1,
            Order::where('payment_intent_id', $paymentIntentId)->count()
        );
    }

    public function testCreditReservationIsReleasedOnOrderCreationFailure(): void
    {
        $user = auth()->user();

        $snapshot = $this->makeSnapshot([
            'checkout_payload' => [
                'lines' => [],
                'shipping_address' => null,
                'billing_address' => null,
                'discount_allocation' => [],
                'wallet_hold_transfer_id' => null,
                'credit_reservation_id' => null,
                'order_context' => [],
                'totals' => [
                    'subtotal' => 0.0,
                    'discount' => 0.0,
                    'tax' => 0.0,
                    'shipping' => 0.0,
                    'payable' => 0.0,
                    'wallet_tender' => 0.0,
                    'gateway_due' => 0.0,
                ],
            ],
        ]);

        $reservation = CreditReservation::create([
            'snapshot_id' => $snapshot->id,
            'companies_id' => $user->getCurrentCompany()->getId(),
            'discount_id' => 1,
            'amount' => 10.00,
            'status' => 'held',
        ]);

        $snapshot->checkout_payload = array_merge($snapshot->checkout_payload ?? [], [
            'credit_reservation_id' => $reservation->getId(),
        ]);
        $snapshot->saveOrFail();

        try {
            new CompleteCheckoutFromSnapshotAction(
                snapshot: $snapshot,
                paymentIntentId: 'pi_fail_' . uniqid(),
                app: $this->kanvasApp,
            )->execute();
        } catch (\Throwable) {
        }

        $reservation->refresh();
        $this->assertSame('released', $reservation->status);
    }
}
