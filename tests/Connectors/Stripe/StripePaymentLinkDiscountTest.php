<?php

declare(strict_types=1);

namespace Tests\Connectors\Stripe;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Stripe\Services\StripePaymentLinkService;
use Kanvas\Souk\Orders\Models\Order;
use ReflectionMethod;
use Stripe\Coupon;
use Tests\Connectors\Stripe\Fakes\FakeStripeClient;
use Tests\TestCase;

/**
 * The Stripe payment link builds its line items from item gross prices, so the
 * order-level discount (order_discounts → discount_amount) must be re-applied as a
 * one-time coupon, otherwise the customer is charged the full pre-discount total.
 */
final class StripePaymentLinkDiscountTest extends TestCase
{
    public function testCreatesCouponWithDiscountAmountInCents(): void
    {
        $client = new FakeStripeClient();
        $client->getCoupons()->queueResponse('create', Coupon::constructFrom(['id' => 'coupon_001']));

        $service = $this->makeService($client);

        $order = new Order();
        $order->id = 42;
        $order->discount_amount = 1329.94;
        $order->discount_name = 'Partner Discount';
        $order->voucher_id = 7;
        $order->currency = 'USD';

        $couponId = $this->createDiscountCoupon($service, $order);

        $this->assertSame('coupon_001', $couponId);

        $calls = $client->getCoupons()->getCalls('create');
        $this->assertCount(1, $calls);
        $this->assertSame(132994, $calls[0]['params']['amount_off']);
        $this->assertSame('usd', $calls[0]['params']['currency']);
        $this->assertSame('once', $calls[0]['params']['duration']);
        $this->assertSame('Partner Discount', $calls[0]['params']['name']);
    }

    public function testReturnsNullAndCreatesNoCouponWhenOrderHasNoDiscount(): void
    {
        $client = new FakeStripeClient();

        $service = $this->makeService($client);

        $order = new Order();
        $order->id = 43;
        $order->discount_amount = 0;
        $order->currency = 'USD';

        $couponId = $this->createDiscountCoupon($service, $order);

        $this->assertNull($couponId);
        $this->assertCount(0, $client->getCoupons()->getCalls('create'));
    }

    private function makeService(FakeStripeClient $client): StripePaymentLinkService
    {
        $app = $this->createMock(AppInterface::class);

        return new StripePaymentLinkService($app, null, $client);
    }

    private function createDiscountCoupon(StripePaymentLinkService $service, Order $order): ?string
    {
        $method = new ReflectionMethod($service, 'createDiscountCoupon');

        return $method->invoke($service, $order);
    }
}
