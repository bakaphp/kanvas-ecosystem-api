<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Discounts\Models\OrderDiscount;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use Tests\TestCase;

final class OrderRecomputeTotalsTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = [null, 'commerce'];

    private function createOrder(array $attributes = []): Order
    {
        $app = app(Apps::class);
        $user = auth()->user();

        return Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->create(array_merge([
                'total_gross_amount' => 200.00,
                'total_net_amount' => 180.00,
                'discount_amount' => 0.00,
                'tax_amount' => 0.00,
            ], $attributes));
    }

    private function addOrderItem(Order $order, float $unitPrice, int $qty): void
    {
        OrderItem::create([
            'order_id' => $order->id,
            'apps_id' => $order->apps_id,
            'product_name' => 'Test Product',
            'product_sku' => 'SKU-001',
            'quantity' => $qty,
            'unit_price_net_amount' => $unitPrice,
            'unit_price_gross_amount' => $unitPrice,
            'is_shipping_required' => true,
            'quantity_fulfilled' => 0,
            'variant_id' => 1,
            'tax_rate' => 0,
            'currency' => 'USD',
            'variant_name' => 'Default',
            'is_public' => 1,
        ]);
    }

    private function addOrderDiscount(Order $order, float $amount): void
    {
        $discount = Discount::factory()->create([
            'apps_id' => $order->apps_id,
            'companies_id' => $order->companies_id,
        ]);

        OrderDiscount::create([
            'apps_id' => $order->apps_id,
            'order_id' => $order->id,
            'discount_id' => $discount->id,
            'amount' => $amount,
        ]);
    }

    public function testV1OrderCalculateTotalPreservesExistingTotals(): void
    {
        $order = $this->createOrder([
            'total_gross_amount' => 200.00,
            'total_net_amount' => 180.00,
        ]);

        Order::withoutSyncingToSearch(fn () => $order->calculateTotal(autoSave: false));

        $this->assertNull($order->totals_version);
        $this->assertSame(200.00, (float) $order->total_gross_amount);
        $this->assertSame(180.00, (float) $order->total_net_amount);
    }

    public function testGetTotalTaxAmountReturnsStoredTaxAmount(): void
    {
        $order = $this->createOrder(['tax_amount' => 18.00]);

        $this->assertSame(18.0, $order->getTotalTaxAmount());
    }

    public function testGetTotalTaxAmountIsNotGrossMinusNet(): void
    {
        $order = $this->createOrder([
            'total_gross_amount' => 200.00,
            'total_net_amount' => 180.00,
            'tax_amount' => 7.00,
        ]);

        $this->assertSame(7.0, $order->getTotalTaxAmount());
        $this->assertNotSame(20.0, $order->getTotalTaxAmount());
    }

    public function testImportedTaxAmountIsNotOverwrittenByRecomputeTotals(): void
    {
        $order = $this->createOrder([
            'totals_version' => 2,
            'tax_source' => 'imported',
            'tax_amount' => 10.00,
            'subtotal_amount' => 0,
            'total_payable_amount' => 0,
            'total_gross_amount' => 0,
            'total_net_amount' => 0,
        ]);

        $this->addOrderItem($order, 100.00, 1);

        Order::withoutSyncingToSearch(fn () => $order->recomputeTotals(autoSave: false));

        $this->assertSame(10.0, (float) $order->tax_amount);
    }

    public function testV2RecomputeSubtotalMinusDiscountPlusShippingPlusTax(): void
    {
        $order = $this->createOrder([
            'totals_version' => 2,
            'tax_source' => 'souk',
            'tax_amount' => 18.00,
            'subtotal_amount' => 0,
            'discount_amount' => 0,
            'total_payable_amount' => 0,
            'total_gross_amount' => 0,
            'total_net_amount' => 0,
            'shipping_price_gross_amount' => 5.00,
            'shipping_price_net_amount' => 5.00,
            'metadata' => ['shipping' => ['provider' => 'dhl']],
        ]);

        $this->addOrderItem($order, 100.00, 1);
        $this->addOrderDiscount($order, 10.00);

        Order::withoutSyncingToSearch(fn () => $order->recomputeTotals(autoSave: false));

        $this->assertSame(100.0, (float) $order->subtotal_amount);
        $this->assertSame(10.0, (float) $order->discount_amount);
        $this->assertSame(18.0, (float) $order->tax_amount);
        $this->assertSame(113.0, (float) $order->total_payable_amount);
        $this->assertSame(113.0, (float) $order->total_net_amount);
        $this->assertSame(2, (int) $order->totals_version);
    }
}
