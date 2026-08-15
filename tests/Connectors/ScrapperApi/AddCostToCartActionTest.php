<?php

declare(strict_types=1);

namespace Tests\Connectors\ScrapperApi;

use Illuminate\Events\Dispatcher;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ScrapperApi\Actions\AddCostToCartAction;
use Kanvas\Connectors\ScrapperApi\Enums\ShippingCostEnum;
use Kanvas\Inventory\Variants\Models\Variants;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Wearepixel\Cart\Cart;
use Wearepixel\Cart\CartCondition;

class AddCostToCartActionTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function testAggregatesShippingAndOfficialTaxesForACartOverTwoHundredDollars(): void
    {
        $app = $this->enabledApp();
        $cart = $this->cart('taxed-cart');
        $cart->add(101, 'First product', 125.0, 2);
        $cart->add(202, 'Second product', 100.0, 1);

        $action = new class ($app, $cart, []) extends AddCostToCartAction {
            protected function findVariant(int|string $id): Variants
            {
                $variant = Mockery::mock(Variants::class)->makePartial();
                $variant->id = (int) $id;

                return $variant;
            }

            protected function calculateShipping(Variants $variant, float $quantity): array
            {
                return [
                    'shippingCost' => $variant->id === 101 ? 10.0 : 5.0,
                    'otherFee' => 2.0,
                    'serviceFee' => 3.0,
                    'total' => $variant->id === 101 ? 15.0 : 10.0,
                    'pounds' => $quantity,
                    'insurance' => $variant->id === 101 ? 4.0 : 1.3,
                ];
            }

            protected function calculateCustomTax(
                Variants $variant,
                float $quantity,
                float $freight,
                float $insurance
            ): array {
                $tax = $variant->id === 101 ? 50.0 : 20.0;

                return [
                    'customTax' => $tax,
                    'customTaxRD' => $tax * 60,
                    'productName' => $variant->id === 101 ? 'First product' : 'Second product',
                    'arancelCode' => $variant->id === 101 ? '6109.10.00' : '8471.30.00',
                    'countryOrigin' => 'US',
                    'arancel' => $variant->id === 101 ? 30.0 : 0.0,
                    'arancelRD' => $variant->id === 101 ? 1800.0 : 0.0,
                    'arancelRate' => $variant->id === 101 ? 20.0 : 0.0,
                    'itbis' => $variant->id === 101 ? 20.0 : 20.0,
                    'itbisRD' => 1200.0,
                    'itbisRate' => 18.0,
                    'tasaAduanal' => 0.0,
                    'tasaAduanalRD' => 0.0,
                    'tasaAduanalRate' => 0.0,
                    'isc' => 0.0,
                    'iscRD' => 0.0,
                    'iscDescription' => 'ISC/CO2',
                ];
            }
        };

        $action->execute();

        $shipping = $cart->getCondition('Shipping');
        $attributes = $shipping->getAttributes();

        $this->assertSame('+82.84', $shipping->getValue());
        $this->assertSame(70.0, $attributes['Custom Tax']);
        $this->assertSame(2.5, $attributes['Shipping Cost']);
        $this->assertSame(3.5, $attributes['Service Fee']);
        $this->assertEqualsWithDelta(5.6, $attributes['Insurance Fee'], 0.000001);
        $this->assertArrayNotHasKey('Mark-Up / Comm Rev', $attributes);
        $this->assertSame(0.0, $attributes['Tax Breakdown']['Total Tasa Aduanal']);
        $this->assertCount(2, $attributes['Custom Tax Details']);
    }

    public function testSkipsTaxesWhenCartSubtotalIsTwoHundredDollarsOrLess(): void
    {
        $app = $this->enabledApp();
        $cart = $this->cart('untaxed-cart');
        $cart->add(303, 'De minimis product', 200.0, 1);

        $action = new class ($app, $cart, []) extends AddCostToCartAction {
            protected function findVariant(int|string $id): Variants
            {
                return Mockery::mock(Variants::class)->makePartial();
            }

            protected function calculateShipping(Variants $variant, float $quantity): array
            {
                return [
                    'shippingCost' => 10.0,
                    'otherFee' => 2.0,
                    'serviceFee' => 3.0,
                    'total' => 15.0,
                    'pounds' => 1.0,
                    'insurance' => 3.2,
                ];
            }

            protected function calculateCustomTax(
                Variants $variant,
                float $quantity,
                float $freight,
                float $insurance
            ): array {
                throw new RuntimeException('Tax calculation must not run at or below the threshold.');
            }
        };

        $action->execute();

        $shipping = $cart->getCondition('Shipping');
        $attributes = $shipping->getAttributes();

        $this->assertSame('+10.44', $shipping->getValue());
        $this->assertSame(0, $attributes['Custom Tax']);
        $this->assertSame([], $attributes['Custom Tax Details']);
        $this->assertSame(3.5, $attributes['Service Fee']);
        $this->assertSame(3.2, $attributes['Insurance Fee']);
    }

    public function testIgnoresExistingShippingConditionWhenEvaluatingTaxThreshold(): void
    {
        $app = $this->enabledApp();
        $cart = $this->cart('stale-tax-cart');
        $cart->add(404, 'Remaining product', 100.0, 1);
        $cart->condition(new CartCondition([
            'name' => 'Shipping',
            'type' => 'shipping',
            'target' => 'subtotal',
            'value' => '+343.94',
            'attributes' => [
                'Custom Tax' => 304.55,
            ],
        ]));

        $action = new class ($app, $cart, []) extends AddCostToCartAction {
            protected function findVariant(int|string $id): Variants
            {
                return Mockery::mock(Variants::class)->makePartial();
            }

            protected function calculateShipping(Variants $variant, float $quantity): array
            {
                return [
                    'shippingCost' => 10.0,
                    'otherFee' => 2.0,
                    'serviceFee' => 3.0,
                    'total' => 15.0,
                    'pounds' => 1.0,
                    'insurance' => 3.2,
                ];
            }

            protected function calculateCustomTax(
                Variants $variant,
                float $quantity,
                float $freight,
                float $insurance
            ): array {
                throw new RuntimeException('A stale shipping condition must not trigger tax calculation.');
            }
        };

        $action->execute();

        $shipping = $cart->getCondition('Shipping');
        $attributes = $shipping->getAttributes();

        $this->assertSame('+7.24', $shipping->getValue());
        $this->assertSame(0, $attributes['Custom Tax']);
        $this->assertSame([], $attributes['Custom Tax Details']);
        $this->assertSame(3.5, $attributes['Service Fee']);
        $this->assertSame(0.0, $attributes['Insurance Fee']);
    }

    private function enabledApp(): Apps
    {
        $app = Mockery::mock(Apps::class)->makePartial();
        $app->shouldReceive('get')->andReturnUsing(
            fn (string $key): mixed => $key === ShippingCostEnum::LOCOMPRO_COST->value ? true : null,
        );

        return $app;
    }

    private function cart(string $key): Cart
    {
        return new Cart(
            new Store($key, new ArraySessionHandler(120)),
            new Dispatcher(),
            $key,
            $key,
            [
                'driver' => 'session',
                'format_numbers' => false,
                'decimals' => 2,
                'round_mode' => 'down',
            ],
        );
    }
}
