<?php

declare(strict_types=1);

namespace Tests\Souk\Integration;

use Kanvas\Souk\Orders\Models\Order;
use Tests\TestCase;

/**
 * Pins the contract that payment processors charge the post-discount net total.
 * Regression for payment links / Azul / CardNet charging the pre-discount gross.
 */
final class OrderTotalDueAmountTest extends TestCase
{
    public function testTotalDueAmountIsNetTotalAfterDiscount(): void
    {
        $order = new Order();
        $order->total_gross_amount = 7643.32;
        $order->discount_amount = 1329.94;
        $order->total_net_amount = 6313.38;

        $this->assertSame(6313.38, $order->getTotalDueAmount());
        $this->assertSame(7643.32, $order->getTotalAmount());
        $this->assertLessThan($order->getTotalAmount(), $order->getTotalDueAmount());
        $this->assertSame(
            round($order->getTotalAmount() - $order->discount_amount, 2),
            round($order->getTotalDueAmount(), 2),
        );
    }

    public function testTotalDueAmountEqualsGrossWhenNoDiscount(): void
    {
        $order = new Order();
        $order->total_gross_amount = 500.00;
        $order->discount_amount = 0.0;
        $order->total_net_amount = 500.00;

        $this->assertSame($order->getTotalAmount(), $order->getTotalDueAmount());
    }
}
