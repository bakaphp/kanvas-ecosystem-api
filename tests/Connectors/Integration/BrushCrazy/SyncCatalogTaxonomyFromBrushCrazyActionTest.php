<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\BrushCrazy;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\BrushCrazy\Actions\SyncCatalogTaxonomyFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Actions\SyncCommerceCatalogFromBrushCrazyAction;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Enums\SaleLineKindEnum;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Tests\TestCase;

final class SyncCatalogTaxonomyFromBrushCrazyActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem', 'inventory'];

    private StudioContext $context;

    private int $id;

    protected function setUp(): void
    {
        parent::setUp();

        config(['scout.driver' => 'null', 'scout.queue' => false]);

        // Custom fields also land in Redis, which never rolls back: fresh source ids per test.
        $this->id = random_int(10_000_000, 99_999_999);

        $company = auth()->user()->getCurrentCompany();

        $this->context = new StudioContext(
            bcStudioId: 2,
            company: $company,
            branch: $company->defaultBranch,
            themeArea: new ThemeArea(),
            timezone: 'America/New_York',
        );
    }

    public function testCategoryHangsUnderItsTypeAndGroupUnderTheGroupsRoot(): void
    {
        $this->taxonomy()->syncRows(
            [$this->row($this->id, 'Retail')],
            [$this->row($this->id + 1, 'Canvases', ['type_id' => $this->id, 'account_id' => 1, 'transferable' => 1])],
            [$this->row($this->id + 2, 'Brushes')],
            [1 => 'Cost of Goods Sold'],
            [$this->id + 1 => [['from_cents' => 0, 'to_cents' => 1000, 'formula' => 'a * 2', 'precision' => 0.99]]],
        );

        $type = $this->category(SyncCatalogTaxonomyFromBrushCrazyAction::categoryTypeSlug($this->id));
        $category = $this->category(SyncCatalogTaxonomyFromBrushCrazyAction::categorySlug($this->id + 1));
        $group = $this->category(SyncCatalogTaxonomyFromBrushCrazyAction::groupSlug($this->id + 2));

        $this->assertSame($type->getId(), (int) $category->parent_id);
        $this->assertSame(
            $this->category(SyncCatalogTaxonomyFromBrushCrazyAction::GROUPS_ROOT_SLUG)->getId(),
            (int) $group->parent_id
        );
        $this->assertSame('Canvases', $category->name);
        $this->assertEquals(
            [
                'account' => 'Cost of Goods Sold',
                'transferable' => true,
                'formulas' => [['from_cents' => 0, 'to_cents' => 1000, 'formula' => 'a * 2', 'precision' => 0.99]],
            ],
            $category->get(CustomFieldEnum::BRUSHCRAZY_CATEGORY_SETTINGS->value)
        );
    }

    public function testReRunRenamesInPlaceAndADeletedSourceRowIsUnpublished(): void
    {
        $this->taxonomy()->syncRows([], [$this->row($this->id, 'Paints')], []);
        $first = $this->category(SyncCatalogTaxonomyFromBrushCrazyAction::categorySlug($this->id));

        $this->taxonomy()->syncRows([], [$this->row($this->id, 'Acrylic Paints', ['deleted_at' => '2024-01-01 00:00:00'])], []);
        $again = $this->category(SyncCatalogTaxonomyFromBrushCrazyAction::categorySlug($this->id));

        $this->assertSame($first->getId(), $again->getId());
        $this->assertSame('Acrylic Paints', $again->name);
        $this->assertFalse((bool) $again->is_published);
        $this->assertSame(
            1,
            Categories::withTrashed()
                ->where('companies_id', $this->context->companyId())
                ->where('slug', SyncCatalogTaxonomyFromBrushCrazyAction::categorySlug($this->id))
                ->count()
        );
    }

    public function testProductIsLinkedToItsCategoryAndGroupAndFollowsACategoryChange(): void
    {
        $taxonomy = $this->taxonomy();
        $taxonomy->syncRows(
            [],
            [$this->row($this->id, 'Canvases'), $this->row($this->id + 1, 'Frames')],
            [$this->row($this->id + 2, 'Wall Art')],
        );

        $catalog = $this->catalog($taxonomy);
        $catalog->syncProductRows([$this->product(['category_id' => $this->id, 'group_id' => $this->id + 2])]);

        $product = $this->savedProduct();
        $this->assertEqualsCanonicalizing(
            [$taxonomy->categoryIdFor($this->id), $taxonomy->groupCategoryIdFor($this->id + 2)],
            $this->liveCategoryIds($product)
        );

        $catalog->syncProductRows([$this->product(['category_id' => $this->id + 1, 'group_id' => $this->id + 2])]);

        $this->assertEqualsCanonicalizing(
            [$taxonomy->categoryIdFor($this->id + 1), $taxonomy->groupCategoryIdFor($this->id + 2)],
            $this->liveCategoryIds($product)
        );
    }

    public function testStudioStockCountIsCopiedAndKeptWhenTheStudioRowIsGone(): void
    {
        $catalog = $this->catalog($this->taxonomy());
        $catalog->syncProductRows(
            [$this->product()],
            [],
            [$this->id => (object) ['quantity' => 12, 'cost' => '{"amount":650,"currency":"USD"}']],
        );

        $variant = $catalog->variantFor($this->id, SaleLineKindEnum::MISC);
        $stock = fn () => VariantsWarehouses::where('products_variants_id', $variant->getId())
            ->where('warehouses_id', $catalog->warehouse()->getId())
            ->first();

        $this->assertSame(12, (int) $stock()->quantity);
        $this->assertEquals(6.5, (float) $stock()->cost);
        $this->assertEquals(5.0, (float) $variant->base_cost);

        $catalog->syncProductRows([$this->product()]);

        $this->assertSame(12, (int) $stock()->quantity);
    }

    public function testVariantIsPricedOnTheDefaultChannelAndGenericLinesStayHidden(): void
    {
        $catalog = $this->catalog($this->taxonomy());
        $catalog->syncProductRows([$this->product()]);

        $channel = Channels::getDefault($this->context->company, app(Apps::class));
        $this->assertNotNull($channel);

        $row = fn (int $variantId) => VariantsChannels::where('products_variants_id', $variantId)
            ->where('channels_id', $channel->getId())
            ->first();

        $priced = $row($catalog->variantFor($this->id, SaleLineKindEnum::MISC)->getId());
        $this->assertEquals(19.99, (float) $priced->price);
        $this->assertTrue((bool) $priced->is_published);

        $catalog->syncProductRows([$this->product(['msrp' => '{"amount":2499,"currency":"USD"}'])]);
        $this->assertEquals(24.99, (float) $row($catalog->variantFor($this->id, SaleLineKindEnum::MISC)->getId())->price);

        $generic = $row($catalog->genericVariant(SaleLineKindEnum::MISC)->getId());
        $this->assertFalse((bool) $generic->is_published);
        $this->assertFalse((bool) $catalog->genericVariant(SaleLineKindEnum::MISC)->product->is_published);
    }

    private function taxonomy(): SyncCatalogTaxonomyFromBrushCrazyAction
    {
        return new SyncCatalogTaxonomyFromBrushCrazyAction(app(Apps::class), auth()->user(), $this->context);
    }

    private function catalog(SyncCatalogTaxonomyFromBrushCrazyAction $taxonomy): SyncCommerceCatalogFromBrushCrazyAction
    {
        return new SyncCommerceCatalogFromBrushCrazyAction(
            app(Apps::class),
            auth()->user(),
            $this->context,
            taxonomy: $taxonomy,
        );
    }

    private function category(string $slug): Categories
    {
        return Categories::withTrashed()
            ->where('apps_id', app(Apps::class)->getId())
            ->where('companies_id', $this->context->companyId())
            ->where('slug', $slug)
            ->firstOrFail();
    }

    private function savedProduct(): Products
    {
        return Products::withTrashed()
            ->where('companies_id', $this->context->companyId())
            ->where('slug', 'bc-product-' . $this->id)
            ->firstOrFail();
    }

    /**
     * Through the relation the storefront reads, which does not filter the pivot's is_deleted.
     *
     * @return array<int, int>
     */
    private function liveCategoryIds(Products $product): array
    {
        return $product->categories()
            ->pluck('categories.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function row(int $id, string $name, array $overrides = []): object
    {
        return (object) ($overrides + [
            'id' => $id,
            'name' => $name,
            'type_id' => null,
            'account_id' => null,
            'transferable' => 0,
            'deleted_at' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function product(array $overrides = []): object
    {
        return (object) ($overrides + [
            'id' => $this->id,
            'barcode' => 'BC-' . $this->id,
            'name' => 'Canvas 11x14',
            'description' => null,
            'msrp' => '{"amount":1999,"currency":"USD"}',
            'cost' => '{"amount":500,"currency":"USD"}',
            'category_id' => null,
            'group_id' => null,
            'published_at' => '2020-01-01 00:00:00',
            'discontinued_at' => null,
            'deleted_at' => null,
            'created_at' => '2020-01-01 00:00:00',
        ]);
    }
}
