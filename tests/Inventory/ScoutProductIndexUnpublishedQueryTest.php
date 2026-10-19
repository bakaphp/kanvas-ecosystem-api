<?php

declare(strict_types=1);

namespace Tests\Inventory;

use App\Console\Commands\Search\ScoutProductIndexProcessCommand;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class ScoutProductIndexUnpublishedQueryTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem'];

    private Apps $kanvasApp;
    private Users $kanvasUser;
    private Companies $company;
    private Channels $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $this->kanvasUser = $user;
        $this->company = $user->getCurrentCompany();

        new InventorySetup($this->kanvasApp, $user, $this->company)->run();

        $this->channel = Channels::getDefault($this->company, $this->kanvasApp);
    }

    public function testProductWithNoVariantsIsTreatedAsUnpublished(): void
    {
        /** @var Products $product */
        $product = Products::factory()->make(['companies_id' => $this->company->getId()]);
        $product->save();

        $this->assertSame(0, $product->variants()->count(), 'Test setup should produce a variant-less product.');

        $this->assertContains($product->getId(), $this->matchedIds());
    }

    public function testProductWithOnlyDeletedVariantsIsTreatedAsUnpublished(): void
    {
        $product = $this->createProduct('ScoutDeletedVariants');
        // VariantObserver refuses to delete a product's last variant, so this state only arises from direct writes
        Variants::where('products_id', $product->getId())->update(['is_deleted' => 1]);

        $this->assertContains($product->getId(), $this->matchedIds());
    }

    public function testProductWithUnpublishedVariantIsTreatedAsUnpublished(): void
    {
        $product = $this->createProduct('ScoutUnpublishedVariant');
        $this->publishOnChannel($product, isPublished: false);

        $this->assertContains($product->getId(), $this->matchedIds());
    }

    public function testProductWithPublishedVariantStaysIndexed(): void
    {
        $product = $this->createProduct('ScoutPublishedVariant');
        $this->publishOnChannel($product, isPublished: true);

        $this->assertNotContains($product->getId(), $this->matchedIds());
    }

    private function matchedIds(): array
    {
        return new ScoutProductIndexProcessCommand()
            ->productsWithoutPublishedVariantsQuery($this->kanvasApp, $this->company, $this->channel)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function publishOnChannel(Products $product, bool $isPublished): void
    {
        /** @var Variants $variant */
        $variant = $product->variants()->firstOrFail();
        $warehouse = Warehouses::fromApp($this->kanvasApp)->fromCompany($this->company)->firstOrFail();

        $variantWarehouse = VariantsWarehouses::updateOrCreate(
            [
                'products_variants_id' => $variant->getId(),
                'warehouses_id' => $warehouse->getId(),
            ],
            [
                'quantity' => 0,
                'price' => 1.00,
                'sku' => $variant->sku ?? 'ScoutSku-' . uniqid(),
                'position' => 1,
                'is_default' => 1,
            ]
        );

        VariantsChannels::where('products_variants_id', $variant->getId())
            ->where('channels_id', $this->channel->getId())
            ->delete();

        new VariantsChannels([
            'product_variants_warehouse_id' => $variantWarehouse->getId(),
            'channels_id' => $this->channel->getId(),
            'products_variants_id' => $variant->getId(),
            'warehouses_id' => $warehouse->getId(),
            'price' => 10.00,
            'discounted_price' => 9.00,
            'is_published' => $isPublished,
        ])->save();
    }

    private function createProduct(string $name): Products
    {
        return new CreateProductAction(
            new ProductDto(
                app: $this->kanvasApp,
                company: $this->company,
                user: $this->kanvasUser,
                name: $name . '-' . uniqid(),
            ),
            $this->kanvasUser
        )->execute();
    }
}
