<?php

declare(strict_types=1);

namespace Tests\Souk\Taxes;

use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Payments\Models\PaymentRefund;
use Kanvas\Souk\Taxes\Enums\TaxReversalStatusEnum;
use Kanvas\Souk\Taxes\Enums\TaxStatusEnum;
use Kanvas\Souk\Taxes\Jobs\CommitTaxTransactionJob;
use Kanvas\Souk\Taxes\Jobs\ReverseTaxTransactionJob;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Kanvas\Souk\Taxes\Models\TaxOperation;
use Tests\TestCase;

final class TaxOperationsReconcileCommandTest extends TestCase
{
    protected $connectionsToTransact = [null, 'commerce'];

    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        Bus::fake();
    }

    public function testFailedCommitIsMovedToCommittingAndRedispatched(): void
    {
        $snapshot = $this->createSnapshot(TaxStatusEnum::COMMIT_FAILED->value);

        $this->artisan('souk:tax-operations-reconcile', ['app_id' => $this->kanvasApp->getId()])
            ->assertSuccessful();

        $this->assertSame(TaxStatusEnum::COMMITTING->value, $snapshot->refresh()->status);
        Bus::assertDispatched(
            CommitTaxTransactionJob::class,
            fn (CommitTaxTransactionJob $job) => $job->snapshot->is($snapshot)
        );
    }

    public function testFailedReversalIsResetToPendingAndRedispatchedAsFullWhenItCoversTheRemainder(): void
    {
        $snapshot = $this->createSnapshot(TaxStatusEnum::COMMITTED->value, remainingReversibleAmount: 100.0);
        $refund = $this->createRefund(100.0);
        $operation = $this->createReversalOperation($snapshot, ['refund_uuid' => $refund->uuid]);

        $this->artisan('souk:tax-operations-reconcile', ['app_id' => $this->kanvasApp->getId()])
            ->assertSuccessful();

        $this->assertSame(TaxReversalStatusEnum::PENDING->value, $operation->refresh()->status);
        Bus::assertDispatched(
            ReverseTaxTransactionJob::class,
            fn (ReverseTaxTransactionJob $job) => $job->refund->is($refund)
                && $job->amount === 100.0
                && $job->isFullReversal === true
        );
    }

    public function testFailedPartialReversalIsRedispatchedAsPartial(): void
    {
        $snapshot = $this->createSnapshot(TaxStatusEnum::COMMITTED->value, remainingReversibleAmount: 100.0);
        $refund = $this->createRefund(40.0);
        $this->createReversalOperation($snapshot, ['refund_uuid' => $refund->uuid]);

        $this->artisan('souk:tax-operations-reconcile', ['app_id' => $this->kanvasApp->getId()])
            ->assertSuccessful();

        Bus::assertDispatched(
            ReverseTaxTransactionJob::class,
            fn (ReverseTaxTransactionJob $job) => $job->amount === 40.0 && $job->isFullReversal === false
        );
    }

    public function testFailedReversalWithoutItsRefundIsLeftForManualReview(): void
    {
        $snapshot = $this->createSnapshot(TaxStatusEnum::COMMITTED->value);
        $operation = $this->createReversalOperation($snapshot, ['refund_uuid' => 'missing-' . uniqid()]);

        $this->artisan('souk:tax-operations-reconcile', ['app_id' => $this->kanvasApp->getId()])
            ->assertSuccessful();

        $this->assertSame(TaxReversalStatusEnum::REVERSAL_FAILED->value, $operation->refresh()->status);
        Bus::assertNotDispatched(ReverseTaxTransactionJob::class);
    }

    public function testRowsOfAnotherAppAreNotTouched(): void
    {
        $snapshot = $this->createSnapshot(TaxStatusEnum::COMMIT_FAILED->value);
        $snapshot->apps_id = $this->kanvasApp->getId() + 100000;
        $snapshot->saveOrFail();

        $this->artisan('souk:tax-operations-reconcile', ['app_id' => $this->kanvasApp->getId()])
            ->assertSuccessful();

        $this->assertSame(TaxStatusEnum::COMMIT_FAILED->value, $snapshot->refresh()->status);
        Bus::assertNotDispatched(CommitTaxTransactionJob::class);
    }

    public function testNeedsReconcileRowsAreReportedWithoutBeingChanged(): void
    {
        $snapshot = $this->createSnapshot(TaxStatusEnum::NEEDS_RECONCILE->value);

        $this->artisan('souk:tax-operations-reconcile', ['app_id' => $this->kanvasApp->getId()])
            ->expectsOutputToContain("Tax snapshot {$snapshot->uuid}")
            ->assertSuccessful();

        $this->assertSame(TaxStatusEnum::NEEDS_RECONCILE->value, $snapshot->refresh()->status);
        Bus::assertNothingDispatched();
    }

    private function createSnapshot(string $status, float $remainingReversibleAmount = 100.0): OrderTaxSnapshot
    {
        $snapshot = new OrderTaxSnapshot();
        $snapshot->apps_id = $this->kanvasApp->getId();
        $snapshot->order_id = 0;
        $snapshot->provider = 'none';
        $snapshot->status = $status;
        $snapshot->taxable_amount = 100.0;
        $snapshot->tax_amount = 18.0;
        $snapshot->remaining_reversible_amount = $remainingReversibleAmount;
        $snapshot->checkout_payload = [];
        $snapshot->saveOrFail();

        return $snapshot;
    }

    private function createRefund(float $amount): PaymentRefund
    {
        $refund = new PaymentRefund();
        $refund->apps_id = $this->kanvasApp->getId();
        $refund->companies_id = 0;
        $refund->payments_id = 0;
        $refund->users_id = 0;
        $refund->amount = $amount;
        $refund->status = 'completed';
        $refund->saveOrFail();

        return $refund;
    }

    private function createReversalOperation(OrderTaxSnapshot $snapshot, array $providerResponse): TaxOperation
    {
        $operation = new TaxOperation();
        $operation->snapshot_id = $snapshot->id;
        $operation->kind = 'reverse';
        $operation->idempotency_key = 'reverse-' . uniqid();
        $operation->status = TaxReversalStatusEnum::REVERSAL_FAILED->value;
        $operation->provider = $snapshot->provider;
        $operation->provider_response = $providerResponse;
        $operation->saveOrFail();

        return $operation;
    }
}
