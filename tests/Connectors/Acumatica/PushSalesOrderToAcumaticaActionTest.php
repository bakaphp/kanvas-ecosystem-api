<?php

declare(strict_types=1);

namespace Tests\Connectors\Acumatica;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Acumatica\Actions\PushSalesOrderToAcumaticaAction;
use Kanvas\Connectors\Acumatica\Enums\ConfigurationEnum;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use Mockery;
use Tests\TestCase;

class PushSalesOrderToAcumaticaActionTest extends TestCase
{
    use DatabaseTransactions;

    private function app(): Apps
    {
        return app(Apps::class);
    }

    private function person(): People
    {
        $user = auth()->user();

        return People::factory()
            ->withAppId($this->app()->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->create(['firstname' => 'Jane', 'lastname' => 'Buyer']);
    }

    private function orderWithItem(People $people): Order
    {
        $app = $this->app();
        $user = auth()->user();

        $order = Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->withUserId($user->getId())
            ->withPeopleId($people->getId())
            ->create(['user_email' => 'jane@buyer.test']);

        $item = new OrderItem();
        $item->apps_id = $app->getId();
        $item->order_id = $order->id;
        $item->variant_id = 1;
        $item->variant_name = 'Kraken Elite 360';
        $item->product_name = 'Kraken Elite 360';
        $item->product_sku = 'RL-KP336';
        $item->quantity = 2;
        $item->unit_price_net_amount = 0;
        $item->unit_price_gross_amount = 150.35;
        $item->quantity_fulfilled = 0;
        $item->currency = 'USD';
        $item->is_public = 1;
        $item->save();

        return $order->refresh();
    }

    public function test_pushes_order_with_customer_and_lines_and_stores_reference(): void
    {
        $people = $this->person();
        $people->set(CustomFieldEnum::CUSTOMER_ID->value, 'C0001');
        $order = $this->orderWithItem($people);

        $captured = null;
        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldReceive('push')->once()->andReturnUsing(
            function (string $entity, array $body, bool $release = false, array $files = [], ?array $findQuery = null) use (&$captured): array {
                $captured = [$entity, $body];

                return ['id' => 'SO-1', 'OrderNbr' => ['value' => 'SO000123']];
            }
        );

        $ref = new PushSalesOrderToAcumaticaAction($this->app(), $order, $writer)->execute();

        $this->assertSame('SO000123', $ref);
        $this->assertSame('SO-1', $order->get(CustomFieldEnum::ORDER_ID->value));
        $this->assertSame('SO000123', (string) $order->get(CustomFieldEnum::ORDER_REF->value));

        [$entity, $body] = $captured;
        $this->assertSame('SalesOrder', $entity);
        $this->assertSame(['value' => 'C0001'], $body['CustomerID']);
        $this->assertSame(['value' => 'RL-KP336'], $body['Details'][0]['InventoryID']);
        $this->assertSame(['value' => 2.0], $body['Details'][0]['OrderQty']);
    }

    public function test_creates_customer_when_person_has_no_code_then_pushes_with_it(): void
    {
        $people = $this->person(); // no CUSTOMER_ID
        $order = $this->orderWithItem($people);

        $captured = null;
        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldReceive('findOrCreate')->once()
            ->andReturn(['id' => 'GUID-C', 'CustomerID' => ['value' => 'C7777']]);
        $writer->shouldReceive('push')->once()->andReturnUsing(
            function (string $entity, array $body, bool $release = false, array $files = [], ?array $findQuery = null) use (&$captured): array {
                $captured = $body;

                return ['id' => 'SO-9', 'OrderNbr' => ['value' => 'SO000999']];
            }
        );

        $ref = new PushSalesOrderToAcumaticaAction($this->app(), $order, $writer)->execute();

        $this->assertSame('SO000999', $ref);
        $this->assertSame(['value' => 'C7777'], $captured['CustomerID']);
        $this->assertSame('C7777', (string) $people->fresh()->get(CustomFieldEnum::CUSTOMER_ID->value));
    }

    public function test_injects_configured_custom_fields_into_so_payload(): void
    {
        $app = $this->app();
        $app->set(ConfigurationEnum::SO_CUSTOM_FIELDS->value, [
            'UsrSOETA' => ['days' => 7],
            'UsrOrderReadyDate' => 2,
            'UsrNote' => ['value' => 'kanvas'],
        ]);

        try {
            $people = $this->person();
            $people->set(CustomFieldEnum::CUSTOMER_ID->value, 'C0001');
            $order = $this->orderWithItem($people);

            $captured = null;
            $writer = Mockery::mock(AcumaticaWriteService::class);
            $writer->shouldReceive('push')->once()->andReturnUsing(
                function (string $entity, array $body, bool $release = false, array $files = [], ?array $findQuery = null) use (&$captured): array {
                    $captured = $body;

                    return ['id' => 'SO-1', 'OrderNbr' => ['value' => 'SO000123']];
                }
            );

            new PushSalesOrderToAcumaticaAction($app, $order, $writer)->execute();

            $this->assertArrayHasKey('custom', $captured);
            $doc = $captured['custom']['Document'];

            $this->assertSame('DateTime', $doc['UsrSOETA']['type']);
            $this->assertSame(
                $order->created_at->copy()->addDays(7)->format('Y-m-d\T00:00:00'),
                $doc['UsrSOETA']['value']
            );

            // bare-int shorthand → order date + N days
            $this->assertSame('DateTime', $doc['UsrOrderReadyDate']['type']);
            $this->assertSame(
                $order->created_at->copy()->addDays(2)->format('Y-m-d\T00:00:00'),
                $doc['UsrOrderReadyDate']['value']
            );

            $this->assertSame(['type' => 'String', 'value' => 'kanvas'], $doc['UsrNote']);
        } finally {
            $app->set(ConfigurationEnum::SO_CUSTOM_FIELDS->value, []);
        }
    }

    public function test_is_idempotent_when_already_pushed(): void
    {
        $people = $this->person();
        $order = $this->orderWithItem($people);
        $order->set(CustomFieldEnum::ORDER_ID->value, 'ALREADY-THERE');

        $writer = Mockery::mock(AcumaticaWriteService::class);
        $writer->shouldNotReceive('push');

        $ref = new PushSalesOrderToAcumaticaAction($this->app(), $order, $writer)->execute();

        $this->assertSame('ALREADY-THERE', $ref);
    }
}
