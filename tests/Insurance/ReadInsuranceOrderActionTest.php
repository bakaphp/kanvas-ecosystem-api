<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Illuminate\Database\Eloquent\Collection;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\Actions\ReadInsuranceOrderAction;
use Kanvas\Insurance\Contracts\InsuranceProviderInterface;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use Mockery;
use Tests\TestCase;

class ReadInsuranceOrderActionTest extends TestCase
{
    private function order(array $metadata, array $variants = []): Order
    {
        $items = array_map(
            function (Variants $variant): OrderItem {
                $item = Mockery::mock(OrderItem::class)->makePartial();
                $item->setRelation('variant', $variant);

                return $item;
            },
            $variants
        );

        $order = Mockery::mock(Order::class)->makePartial();
        $order->metadata = $metadata;
        $order->shouldReceive('get')->andReturn(null);
        $order->setRelation('items', new Collection($items));

        return $order;
    }

    private function typedVariant(string $productTypeSlug): Variants
    {
        $productsType = Mockery::mock(ProductsTypes::class)->makePartial();
        $productsType->slug = $productTypeSlug;

        $product = Mockery::mock(Products::class)->makePartial();
        $product->setRelation('productsType', $productsType);

        $variant = Mockery::mock(Variants::class)->makePartial();
        $variant->setRelation('product', $product);

        return $variant;
    }

    private function request(Order $order)
    {
        $data = new ReadInsuranceOrderAction($order)->execute();

        return $data->quoteRequest(Mockery::mock(InsuranceProviderInterface::class));
    }

    public function testRebuildsTheRequestFromLegacyMetadata(): void
    {
        $variant = Mockery::mock(Variants::class)->makePartial();
        $order = $this->order(
            ['insurance' => ['product' => 'A-PA', 'payload' => ['tipo' => 'APA']]],
            [$variant]
        );

        $request = $this->request($order);

        $this->assertSame('A-PA', $request->product);
        $this->assertSame(['tipo' => 'APA'], $request->payload);
        $this->assertSame($variant, $request->vehicle);
    }

    public function testPicksTheVehicleLineRatherThanWhicheverComesFirst(): void
    {
        $plan = $this->typedVariant('insurance-plan');
        $vehicle = $this->typedVariant('vehicle');

        $order = $this->order(['insurance' => ['provider' => 'p', 'product' => 'A-PA']], [$plan, $vehicle]);

        $this->assertSame($vehicle, $this->request($order)->vehicle);
    }

    public function testFallsBackToTheOnlyLineWhenNoneIsTypedVehicle(): void
    {
        $only = $this->typedVariant('parking');

        $order = $this->order(['insurance' => ['product' => 'A-PA']], [$only]);

        $this->assertSame($only, $this->request($order)->vehicle);
    }

    public function testTheLegacyFallbackNeverTreatsThePlanLineAsTheVehicle(): void
    {
        $order = $this->order(['insurance' => ['provider' => 'p', 'product' => 'A-PA']], [$this->typedVariant('insurance-plan')]);

        $this->assertNull($this->request($order)->vehicle);
    }

    public function testVehicleIsNullWhenTheOrderHasNoItems(): void
    {
        $request = $this->request($this->order(['insurance' => ['product' => 'A-PL']]));

        $this->assertSame([], $request->payload);
        $this->assertNull($request->vehicle);
    }

    public function testRefusesAnOrderWithoutAProduct(): void
    {
        $this->expectException(ValidationException::class);

        $this->request($this->order(['insurance' => ['quote_number' => '1']]));
    }

    public function testStoredCustomerIsLaidOverTheLegacyPayload(): void
    {
        $order = $this->order([
            'insurance' => [
                'product' => 'A-PA',
                'payload' => ['tipo' => 'APA', 'cliente' => ['nombre' => 'old']],
                'cliente' => ['nombre' => 'Ana', 'numeroDocumento' => '00100000001'],
            ],
        ]);

        $request = $this->request($order);

        $this->assertSame('APA', $request->payload['tipo']);
        $this->assertSame(['nombre' => 'Ana', 'numeroDocumento' => '00100000001'], $request->payload['cliente']);
    }

    public function testALegacyOrderIgnoresTheDataBlockOfOtherFeatures(): void
    {
        $order = $this->order([
            'insurance' => ['provider' => 'p', 'product' => 'A-PA', 'quote_number' => 'LEG'],
            'data' => ['quote_number' => 'OTHER', 'customer' => ['first_name' => 'X']],
        ]);

        $data = new ReadInsuranceOrderAction($order)->execute();

        $this->assertSame('LEG', $data->quoteNumber);
        $this->assertNull($data->customer);
        $this->assertFalse($data->hasCustomerData());
    }
}
