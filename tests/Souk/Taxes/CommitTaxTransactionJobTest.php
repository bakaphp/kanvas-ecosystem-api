<?php

declare(strict_types=1);

namespace Tests\Souk\Taxes;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Taxes\Enums\TaxStatusEnum;
use Kanvas\Souk\Taxes\Jobs\CommitTaxTransactionJob;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Tests\TestCase;

final class CommitTaxTransactionJobTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce'];

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

    private function createSnapshot(Order $order, string $status, string $provider = 'manual'): OrderTaxSnapshot
    {
        $snapshot = new OrderTaxSnapshot();
        $snapshot->apps_id = $this->kanvasApp->getId();
        $snapshot->order_id = $order->id;
        $snapshot->kind = 'calculation';
        $snapshot->treatment = 'taxed';
        $snapshot->status = $status;
        $snapshot->provider = $provider;
        $snapshot->input_hash = hash('sha256', (string) $order->id . $provider . $status);
        $snapshot->tax_amount = '18.00';
        $snapshot->taxable_amount = '100.00';
        $snapshot->remaining_reversible_amount = '100.00';
        $snapshot->remaining_reversible_tax = '18.00';
        $snapshot->souk_tax_engine_enabled = true;
        $snapshot->saveOrFail();

        return $snapshot;
    }

    public function testHasKanvasJobsTrait(): void
    {
        $order = $this->createOrder();
        $snapshot = $this->createSnapshot($order, TaxStatusEnum::COMMITTING->value);

        $job = new CommitTaxTransactionJob($snapshot, $this->kanvasApp);

        $this->assertTrue(method_exists($job, 'overwriteAppService'));
    }

    public function testJobTransitionsSnapshotToCommitted(): void
    {
        $order = $this->createOrder();
        $snapshot = $this->createSnapshot($order, TaxStatusEnum::COMMITTING->value);

        new CommitTaxTransactionJob($snapshot, $this->kanvasApp)->handle();

        $snapshot->refresh();
        $this->assertSame(TaxStatusEnum::COMMITTED->value, $snapshot->status);
    }

    public function testJobIsIdempotentWhenSnapshotAlreadyCommitted(): void
    {
        $order = $this->createOrder();
        $snapshot = $this->createSnapshot($order, TaxStatusEnum::COMMITTED->value);

        new CommitTaxTransactionJob($snapshot, $this->kanvasApp)->handle();

        $snapshot->refresh();
        $this->assertSame(TaxStatusEnum::COMMITTED->value, $snapshot->status);
    }

    public function testJobExitsCleanlyWhenSnapshotIsVoided(): void
    {
        $order = $this->createOrder();
        $snapshot = $this->createSnapshot($order, TaxStatusEnum::VOIDED->value);

        new CommitTaxTransactionJob($snapshot, $this->kanvasApp)->handle();

        $snapshot->refresh();
        $this->assertSame(TaxStatusEnum::VOIDED->value, $snapshot->status);
    }

    public function testJobIsIdempotentOnSecondHandleCallAfterCommit(): void
    {
        $order = $this->createOrder();
        $snapshot = $this->createSnapshot($order, TaxStatusEnum::COMMITTING->value);

        new CommitTaxTransactionJob($snapshot, $this->kanvasApp)->handle();
        $snapshot->refresh();
        $this->assertSame(TaxStatusEnum::COMMITTED->value, $snapshot->status);

        new CommitTaxTransactionJob($snapshot, $this->kanvasApp)->handle();
        $snapshot->refresh();
        $this->assertSame(TaxStatusEnum::COMMITTED->value, $snapshot->status);
    }

    public function testJobReportsItsQueueNameAsSoukTax(): void
    {
        $order = $this->createOrder();
        $snapshot = $this->createSnapshot($order, TaxStatusEnum::COMMITTING->value);

        $job = new CommitTaxTransactionJob($snapshot, $this->kanvasApp);

        $this->assertSame('souk-tax', $job->queue());
    }
}
