<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Connectors\ScrapperApi\Enums\ShippingCostEnum;
use Wearepixel\Cart\CartCondition;

class ShippingQuoteTest extends ShippingOrderBase
{
    public function testQuotesReturnBothInposdomServices(): void
    {
        $this->addToCart()->assertSuccessful();

        $quotes = $this->shippingQuotes();

        $this->assertEqualsCanonicalizing(['ems', 'correo_certificado'], array_keys($quotes));
        $this->assertSame('inposdom', $quotes['ems']['provider']);
        $this->assertGreaterThan(0, $quotes['ems']['amount']);
    }

    public function testQuotesAreEmptyForAnUnmappedCountry(): void
    {
        $this->addToCart()->assertSuccessful();

        $this->assertSame([], $this->shippingQuotes('ZZ'));
    }

    public function testApplyShippingQuoteSetsCartShipping(): void
    {
        $this->addToCart()->assertSuccessful();
        $quote = $this->shippingQuotes()['ems'];

        $shipping = $this->applyShippingQuote()->json('data.applyShippingQuote.shipping');

        $this->assertSame('Shipping', $shipping['name']);
        $this->assertEquals($quote['amount'], (float) ltrim($shipping['value'], '+'));
        $this->assertSame('inposdom', $shipping['attributes']['provider']);
        $this->assertSame('ems', $shipping['attributes']['service']);
        $this->assertSame('US', $shipping['attributes']['destination']['country']);
    }

    public function testApplyingAnUnavailableServiceIsRejected(): void
    {
        $this->addToCart()->assertSuccessful();

        $response = $this->applyShippingQuote('ghost');

        $this->assertSame('Shipping service not available', $response->json('errors.0.message'));
        $this->assertNull($this->cartShipping());
    }

    public function testApplyIsRejectedWhenLocomproCostIsOn(): void
    {
        $this->addToCart()->assertSuccessful();
        $this->apps->set(ShippingCostEnum::LOCOMPRO_COST->value, true);

        $response = $this->applyShippingQuote();

        $this->apps->del(ShippingCostEnum::LOCOMPRO_COST->value);
        $this->assertNotNull($response->json('errors.0.message'));
        $this->assertNull($this->cartShipping());
    }

    public function testSelectionSurvivesAddingAnItem(): void
    {
        $this->addToCart()->assertSuccessful();
        $this->applyShippingQuote()->assertSuccessful();

        $this->addToCart()->assertSuccessful();

        $this->assertSame('ems', $this->cartShipping()['attributes']['service']);
    }

    public function testCrossingAWeightBracketReprices(): void
    {
        $this->addToCart()->assertSuccessful();
        $this->applyShippingQuote()->assertSuccessful();
        $lightValue = $this->cartShipping()['value'];

        $this->updateCartQuantity(2)->assertSuccessful();

        $heavyQuote = $this->shippingQuotes()['ems']['amount'];
        $heavyValue = $this->cartShipping()['value'];
        $this->assertNotSame($lightValue, $heavyValue);
        $this->assertEquals($heavyQuote, (float) ltrim($heavyValue, '+'));
    }

    public function testConditionIsRemovedWhenTheSelectionIsNoLongerQuotable(): void
    {
        $this->addToCart()->assertSuccessful();
        $this->applyShippingQuote()->assertSuccessful();

        $this->setVariantWeight(999.99);
        $this->updateCartQuantity(2)->assertSuccessful();

        $this->assertNull($this->cartShipping());
    }

    public function testConditionIsRemovedWhenTheLastItemIsRemoved(): void
    {
        $this->addToCart()->assertSuccessful();
        $this->applyShippingQuote()->assertSuccessful();

        $this->removeFromCart()->assertSuccessful();

        $this->assertNull($this->cartShipping());
    }

    public function testACartWithoutProviderShippingIsUntouched(): void
    {
        $this->addToCart()->assertSuccessful();
        app('cart')->session($this->cartIdentifier)->condition(new CartCondition([
            'name' => 'Shipping',
            'type' => 'shipping',
            'target' => 'subtotal',
            'value' => '+25',
            'attributes' => ['Shipping Cost' => 25],
        ]));

        $this->updateCartQuantity(2)->assertSuccessful();

        $this->assertSame('+25', $this->cartShipping()['value']);
    }

    public function testAppliedShippingFlowsIntoTheOrder(): void
    {
        $this->addToCart()->assertSuccessful();
        $quote = $this->shippingQuotes()['ems'];
        $this->applyShippingQuote()->assertSuccessful();

        $order = $this->orderFromResponse($this->placeOrderFromCart());

        $this->assertSame($quote['service_name'], $order->shipping_method_name);
        $this->assertEquals($quote['amount'], (float) $order->shipping_price_net_amount);
        $this->assertEquals(
            (float) $order->total_gross_amount - (float) $order->tax_amount + $quote['amount'],
            $order->getTotalDueAmount()
        );
    }
}
