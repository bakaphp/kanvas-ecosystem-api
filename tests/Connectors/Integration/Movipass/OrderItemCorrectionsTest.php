<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\Corrections\AddOrderItemAction;
use Kanvas\Connectors\Movipass\Actions\Corrections\RemoveOrderItemAction;
use Kanvas\Connectors\Movipass\Actions\Corrections\UpdateOrderItemAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use Tests\TestCase;

final class OrderItemCorrectionsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass integration tests are skipped in CI');
        }
    }

    private function createOrderWithItems(float $servicePrice = 4000.00): array
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $region = Regions::getDefault($company, $app);

        $person = People::withoutSyncingToSearch(
            fn () => People::factory()
                ->withAppId($app->getId())
                ->withCompanyId($company->getId())
                ->create()
        );

        $locationType = ProductsTypes::factory()
            ->company($company->getId())
            ->create(['slug' => 'impound-lot', 'name' => 'impound_lot']);

        $serviceType = ProductsTypes::factory()
            ->company($company->getId())
            ->create(['slug' => 'services', 'name' => 'services']);

        $locationProduct = Variants::withoutSyncingToSearch(
            fn () => Products::withoutSyncingToSearch(
                fn () => Products::factory()
                    ->withAppId($app->getId())
                    ->withCompanyId($company->getId())
                    ->create(['products_types_id' => $locationType->id])
            )
        );

        $serviceProduct = Variants::withoutSyncingToSearch(
            fn () => Products::withoutSyncingToSearch(
                fn () => Products::factory()
                    ->withAppId($app->getId())
                    ->withCompanyId($company->getId())
                    ->create(['products_types_id' => $serviceType->id])
            )
        );

        $locationVariant = $locationProduct->variants()->first();
        $serviceVariant = $serviceProduct->variants()->first();

        $order = Order::withoutSyncingToSearch(
            fn () => Order::factory()
                ->withAppId($app->getId())
                ->withCompanyId($company->getId())
                ->withUserId($user->getId())
                ->create(['region_id' => $region->getId(), 'people_id' => $person->id])
        );

        $locationItem = $order->allItems()->create([
            'apps_id' => $app->getId(),
            'product_name' => $locationVariant->product->name,
            'product_sku' => $locationVariant->sku,
            'quantity' => 1,
            'unit_price_net_amount' => 0,
            'unit_price_gross_amount' => 0,
            'is_shipping_required' => false,
            'quantity_fulfilled' => 0,
            'variant_id' => $locationVariant->id,
            'variant_name' => $locationVariant->name,
            'tax_rate' => 0,
            'currency' => 'DOP',
            'is_public' => 1,
            'is_deleted' => 0,
        ]);

        $serviceItem = $order->allItems()->create([
            'apps_id' => $app->getId(),
            'product_name' => $serviceVariant->product->name,
            'product_sku' => $serviceVariant->sku,
            'quantity' => 1,
            'unit_price_net_amount' => $servicePrice,
            'unit_price_gross_amount' => $servicePrice,
            'is_shipping_required' => false,
            'quantity_fulfilled' => 0,
            'variant_id' => $serviceVariant->id,
            'variant_name' => $serviceVariant->name,
            'tax_rate' => 0,
            'currency' => 'DOP',
            'is_public' => 1,
            'is_deleted' => 0,
        ]);

        Order::withoutSyncingToSearch(fn () => $order->calculateTotal());

        return [$order, $user, $locationItem, $serviceItem, $serviceVariant];
    }

    public function testUpdateItemChangesAmountAndRecalculatesTotal(): void
    {
        [$order, $user, , $serviceItem] = $this->createOrderWithItems(4000.00);

        $result = Order::withoutSyncingToSearch(
            fn () => new UpdateOrderItemAction(
                $order,
                $user,
                (int) $serviceItem->id,
                'Ajuste de tarifa',
                newAmount: 4400.00,
            )->execute()
        );

        $this->assertEquals(4400.00, (float) $result->total_net_amount);
        $this->assertEquals(4400.00, (float) $serviceItem->fresh()->unit_price_net_amount);
    }

    public function testUpdateItemChangesQuantity(): void
    {
        [$order, $user, , $serviceItem] = $this->createOrderWithItems(1000.00);

        $result = Order::withoutSyncingToSearch(
            fn () => new UpdateOrderItemAction(
                $order,
                $user,
                (int) $serviceItem->id,
                'Cambio de cantidad',
                newQuantity: 3,
            )->execute()
        );

        $this->assertEquals(3, (int) $serviceItem->fresh()->quantity);
        $this->assertEquals(3000.00, (float) $result->total_net_amount);
    }

    public function testAddItemAppendsLineAndRecalculatesTotal(): void
    {
        [$order, $user, , , $serviceVariant] = $this->createOrderWithItems(4000.00);

        $result = Order::withoutSyncingToSearch(
            fn () => new AddOrderItemAction(
                $order,
                $user,
                (int) $serviceVariant->id,
                1,
                400.00,
                'Recargo por mora',
            )->execute()
        );

        $this->assertEquals(4400.00, (float) $result->total_net_amount);
        $this->assertEquals(3, $order->allItems()->count());
    }

    public function testRemoveItemDeletesLineAndRecalculatesTotal(): void
    {
        [$order, $user, , $serviceItem] = $this->createOrderWithItems(4000.00);

        $result = Order::withoutSyncingToSearch(
            fn () => new RemoveOrderItemAction(
                $order,
                $user,
                (int) $serviceItem->id,
                'Servicio no aplicaba',
            )->execute()
        );

        $this->assertEquals(0.0, (float) $result->total_net_amount);
        $this->assertNull(OrderItem::find($serviceItem->id));
        $this->assertEquals(1, $order->allItems()->count());
    }

    public function testRemoveLocationItemIsBlocked(): void
    {
        [$order, $user, $locationItem] = $this->createOrderWithItems(4000.00);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('The location item cannot be removed from the order');

        Order::withoutSyncingToSearch(
            fn () => new RemoveOrderItemAction(
                $order,
                $user,
                (int) $locationItem->id,
                'Intento de quitar location',
            )->execute()
        );
    }
}
