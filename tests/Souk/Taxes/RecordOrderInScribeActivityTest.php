<?php

declare(strict_types=1);

namespace Tests\Souk\Taxes;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\SalesReceipts\Models\SalesReceipt;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use Kanvas\Souk\Taxes\Activities\RecordOrderInScribeActivity;
use Kanvas\Souk\Taxes\Enums\ConfigurationEnum;
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

    public function testInclusiveTaxMappingOnReceipt(): void
    {
        $app = app(Apps::class);
        $company = $this->company;

        $company->set(ConfigurationEnum::SCRIBE_SYNC_SOUK_ORDERS->value, '1');

        $order = $this->buildOrderWithItem(
            subtotal: 100.0,
            unitTaxAmount: 18.0,
            quantity: 1,
            taxCalculationMode: 'inclusive',
        );

        $activity = new RecordOrderInScribeActivity();
        $result = $activity->execute($order, $app, []);

        $this->assertSame('receipt recorded', $result['message']);
        $this->assertSame($order->uuid, $result['external_id']);

        $receipt = SalesReceipt::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('external_id', $order->uuid)
            ->with('lines')
            ->firstOrFail();

        $this->assertCount(1, $receipt->lines);

        $line = $receipt->lines->first();

        // Inclusive: unit_price_net = unit_price_net_amount - (unit_tax_amount / qty) = 100 - 18 = 82
        $this->assertEqualsWithDelta(82.0, (float) $line->unit_price_native, 0.01);
        $this->assertEqualsWithDelta(18.0, (float) $line->tax_amount_native, 0.01);
    }

    public function testIdempotentWhenReceiptAlreadyExists(): void
    {
        $app = app(Apps::class);
        $company = $this->company;

        $company->set(ConfigurationEnum::SCRIBE_SYNC_SOUK_ORDERS->value, '1');

        $order = $this->buildOrderWithItem(
            subtotal: 50.0,
            unitTaxAmount: 5.0,
            quantity: 1,
            taxCalculationMode: 'exclusive',
        );

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
        float $unitTaxAmount,
        float $quantity,
        string $taxCalculationMode,
    ): Order {
        $order = new Order();
        $order->apps_id = $this->kanvasApp->getId();
        $order->companies_id = $this->company->getId();
        $order->users_id = static::$cachedUser->getId();
        $order->region_id = 1;
        $order->order_number = random_int(10000, 99999);
        $order->status = 'draft';
        $order->fulfillment_status = 'pending';
        $order->currency = 'USD';
        $order->tax_calculation_mode = $taxCalculationMode;
        $order->subtotal_amount = $subtotal;
        $order->tax_amount = $unitTaxAmount * $quantity;
        $order->total_gross_amount = $subtotal + ($unitTaxAmount * $quantity);
        $order->total_net_amount = $subtotal;
        $order->save();

        $item = new OrderItem();
        $item->apps_id = $this->kanvasApp->getId();
        $item->order_id = $order->id;
        $item->product_name = 'Test Product';
        $item->product_sku = 'SKU-001';
        $item->quantity = $quantity;
        $item->unit_price_net_amount = $subtotal;
        $item->unit_price_gross_amount = $subtotal + $unitTaxAmount;
        $item->unit_tax_amount = $unitTaxAmount;
        $item->variant_id = 0;
        $item->is_public = true;
        $item->save();

        return $order->refresh();
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
        $order->tax_source = 'imported';
        $order->subtotal_amount = $subtotal;
        $order->tax_amount = $taxAmount;
        $order->total_gross_amount = $subtotal + $taxAmount;
        $order->total_net_amount = $subtotal;
        $order->save();

        return $order->refresh();
    }
}
