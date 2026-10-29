<?php

declare(strict_types=1);

namespace Tests\Souk\Taxes;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\SalesReceipts\Models\SalesReceipt;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use Kanvas\Souk\Taxes\Activities\RecordOrderInScribeActivity;
use Kanvas\Souk\Taxes\Enums\ConfigurationEnum;
use Kanvas\Souk\Taxes\Models\OrderTaxComponent;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Mockery;
use Mockery\MockInterface;
use Tests\Scribe\ScribeTestCase;

final class RecordOrderInScribeActivityTest extends ScribeTestCase
{
    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'accounting', 'intelligence', 'crm', 'commerce'];

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testSkipsWhenSettingOff(): void
    {
        $app = app(Apps::class);

        /** @var MockInterface $company */
        $company = Mockery::mock(Companies::class)->makePartial();
        $company->shouldReceive('get')
            ->with(ConfigurationEnum::SCRIBE_SYNC_SOUK_ORDERS->value)
            ->andReturn(false);

        /** @var MockInterface $order */
        $order = Mockery::mock(Order::class)->makePartial();
        $order->id = 99999;
        $order->shouldReceive('getAttribute')
            ->with('company')
            ->andReturn($company);

        $activity = new RecordOrderInScribeActivity();
        $result = $activity->execute($order, $app, []);

        $this->assertSame('scribe sync disabled', $result['message']);
        $this->assertSame(99999, $result['order_id']);
    }

    public function testLineTaxComesFromTheOrderTaxSnapshot(): void
    {
        $app = app(Apps::class);
        $company = $this->company;

        $company->set(ConfigurationEnum::SCRIBE_SYNC_SOUK_ORDERS->value, '1');

        $order = $this->buildOrderWithItem(subtotal: 100.0, lineTax: 18.0, quantity: 1);

        $result = new RecordOrderInScribeActivity()->execute($order, $app, []);

        $this->assertSame('receipt recorded', $result['message']);
        $this->assertSame($order->uuid, $result['external_id']);

        $receipt = $this->receiptFor($order);

        $this->assertCount(1, $receipt->lines);

        $line = $receipt->lines->first();
        $this->assertEqualsWithDelta(100.0, (float) $line->unit_price_native, 0.01);
        $this->assertEqualsWithDelta(18.0, (float) $line->tax_amount_native, 0.01);
    }

    public function testShippingTaxGetsItsOwnLineSoTheReceiptMatchesTheSnapshot(): void
    {
        $app = app(Apps::class);
        $company = $this->company;

        $company->set(ConfigurationEnum::SCRIBE_SYNC_SOUK_ORDERS->value, '1');

        $order = $this->buildOrderWithItem(
            subtotal: 100.0,
            lineTax: 18.0,
            quantity: 1,
            shipping: 10.0,
            shippingTax: 1.8,
        );

        new RecordOrderInScribeActivity()->execute($order, $app, []);

        $receipt = $this->receiptFor($order);

        $this->assertCount(2, $receipt->lines);
        $this->assertEqualsWithDelta(19.8, (float) $receipt->lines->sum('tax_amount_native'), 0.01);
    }

    public function testIdempotentWhenReceiptAlreadyExists(): void
    {
        $app = app(Apps::class);
        $company = $this->company;

        $company->set(ConfigurationEnum::SCRIBE_SYNC_SOUK_ORDERS->value, '1');

        $order = $this->buildOrderWithItem(subtotal: 50.0, lineTax: 5.0, quantity: 1);

        $activity = new RecordOrderInScribeActivity();

        $first = $activity->execute($order, $app, []);
        $this->assertSame('receipt recorded', $first['message']);

        $second = $activity->execute($order, $app, []);
        $this->assertSame('receipt already exists', $second['message']);
        $this->assertSame($first['receipt_id'], $second['receipt_id']);

        $this->assertSame(
            1,
            SalesReceipt::query()
                ->where('apps_id', $app->getId())
                ->where('external_id', $order->uuid)
                ->count()
        );
    }

    public function testImportedOrderPassesThroughWithSingleTaxLine(): void
    {
        $app = app(Apps::class);
        $company = $this->company;

        $company->set(ConfigurationEnum::SCRIBE_SYNC_SOUK_ORDERS->value, '1');

        $order = $this->buildImportedOrder(subtotal: 200.0, taxAmount: 36.0);

        $activity = new RecordOrderInScribeActivity();
        $result = $activity->execute($order, $app, []);

        $this->assertSame('receipt recorded', $result['message']);

        $receipt = SalesReceipt::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('external_id', $order->uuid)
            ->with('lines')
            ->firstOrFail();

        // Imported orders produce exactly one line that carries the full subtotal + tax
        $this->assertCount(1, $receipt->lines);

        $line = $receipt->lines->first();
        $this->assertEqualsWithDelta(200.0, (float) $line->unit_price_native, 0.01);
        $this->assertEqualsWithDelta(36.0, (float) $line->tax_amount_native, 0.01);
    }

    private function buildOrderWithItem(
        float $subtotal,
        float $lineTax,
        float $quantity,
        float $shipping = 0.0,
        float $shippingTax = 0.0
    ): Order {
        $order = new Order();
        $order->apps_id = $this->kanvasApp->getId();
        $order->companies_id = $this->company->getId();
        $order->users_id = static::$cachedUser->getId();
        $order->people_id = 0;
        $order->region_id = 1;
        $order->order_number = random_int(10000, 99999);
        $order->status = 'draft';
        $order->fulfillment_status = 'pending';
        $order->currency = 'USD';
        $order->subtotal_amount = $subtotal;
        $order->shipping_price_net_amount = $shipping;
        $order->tax_amount = $lineTax + $shippingTax;
        $order->tax_source = 'souk';
        $order->total_gross_amount = $subtotal + $shipping + $lineTax + $shippingTax;
        $order->total_net_amount = $subtotal;
        $order->save();

        $item = new OrderItem();
        $item->apps_id = $this->kanvasApp->getId();
        $item->order_id = $order->id;
        $item->product_name = 'Test Product';
        $item->product_sku = 'SKU-001';
        $item->variant_name = 'Test Product';
        $item->quantity = $quantity;
        $item->unit_price_net_amount = $subtotal;
        $item->unit_price_gross_amount = $subtotal;
        $item->variant_id = 0;
        $item->is_public = true;
        $item->save();

        $snapshot = new OrderTaxSnapshot();
        $snapshot->apps_id = $this->kanvasApp->getId();
        $snapshot->order_id = $order->id;
        $snapshot->kind = 'calculation';
        $snapshot->treatment = 'taxed';
        $snapshot->status = 'calculated';
        $snapshot->provider = 'manual';
        $snapshot->input_hash = hash('sha256', (string) $order->id);
        $snapshot->tax_amount = $lineTax + $shippingTax;
        $snapshot->taxable_amount = $subtotal + $shipping;
        $snapshot->saveOrFail();

        $this->addComponent($snapshot, $item->uuid, $lineTax);

        if ($shippingTax > 0) {
            $this->addComponent($snapshot, 'shipping', $shippingTax);
        }

        return $order->refresh();
    }

    private function addComponent(OrderTaxSnapshot $snapshot, string $lineId, float $tax): void
    {
        $component = new OrderTaxComponent();
        $component->snapshot_id = $snapshot->id;
        $component->line_id = $lineId;
        $component->tax_type = 'VAT';
        $component->jurisdiction = 'DO';
        $component->rate = 0.18;
        $component->tax_amount = $tax;
        $component->taxability_reason = 'taxable';
        $component->saveOrFail();
    }

    private function receiptFor(Order $order): SalesReceipt
    {
        return SalesReceipt::query()
            ->where('external_id', $order->uuid)
            ->with('lines')
            ->firstOrFail();
    }

    private function buildImportedOrder(float $subtotal, float $taxAmount): Order
    {
        $order = new Order();
        $order->apps_id = $this->kanvasApp->getId();
        $order->companies_id = $this->company->getId();
        $order->users_id = static::$cachedUser->getId();
        $order->region_id = 1;
        $order->order_number = random_int(10000, 99999);
        $order->status = 'draft';
        $order->fulfillment_status = 'pending';
        $order->currency = 'USD';
        $order->people_id = 0;
        $order->tax_source = 'imported';
        $order->subtotal_amount = $subtotal;
        $order->tax_amount = $taxAmount;
        $order->total_gross_amount = $subtotal + $taxAmount;
        $order->total_net_amount = $subtotal;
        $order->save();

        return $order->refresh();
    }
}
