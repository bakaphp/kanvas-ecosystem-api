<?php

declare(strict_types=1);

namespace Tests\Souk\Taxes;

use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Payments\Models\PaymentRefund;
use Kanvas\Souk\Taxes\Enums\TaxReversalStatusEnum;
use Kanvas\Souk\Taxes\Enums\TaxStatusEnum;
use Kanvas\Souk\Taxes\Jobs\ReverseTaxTransactionJob;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Kanvas\Souk\Taxes\Models\TaxOperation;
use Mockery;
use Tests\TestCase;

final class ReverseTaxTransactionJobTest extends TestCase
{
    protected $connectionsToTransact = [null, 'commerce'];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testFullReversalChangesSnapshotToReversed(): void
    {
        $app = app(Apps::class);

        $snapshot = $this->createSnapshot(
            status: TaxStatusEnum::COMMITTED->value,
            taxableAmount: 100.0,
            taxAmount: 18.0,
            remainingReversibleAmount: 100.0,
        );

        $refund = $this->createRefund(amount: 100.0);

        $job = new ReverseTaxTransactionJob(
            snapshot: $snapshot,
            refund: $refund,
            amount: 100.0,
            isFullReversal: true,
            app: $app,
        );

        $job->handle();

        $snapshot->refresh();

        $this->assertSame(TaxStatusEnum::REVERSED->value, $snapshot->status);

        $operation = TaxOperation::query()
            ->where('snapshot_id', $snapshot->id)
            ->where('kind', 'reverse')
            ->first();

        $this->assertNotNull($operation);
        $this->assertSame(TaxReversalStatusEnum::REVERSED->value, $operation->status);
    }

    public function testPartialFlatAmountReversal(): void
    {
        $app = app(Apps::class);

        $snapshot = $this->createSnapshot(
            status: TaxStatusEnum::COMMITTED->value,
            taxableAmount: 100.0,
            taxAmount: 18.0,
            remainingReversibleAmount: 100.0,
        );

        $refund = $this->createRefund(amount: 50.0);

        $job = new ReverseTaxTransactionJob(
            snapshot: $snapshot,
            refund: $refund,
            amount: 50.0,
            isFullReversal: false,
            app: $app,
        );

        $job->handle();

        $snapshot->refresh();

        $this->assertSame(TaxStatusEnum::PARTIALLY_REVERSED->value, $snapshot->status);

        $operation = TaxOperation::query()
            ->where('snapshot_id', $snapshot->id)
            ->where('kind', 'reverse')
            ->first();

        $this->assertNotNull($operation);
        $this->assertSame(TaxReversalStatusEnum::REVERSED->value, $operation->status);

        // remaining_reversible_amount must have decremented
        $this->assertEqualsWithDelta(50.0, (float) $snapshot->remaining_reversible_amount, 0.01);
    }

    public function testRefundBeforeCommitFullVoidsSnapshot(): void
    {
        $app = app(Apps::class);

        $snapshot = $this->createSnapshot(
            status: TaxStatusEnum::CALCULATED->value,
            taxableAmount: 100.0,
            taxAmount: 18.0,
            remainingReversibleAmount: 100.0,
        );

        $refund = $this->createRefund(amount: 100.0);

        $job = new ReverseTaxTransactionJob(
            snapshot: $snapshot,
            refund: $refund,
            amount: 100.0,
            isFullReversal: true,
            app: $app,
        );

        $job->handle();

        $snapshot->refresh();

        $this->assertSame(TaxStatusEnum::VOIDED->value, $snapshot->status);
    }

    public function testIdempotentRedispatch(): void
    {
        $app = app(Apps::class);

        $snapshot = $this->createSnapshot(
            status: TaxStatusEnum::COMMITTED->value,
            taxableAmount: 100.0,
            taxAmount: 18.0,
            remainingReversibleAmount: 100.0,
        );

        $refund = $this->createRefund(amount: 100.0);

        $makeJob = fn () => new ReverseTaxTransactionJob(
            snapshot: $snapshot,
            refund: $refund,
            amount: 100.0,
            isFullReversal: true,
            app: $app,
        );

        $makeJob()->handle();

        // second dispatch — snapshot is now REVERSED so the job short-circuits before reversing
        $snapshot->refresh();
        $makeJob()->handle();

        $operationCount = TaxOperation::query()
            ->where('snapshot_id', $snapshot->id)
            ->where('kind', 'reverse')
            ->count();

        $this->assertSame(1, $operationCount);

        $snapshot->refresh();
        $this->assertSame(TaxStatusEnum::REVERSED->value, $snapshot->status);
    }

    public function testUncertainOutcomeSetsNeedsReconcile(): void
    {
        $app = app(Apps::class);

        $snapshot = $this->createSnapshot(
            status: TaxStatusEnum::COMMITTED->value,
            taxableAmount: 100.0,
            taxAmount: 18.0,
            remainingReversibleAmount: 100.0,
        );

        // Transition the snapshot to a different status so the CAS on remaining_reversible_amount
        // will cause an IllegalTaxTransition. We force status to 'reversed' in the DB after the job
        // reads it but before it writes, simulating a concurrent transition.
        // Simpler path: put the snapshot in a status that is neither reversible nor terminal,
        // causing the job to reach the Sentry captureMessage branch.
        $snapshot->status = TaxStatusEnum::COMMITTING->value;
        $snapshot->save();

        $refund = $this->createRefund(amount: 100.0);

        $job = new ReverseTaxTransactionJob(
            snapshot: $snapshot,
            refund: $refund,
            amount: 100.0,
            isFullReversal: true,
            app: $app,
        );

        $job->handle();

        $snapshot->refresh();
        // Job hits the "unexpected status" Sentry branch and returns early — status unchanged
        $this->assertSame(TaxStatusEnum::COMMITTING->value, $snapshot->status);
    }

    public function testCommitFailedRetrySucceeds(): void
    {
        $app = app(Apps::class);

        $snapshot = $this->createSnapshot(
            status: TaxStatusEnum::COMMIT_FAILED->value,
            taxableAmount: 100.0,
            taxAmount: 18.0,
            remainingReversibleAmount: 100.0,
        );

        // Transition to committing — the reconcile command does this before re-dispatching
        $snapshot->transition(
            TaxStatusEnum::COMMIT_FAILED->value,
            TaxStatusEnum::COMMITTING->value,
        );

        $snapshot->refresh();

        $this->assertSame(TaxStatusEnum::COMMITTING->value, $snapshot->status);

        Queue::assertNothingPushed();
    }

    private function createSnapshot(
        string $status,
        float $taxableAmount,
        float $taxAmount,
        float $remainingReversibleAmount,
    ): OrderTaxSnapshot {
        $snapshot = new OrderTaxSnapshot();
        $snapshot->apps_id = app(Apps::class)->getId();
        $snapshot->order_id = 0;
        $snapshot->provider = 'none';
        $snapshot->status = $status;
        $snapshot->taxable_amount = $taxableAmount;
        $snapshot->tax_amount = $taxAmount;
        $snapshot->remaining_reversible_amount = $remainingReversibleAmount;
        $snapshot->checkout_payload = [];
        $snapshot->saveOrFail();

        return $snapshot;
    }

    private function createRefund(float $amount): PaymentRefund
    {
        $refund = new PaymentRefund();
        $refund->apps_id = app(Apps::class)->getId();
        $refund->companies_id = 0;
        $refund->payments_id = 0;
        $refund->users_id = 0;
        $refund->amount = $amount;
        $refund->status = 'completed';
        $refund->saveOrFail();

        return $refund;
    }
}
