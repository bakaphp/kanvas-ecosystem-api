<?php

declare(strict_types=1);

namespace Tests\Souk\Integration\Shipping;

use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Products\Models\ProductsAttributes;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Locations\Models\Countries;
use Kanvas\Souk\Shipping\Actions\BuildShipmentRequestAction;
use Kanvas\Souk\Shipping\DataTransferObject\ShippingDestination;
use Kanvas\Souk\Shipping\Restrictions\CheckShippingRestrictionsAction;
use Mockery;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;
use Wearepixel\Cart\Cart;

final class CheckShippingRestrictionsActionTest extends TestCase
{
    use DatabaseTransactions;
    use InventoryCases;

    public function testAnEmptyAttributeShipsAnywhere(): void
    {
        $action = $this->action([$this->variantWith('Plain box', null)], new ShippingDestination(countryCode: 'us'));

        $this->assertSame([], $action->blockedProductNames());
    }

    public function testAnAllowedCountryPasses(): void
    {
        $action = $this->action([$this->variantWith('Box', 'DO,US-FL')], new ShippingDestination(countryCode: 'do'));

        $this->assertSame([], $action->blockedProductNames());
    }

    public function testABlockedCountryNamesTheProduct(): void
    {
        $action = $this->action(
            [$this->variantWith('Box', 'DO'), $this->variantWith('Other', null)],
            new ShippingDestination(countryCode: 'us')
        );

        $this->assertSame(['Box'], $action->blockedProductNames());
    }

    public function testAProvinceRuleMatchesOnlyThatState(): void
    {
        $florida = new ShippingDestination(countryCode: 'us', state: 'fl');
        $texas = new ShippingDestination(countryCode: 'us', state: 'TX');
        $variants = [$this->variantWith('Box', 'DO, US-FL')];

        $this->assertSame([], $this->action($variants, $florida)->blockedProductNames());
        $this->assertSame(['Box'], $this->action($variants, $texas)->blockedProductNames());
    }

    public function testAssertAllowedRejectsWithTheBlockedProductNames(): void
    {
        $action = $this->action([$this->variantWith('Box', 'DO')], new ShippingDestination(countryCode: 'us'));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Box');

        $action->assertAllowed();
    }

    public function testTheAttributeSurvivesAStoredProductRoundTrip(): void
    {
        $name = 'Restricted ' . uniqid();
        $data = $this->graphQLData($this->createProduct([
            'name' => $name,
            'description' => 'Restricted product',
            'sku' => uniqid('sku-'),
            'slug' => uniqid('restricted-'),
            'weight' => 1,
            'attributes' => [['name' => 'Shipping Destinations', 'value' => 'DO,US-FL']],
        ]), 'createProduct');

        $variant = new Variants();
        $variant->setRelation('product', Products::find($data['id']));

        $this->assertSame([$name], $this->action([$variant], new ShippingDestination(countryCode: 'us'))->blockedProductNames());
        $this->assertSame([], $this->action([$variant], new ShippingDestination(countryCode: 'us', state: 'FL'))->blockedProductNames());
        $this->assertSame([], $this->action([$variant], new ShippingDestination(countryCode: 'do'))->blockedProductNames());
    }

    public function testTheShipmentRequestIsRefusedForARestrictedCartLine(): void
    {
        Countries::firstOrCreate(['code' => 'us'], ['name' => 'United States']);
        $cart = new Cart(
            new Store('restriction-cart', new ArraySessionHandler(120)),
            new Dispatcher(),
            'restriction-cart',
            'restriction-cart',
            ['driver' => 'session', 'format_numbers' => false, 'decimals' => 2, 'round_mode' => 'down'],
        );
        $cart->add(1, 'Variant 1', 10.0, 1);
        $variant = $this->variantWith('Box', 'DO');
        $variant->weight = 0.5;

        $action = new class (app(Apps::class), $cart, new ShippingDestination(countryCode: 'us'), $variant) extends BuildShipmentRequestAction {
            public function __construct(Apps $app, Cart $cart, ShippingDestination $destination, private readonly Variants $variant)
            {
                parent::__construct(app: $app, cart: $cart, destination: $destination);
            }

            protected function findVariant(int|string $id): Variants
            {
                return $this->variant;
            }
        };

        $this->expectException(ValidationException::class);

        $action->execute();
    }

    private function action(array $variants, ShippingDestination $destination): CheckShippingRestrictionsAction
    {
        return new CheckShippingRestrictionsAction($variants, $destination);
    }

    private function variantWith(string $productName, ?string $destinations): Variants
    {
        $attribute = null;

        if ($destinations !== null) {
            $attribute = Mockery::mock(ProductsAttributes::class)->makePartial();
            $attribute->shouldReceive('getAttribute')->with('value')->andReturn($destinations);
        }
        $product = Mockery::mock(Products::class)->makePartial();
        $product->id = random_int(1000, 999999);
        $product->name = $productName;
        $product->shouldReceive('getAttributeBySlug')
            ->with(CheckShippingRestrictionsAction::ATTRIBUTE_SLUG)
            ->andReturn($attribute);

        $variant = new Variants();
        $variant->setRelation('product', $product);

        return $variant;
    }
}
