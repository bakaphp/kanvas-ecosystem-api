<?php

declare(strict_types=1);

namespace Tests\Souk\Taxes;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Taxes\Enums\TaxStatusEnum;
use Kanvas\Souk\Taxes\Jobs\CommitTaxTransactionJob;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Tests\TestCase;

final class PayFromWalletActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce'];

    protected Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
    }

    private function createOrder(array $attributes = []): Order
    {
        $user = auth()->user();

        return Order::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->create($attributes);
    }

    private function createCalculatedSnapshot(Order $order): OrderTaxSnapshot
    {
        $snapshot = new OrderTaxSnapshot();
        $snapshot->apps_id = $this->kanvasApp->getId();
        $snapshot->order_id = $order->id;
        $snapshot->kind = 'calculation';
        $snapshot->treatment = 'taxed';
        $snapshot->status = TaxStatusEnum::CALCULATED->value;
        $snapshot->provider = 'manual';
        $snapshot->input_hash = hash('sha256', (string) $order->id . 'wallet-pay-test');
        $snapshot->tax_amount = '18.00';
        $snapshot->taxable_amount = '100.00';
        $snapshot->remaining_reversible_amount = '100.00';
        $snapshot->remaining_reversible_tax = '18.00';
        $snapshot->souk_tax_engine_enabled = true;
        $snapshot->saveOrFail();

        return $snapshot;
    }

    public function testTotalDueAmountIncludesTaxForV2Orders(): void
    {
        $order = $this->createOrder([
            'totals_version' => 2,
            'tax_source' => 'souk',
            'tax_amount' => 18.00,
            'subtotal_amount' => 100.00,
            'total_payable_amount' => 118.00,
            'total_net_amount' => 118.00,
            'total_gross_amount' => 118.00,
        ]);

        $this->assertSame(18.0, $order->getTotalTaxAmount());
        $this->assertSame(118.0, $order->getTotalDueAmount());
        $this->assertNotSame(100.0, $order->getTotalDueAmount(), 'getTotalDueAmount() must include tax for v2 orders.');
    }

    public function testMarkAsPaidSetsPaymentStatusToPaid(): void
    {
        Queue::fake();

        $user = auth()->user();
        $order = $this->createOrder();

        $order->markAsPaid($user);

        $this->assertSame(PaymentStatusEnum::PAID->value, $order->payment_status);
    }

    public function testMarkAsPaidDispatchesCommitJobWhenCalculatedSnapshotExists(): void
    {
        Queue::fake();

        $user = auth()->user();
        $order = $this->createOrder();
        $snapshot = $this->createCalculatedSnapshot($order);

        $order->markAsPaid($user);

        Queue::assertPushed(CommitTaxTransactionJob::class);
    }

    public function testMarkAsPaidTransitionsSnapshotToCommittingBeforeDispatch(): void
    {
        Queue::fake();

        $user = auth()->user();
        $order = $this->createOrder();
        $snapshot = $this->createCalculatedSnapshot($order);

        $order->markAsPaid($user);

        $snapshot->refresh();
        $this->assertSame(TaxStatusEnum::COMMITTING->value, $snapshot->status);
    }

    public function testMarkAsPaidDoesNotDispatchJobWhenNoSnapshotExists(): void
    {
        Queue::fake();

        $user = auth()->user();
        $order = $this->createOrder();

        $order->markAsPaid($user);

        Queue::assertNotPushed(CommitTaxTransactionJob::class);
    }

    public function testMarkAsPaidDoesNotDispatchJobWhenSnapshotIsAlreadyCommitted(): void
    {
        Queue::fake();

        $user = auth()->user();
        $order = $this->createOrder();

        $snapshot = new OrderTaxSnapshot();
        $snapshot->apps_id = $this->kanvasApp->getId();
        $snapshot->order_id = $order->id;
        $snapshot->kind = 'calculation';
        $snapshot->treatment = 'taxed';
        $snapshot->status = TaxStatusEnum::COMMITTED->value;
        $snapshot->provider = 'manual';
        $snapshot->input_hash = hash('sha256', (string) $order->id . 'already-committed');
        $snapshot->tax_amount = '18.00';
        $snapshot->taxable_amount = '100.00';
        $snapshot->remaining_reversible_amount = '100.00';
        $snapshot->remaining_reversible_tax = '18.00';
        $snapshot->souk_tax_engine_enabled = true;
        $snapshot->saveOrFail();

        $order->markAsPaid($user);

        Queue::assertNotPushed(CommitTaxTransactionJob::class);
    }
}
