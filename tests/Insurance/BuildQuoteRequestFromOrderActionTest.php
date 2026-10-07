<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Illuminate\Database\Eloquent\Collection;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\Actions\BuildQuoteRequestFromOrderAction;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use Mockery;
use Tests\TestCase;

class BuildQuoteRequestFromOrderActionTest extends TestCase
{
    private function order(array $metadata, ?Variants $variant = null): Order
    {
        $item = Mockery::mock(OrderItem::class)->makePartial();
        $item->setRelation('variant', $variant);

        $order = Mockery::mock(Order::class)->makePartial();
        $order->metadata = $metadata;
        $order->setRelation('items', new Collection($variant !== null ? [$item] : []));

        return $order;
    }

    public function testRebuildsTheRequestFromOrderMetadata(): void
    {
        $variant = Mockery::mock(Variants::class)->makePartial();
        $order = $this->order(
            ['insurance' => ['product' => 'A-PA', 'payload' => ['tipo' => 'APA']]],
            $variant
        );

        $request = new BuildQuoteRequestFromOrderAction($order)->execute();

        $this->assertSame('A-PA', $request->product);
        $this->assertSame(['tipo' => 'APA'], $request->payload);
        $this->assertSame($variant, $request->vehicle);
    }

    public function testVehicleIsNullWhenTheOrderHasNoItems(): void
    {
        $order = $this->order(['insurance' => ['product' => 'A-PL']]);

        $request = new BuildQuoteRequestFromOrderAction($order)->execute();

        $this->assertSame([], $request->payload);
        $this->assertNull($request->vehicle);
    }

    public function testRefusesAnOrderWithoutAProduct(): void
    {
        $this->expectException(ValidationException::class);

        new BuildQuoteRequestFromOrderAction($this->order(['insurance' => ['quote_number' => '1']]))->execute();
    }

    public function testCustomerIsLaidOverThePayload(): void
    {
        $order = $this->order([
            'insurance' => [
                'product' => 'A-PA',
                'payload' => ['tipo' => 'APA', 'cliente' => ['nombre' => 'old']],
                'cliente' => ['nombre' => 'Ana', 'numeroDocumento' => '00100000001'],
            ],
        ]);

        $request = new BuildQuoteRequestFromOrderAction($order)->execute();

        $this->assertSame('APA', $request->payload['tipo']);
        $this->assertSame(['nombre' => 'Ana', 'numeroDocumento' => '00100000001'], $request->payload['cliente']);
    }
}
