<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Kanvas\Activities\Models\Activity;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\Corrections\AdjustOrderItemAmountAction;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class AdjustOrderItemAmountActionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass integration tests are skipped in CI');
        }
    }

    private function createOrderWithItems(
        Apps $app,
        Users $user,
        float $servicePrice,
        array $orderOverrides = []
    ): Order {
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
            ->create(['slug' => 'impound_lot', 'name' => 'impound_lot']);

        $serviceType = ProductsTypes::factory()
            ->company($company->getId())
            ->create(['slug' => 'grua-deposito', 'name' => 'services']);

        $locationProduct = Variants::withoutSyncingToSearch(
            fn () => Products::withoutSyncingToSearch(
                fn () => Products::factory()
                    ->withAppId($app->getId())
                    ->withCompanyId($company->getId())
                    ->create(['products_types_id' => $locationType->id, 'is_deleted' => 0, 'is_published' => 1])
            )
        );

        $serviceProduct = Variants::withoutSyncingToSearch(
            fn () => Products::withoutSyncingToSearch(
                fn () => Products::factory()
                    ->withAppId($app->getId())
                    ->withCompanyId($company->getId())
                    ->create(['products_types_id' => $serviceType->id, 'is_deleted' => 0, 'is_published' => 1])
            )
        );

        $locationVariant = $locationProduct->variants()->first();
        $serviceVariant = $serviceProduct->variants()->first();

        $order = Order::withoutSyncingToSearch(
            fn () => Order::factory()
                ->withAppId($app->getId())
                ->withCompanyId($company->getId())
                ->withUserId($user->getId())
                ->create(array_merge([
                    'region_id' => $region->getId(),
                    'people_id' => $person->id,
                ], $orderOverrides))
        );

        $order->allItems()->create([
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

        $order->allItems()->create([
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

        return $order;
    }

    public function testItAdjustsServiceItemAmountAndRecalculatesTotal(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();

        $order = $this->createOrderWithItems($app, $user, 4000.00);
        Order::withoutSyncingToSearch(fn () => $order->calculateTotal());

        $this->assertEquals(4000.00, (float) $order->total_net_amount);

        $result = Order::withoutSyncingToSearch(
            fn () => new AdjustOrderItemAmountAction($order, $user, 4400.00, 'Ajuste de tarifa aplicado')->execute()
        );

        $this->assertEquals(4400.00, (float) $result->total_net_amount);
        $this->assertEquals(4400.00, (float) $result->total_gross_amount);

        $log = Activity::where('subject_id', $order->id)
            ->where('subject_type', Order::class)
            ->where('description', 'adjust-amount')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals(4000.00, $log->properties['changes']['amount']['old']);
        $this->assertEquals(4400.00, $log->properties['changes']['amount']['new']);
        $this->assertEquals('Ajuste de tarifa aplicado', $log->properties['reason']);
    }

    public function testItLeavesLocationItemUntouched(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();

        $order = $this->createOrderWithItems($app, $user, 4000.00);

        Order::withoutSyncingToSearch(
            fn () => new AdjustOrderItemAmountAction($order, $user, 4400.00, 'Ajuste de monto')->execute()
        );

        $items = $order->allItems()->with('variant.product.productType')->get();

        $locationItem = $items->first(fn ($i) => $i->variant?->product?->productType?->name === 'impound_lot');
        $serviceItem = $items->first(fn ($i) => $i->variant?->product?->productType?->name == 'services');

        $this->assertEquals(0.0, (float) $locationItem->unit_price_net_amount);
        $this->assertEquals(4400.0, (float) $serviceItem->unit_price_net_amount);
    }

    public function testItRejectsZeroAmount(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();

        $order = $this->createOrderWithItems($app, $user, 4000.00);

        $this->expectException(ValidationException::class);

        Order::withoutSyncingToSearch(
            fn () => new AdjustOrderItemAmountAction($order, $user, 0.0, 'should fail')->execute()
        );
    }

    public function testItBlocksAdjustmentOnFinalStatus(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();

        $orderType = OrderTypes::where('name', 'impound_lot')->fromApp($app)->first();

        if (! $orderType) {
            $this->markTestSkipped('impound_lot order type not set up in this environment');
        }

        $releasedStatus = OrderStatus::where('slug', MovipassOrderStatusEnum::RELEASED->value)
            ->where('order_types_id', $orderType->id)
            ->first();

        if (! $releasedStatus) {
            $this->markTestSkipped('released_from_lot status not found — run movipass:setup-impound-lot first');
        }

        $order = $this->createOrderWithItems($app, $user, 4000.00, [
            'order_status_id' => $releasedStatus->id,
        ]);

        $this->expectException(ValidationException::class);

        Order::withoutSyncingToSearch(
            fn () => new AdjustOrderItemAmountAction($order, $user, 4400.00, 'should fail')->execute()
        );
    }
}
