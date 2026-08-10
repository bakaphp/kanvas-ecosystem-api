<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Actions\CreateSampleOrderAction;
use Tests\TestCase;

class OrderItemDeletedVariantTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'inventory', 'crm'];

    public function test_order_item_variant_still_resolves_after_variant_is_soft_deleted(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new InventorySetup($app, $user, $company)->run();

        $product = new CreateProductAction(
            new ProductDto(
                app: $app,
                company: $company,
                user: $user,
                name: 'Deleted Variant Order ' . uniqid(),
            ),
            $user,
        )->execute();

        /** @var Variants $variant */
        $variant = $product->variants()->where('is_deleted', 0)->firstOrFail();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create(['firstname' => 'Deleted', 'lastname' => 'Variant']);

        $region = Regions::fromApp($app)->fromCompany($company)->notDeleted()->firstOrFail();
        $currency = Currencies::getByCode('USD');

        $order = new CreateSampleOrderAction(
            app: $app,
            company: $company,
            user: $user,
            region: $region,
            people: $people,
            currency: $currency,
            lines: [['variant' => $variant, 'quantity' => 1]],
            note: 'Order with a to-be-deleted variant',
        )->execute();

        $item = $order->items()->firstOrFail();
        $this->assertSame((int) $variant->getId(), (int) $item->variant_id);

        $variant->is_deleted = 1;
        $variant->saveOrFail();

        // The global SoftDeletingScope hides the variant from ordinary queries...
        $this->assertNull(Variants::query()->find($variant->getId()));

        // ...but the order line's relation uses withTrashed(), so the historical
        // variant still resolves instead of returning null (which 500s the query).
        $item->refresh();
        $this->assertNotNull($item->variant, 'Soft-deleted variant must still resolve on the order line.');
        $this->assertSame((int) $variant->getId(), (int) $item->variant->getId());
    }
}
