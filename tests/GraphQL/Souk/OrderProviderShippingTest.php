<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Illuminate\Testing\TestResponse;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Models\Order;
use Wearepixel\Cart\CartCondition;

class OrderProviderShippingTest extends ShippingOrderBase
{
    public function testProviderShippingIsPersistedAndCharged(): void
    {
        $this->addToCart()->assertSuccessful();
        $quote = $this->shippingQuotes()['ems'];
        $this->applyShippingQuote()->assertSuccessful();
        $estimate = $this->cartShipping()['attributes']['estimate_shipping_date'];

        $order = $this->orderFromResponse($this->placeOrderFromCart());

        $this->assertSame($quote['service_name'], $order->shipping_method_name);
        $this->assertStringStartsWith($estimate, (string) $order->estimate_shipping_date);
        $this->assertSame('inposdom', $order->metadata['shipping']['provider']);
        $this->assertSame('ems', $order->metadata['shipping']['service']);
        $this->assertEquals($quote['amount'], $order->metadata['shipping']['quoted_amount']);
        $this->assertEquals($quote['amount'], (float) $order->shipping_price_net_amount);
        $this->assertEquals(
            (float) $order->total_gross_amount - (float) $order->tax_amount + $quote['amount'],
            $order->getTotalDueAmount()
        );
    }

    public function testStaleCartIsRepricedAtOrderCreation(): void
    {
        $this->addToCart()->assertSuccessful();
        $this->applyShippingQuote()->assertSuccessful();
        $staleAmount = (float) ltrim($this->cartShipping()['value'], '+');

        $this->configureRateCards(function (array $cards): array {
            foreach ($cards['inposdom']['services']['ems']['zones'] as &$zone) {
                foreach ($zone['rates'] as &$rate) {
                    $rate['amount'] += 1000;
                }
            }

            return $cards;
        });
        $freshAmount = $this->shippingQuotes()['ems']['amount'];

        $order = $this->orderFromResponse($this->placeOrderFromCart());

        $this->assertNotEquals($staleAmount, $freshAmount);
        $this->assertEquals($freshAmount, (float) $order->shipping_price_net_amount);
        $this->assertEquals($freshAmount, $order->metadata['shipping']['quoted_amount']);
    }

    public function testUnsupportedServiceFailsOrderCreation(): void
    {
        $this->addToCart()->assertSuccessful();
        $this->applyShippingQuote()->assertSuccessful();

        $this->configureRateCards(function (array $cards): array {
            unset($cards['inposdom']['services']['ems']);

            return $cards;
        });
        $ordersBefore = Order::query()->count();

        $response = $this->placeOrderFromCart();

        $this->assertSame('Selected shipping service is no longer available', $response->json('errors.0.message'));
        $this->assertSame($ordersBefore, Order::query()->count());
    }

    public function testProviderOrderMergesShippingIntoJsonStringMetadata(): void
    {
        $this->addToCart()->assertSuccessful();
        $this->applyShippingQuote()->assertSuccessful();

        $order = $this->orderFromResponse($this->placeOrderFromCart('{"keep":"me"}'));

        $this->assertSame('me', $order->metadata['keep']);
        $this->assertSame('inposdom', $order->metadata['shipping']['provider']);
    }

    public function testNonProviderOrderAcceptsStringMetadata(): void
    {
        $this->addToCart()->assertSuccessful();

        $response = $this->placeOrderFromCart('{"keep":"me"}');

        $this->assertNull($response->json('errors'));
        $this->assertNotNull($this->orderFromResponse($response));
    }

    public function testNonProviderShippingConditionKeepsCurrentTotals(): void
    {
        $order = $this->createOrderWithLegacyShipping();

        $this->assertNull($order->shipping_method_name);
        $this->assertArrayNotHasKey('shipping', $order->metadata ?? []);
        $this->assertEquals(25.0, (float) $order->shipping_price_net_amount);
        $this->assertEquals(
            (float) $order->total_gross_amount - (float) $order->tax_amount,
            $order->getTotalDueAmount()
        );
    }

    public function testCalculateTotalKeepsProviderShipping(): void
    {
        $order = $this->createOrderWithProviderShipping();
        $expected = $order->getTotalDueAmount();

        $order->calculateTotal();
        $order->refresh();

        $this->assertEquals($expected, $order->getTotalDueAmount());
    }

    public function testCalculateTotalWithoutProviderDoesNotAddShipping(): void
    {
        $order = $this->createOrderWithLegacyShipping();

        $order->calculateTotal();
        $order->refresh();

        $this->assertEquals((float) $order->total_gross_amount, $order->getTotalDueAmount());
    }

    public function testAddPaymentRejectsShortAmountWhenEnforced(): void
    {
        $this->apps->set(ConfigurationEnum::ENFORCE_EXACT_PAYMENT_AMOUNT->value, true);
        $order = $this->createOrderWithProviderShipping();

        $data = $this->addCashPayment($order, $order->getTotalDueAmount() - 25)->json('data.addPaymentToOrder');

        $this->apps->set(ConfigurationEnum::ENFORCE_EXACT_PAYMENT_AMOUNT->value, false);
        $this->assertSame('error', $data['status']);
    }

    public function testAddPaymentAcceptsExactAmountWhenEnforced(): void
    {
        $this->apps->set(ConfigurationEnum::ENFORCE_EXACT_PAYMENT_AMOUNT->value, true);
        $order = $this->createOrderWithProviderShipping();

        $data = $this->addCashPayment($order, $order->getTotalDueAmount())->json('data.addPaymentToOrder');

        $this->apps->set(ConfigurationEnum::ENFORCE_EXACT_PAYMENT_AMOUNT->value, false);
        $this->assertSame('success', $data['status']);
    }

    public function testAddPaymentAcceptsShortAmountWhenNotEnforced(): void
    {
        $this->apps->set(ConfigurationEnum::ENFORCE_EXACT_PAYMENT_AMOUNT->value, false);
        $order = $this->createOrderWithProviderShipping();

        $data = $this->addCashPayment($order, $order->getTotalDueAmount() - 25)->json('data.addPaymentToOrder');

        $this->assertSame('success', $data['status']);
    }

    protected function createOrderWithProviderShipping(): Order
    {
        $this->addToCart()->assertSuccessful();
        $this->applyShippingQuote()->assertSuccessful();

        return $this->orderFromResponse($this->placeOrderFromCart());
    }

    protected function createOrderWithLegacyShipping(): Order
    {
        $this->addToCart()->assertSuccessful();
        app('cart')->session($this->cartIdentifier)->condition(new CartCondition([
            'name' => 'Shipping',
            'type' => 'shipping',
            'target' => 'subtotal',
            'value' => '+25',
            'attributes' => ['Shipping Cost' => 25],
        ]));

        return $this->orderFromResponse($this->placeOrderFromCart());
    }

    protected function addCashPayment(Order $order, float $amount): TestResponse
    {
        return $this->graphQL('
            mutation addPaymentToOrder($orderID: ID!, $input: PaymentInput!) {
                addPaymentToOrder(orderID: $orderID, input: $input) {
                    status
                    message
                }
            }
        ', [
            'orderID' => $order->id,
            'input' => [
                'payment_method' => 'CASH',
                'amount' => $amount,
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);
    }
}
