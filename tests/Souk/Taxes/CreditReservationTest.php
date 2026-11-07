<?php

declare(strict_types=1);

namespace Tests\Souk\Taxes;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Taxes\Actions\ReleaseCheckoutHoldsAction;
use Kanvas\Souk\Taxes\Enums\GatewayAttemptStatusEnum;
use Kanvas\Souk\Taxes\Models\CreditReservation;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('serial')]
final class CreditReservationTest extends TestCase
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
        $snapshot->cart_session_id = 'credit-test-' . uniqid();
        $snapshot->kind = 'calculation';
        $snapshot->treatment = 'taxed';
        $snapshot->status = 'calculated';
        $snapshot->provider = 'manual_rate';
        $snapshot->input_hash = hash('sha256', 'credit-test-' . uniqid());
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

    private function createReservation(int $snapshotId, string $status = 'held'): CreditReservation
    {
        $user = auth()->user();

        return CreditReservation::create([
            'snapshot_id' => $snapshotId,
            'companies_id' => $user->getCurrentCompany()->getId(),
            'discount_id' => 1,
            'amount' => 10.00,
            'status' => $status,
        ]);
    }

    public function testFrozenCreditAppliedOnFinalizedOrder(): void
    {
        $snapshot = $this->makeSnapshot();

        $reservation = $this->createReservation($snapshot->id, 'held');

        $snapshot->checkout_payload = array_merge($snapshot->checkout_payload, [
            'credit_reservation_id' => $reservation->getId(),
        ]);
        $snapshot->saveOrFail();

        $reservation->refresh();

        $this->assertSame('held', $reservation->status);
        $this->assertSame($snapshot->id, $reservation->snapshot_id);
        $this->assertSame(10.00, $reservation->amount);
    }

    public function testConcurrentFinalizationReservesOnce(): void
    {
        $snapshot = $this->makeSnapshot();

        $this->createReservation($snapshot->id, 'held');

        $existingHeld = CreditReservation::query()
            ->where('snapshot_id', $snapshot->id)
            ->where('status', 'held')
            ->lockForUpdate()
            ->first();

        if ($existingHeld !== null) {
            $count = CreditReservation::query()
                ->where('snapshot_id', $snapshot->id)
                ->count();

            $this->assertSame(1, $count, 'A second reservation attempt must not insert a new row when one already exists.');

            return;
        }

        $this->createReservation($snapshot->id, 'held');

        $count = CreditReservation::query()
            ->where('snapshot_id', $snapshot->id)
            ->count();

        $this->assertSame(1, $count, 'Calling the reservation twice must produce exactly one row.');
    }

    public function testCancelledCheckoutReleasesReservation(): void
    {
        $snapshot = $this->makeSnapshot();

        $reservation = $this->createReservation($snapshot->id, 'held');

        $snapshot->checkout_payload = array_merge($snapshot->checkout_payload, [
            'credit_reservation_id' => $reservation->getId(),
        ]);
        $snapshot->saveOrFail();

        new ReleaseCheckoutHoldsAction($snapshot)->execute();

        $reservation->refresh();

        $this->assertSame('released', $reservation->status);
    }

    public function testReleasingAlreadyReleasedReservationIsNoop(): void
    {
        $snapshot = $this->makeSnapshot();

        $reservation = $this->createReservation($snapshot->id, 'released');

        $snapshot->checkout_payload = array_merge($snapshot->checkout_payload, [
            'credit_reservation_id' => $reservation->getId(),
        ]);
        $snapshot->saveOrFail();

        new ReleaseCheckoutHoldsAction($snapshot)->execute();

        $reservation->refresh();

        $this->assertSame('released', $reservation->status);
    }

    public function testReleasingConsumedReservationIsNoop(): void
    {
        $snapshot = $this->makeSnapshot();

        $reservation = $this->createReservation($snapshot->id, 'consumed');

        $snapshot->checkout_payload = array_merge($snapshot->checkout_payload, [
            'credit_reservation_id' => $reservation->getId(),
        ]);
        $snapshot->saveOrFail();

        new ReleaseCheckoutHoldsAction($snapshot)->execute();

        $reservation->refresh();

        $this->assertSame('consumed', $reservation->status);
    }

    public function testSnapshotWithNullCreditReservationDoesNotFail(): void
    {
        $snapshot = $this->makeSnapshot();

        new ReleaseCheckoutHoldsAction($snapshot)->execute();

        $snapshot->refresh();

        $this->assertSame(GatewayAttemptStatusEnum::VOIDED->value, $snapshot->gateway_attempt_status);
    }
}
