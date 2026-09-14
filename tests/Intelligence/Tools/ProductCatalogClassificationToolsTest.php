<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\AttributeSearchTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\CategorySearchTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\CreateProductTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\ListChannelsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\ListProductTypesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\ListWarehousesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\SetProductAttributesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\SetProductCategoriesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\SetVariantAttributesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\SetVariantChannelPriceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\SetVariantChannelStatusTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\SetVariantStockTool;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Tests\TestCase;

/**
 * Covers the reference-lookup tools and the classification/pricing writes that hang off them. No
 * DatabaseTransactions, for the CreateProductAction retry reason spelled out in
 * ProductCatalogCrudToolsTest.
 */
class ProductCatalogClassificationToolsTest extends TestCase
{
    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);

        $company = auth()->user()->getCurrentCompany();

        if (Warehouses::getDefault($company, $this->kanvasApp) === null) {
            new InventorySetup($this->kanvasApp, auth()->user(), $company)->run();
        }
    }

    private function withoutSearch(callable $callback): void
    {
        Products::withoutSyncingToSearch(fn () => Variants::withoutSyncingToSearch($callback));
    }

    private function tool(object $tool): object
    {
        return $tool->withContext($this->kanvasApp, auth()->user()->getCurrentCompany(), auth()->user());
    }

    private function product(): Products
    {
        return Products::factory()
            ->company(auth()->user()->getCurrentCompany()->getId())
            ->create();
    }

    public function testListWarehousesSurfacesTheDefaultFirst(): void
    {
        $result = $this->tool(new ListWarehousesTool())->__invoke();

        $this->assertGreaterThan(0, $result['total']);
        $this->assertTrue($result['warehouses'][0]['is_default'], 'The default warehouse must lead the list');
    }

    public function testListChannelsSurfacesTheDefaultFirst(): void
    {
        $result = $this->tool(new ListChannelsTool())->__invoke();

        $this->assertGreaterThan(0, $result['total']);
        $this->assertTrue($result['channels'][0]['is_default'], 'The default channel must lead the list');
    }

    public function testListProductTypesReturnsRows(): void
    {
        $result = $this->tool(new ListProductTypesTool())->__invoke();

        $this->assertArrayHasKey('product_types', $result);
        $this->assertSame(count($result['product_types']), $result['showing']);
    }

    /**
     * A truncated page that does not say so reads to the model as the whole catalog, which is how
     * an agent concludes a category "does not exist" on a tenant with thousands of them.
     */
    public function testAReferenceListSaysWhenItIsTruncated(): void
    {
        $company = auth()->user()->getCurrentCompany();

        for ($i = 0; $i < 3; $i++) {
            Categories::create([
                'apps_id' => $this->kanvasApp->getId(),
                'companies_id' => $company->getId(),
                'users_id' => auth()->user()->getId(),
                'name' => 'AgentCatalogTest ' . fake()->unique()->uuid(),
                'slug' => 'agent-catalog-test-' . fake()->unique()->uuid(),
            ]);
        }

        $result = $this->tool(new CategorySearchTool())->__invoke(keyword: 'AgentCatalogTest', limit: 2);

        $this->assertSame(2, $result['showing']);
        $this->assertGreaterThan(2, $result['total']);
        $this->assertStringContainsString('Showing 2 of', $result['message']);
    }

    public function testCategorySearchFiltersByKeyword(): void
    {
        $company = auth()->user()->getCurrentCompany();
        $unique = 'AgentOnlyCat' . fake()->unique()->uuid();

        Categories::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $company->getId(),
            'users_id' => auth()->user()->getId(),
            'name' => $unique,
            'slug' => strtolower($unique),
        ]);

        $result = $this->tool(new CategorySearchTool())->__invoke(keyword: $unique);

        $this->assertSame(1, $result['total']);
        $this->assertSame($unique, $result['categories'][0]['name']);
    }

    public function testAttributeSearchWithNoMatchSaysSo(): void
    {
        $result = $this->tool(new AttributeSearchTool())
            ->__invoke(keyword: 'zzz-no-such-attribute-' . fake()->unique()->uuid());

        $this->assertSame(0, $result['total']);
        $this->assertStringContainsString('No attributes found', $result['message']);
    }

    public function testSetProductCategoriesAddsWithoutDiscardingExistingOnes(): void
    {
        $this->withoutSearch(function (): void {
            $company = auth()->user()->getCurrentCompany();
            $product = $this->product();

            $first = Categories::create([
                'apps_id' => $this->kanvasApp->getId(),
                'companies_id' => $company->getId(),
                'users_id' => auth()->user()->getId(),
                'name' => 'AgentCatA' . fake()->unique()->uuid(),
                'slug' => 'agent-cat-a-' . fake()->unique()->uuid(),
            ]);

            $second = Categories::create([
                'apps_id' => $this->kanvasApp->getId(),
                'companies_id' => $company->getId(),
                'users_id' => auth()->user()->getId(),
                'name' => 'AgentCatB' . fake()->unique()->uuid(),
                'slug' => 'agent-cat-b-' . fake()->unique()->uuid(),
            ]);

            $this->tool(new SetProductCategoriesTool())
                ->__invoke(product_id: $product->getId(), category_ids: [$first->getId()]);

            $result = $this->tool(new SetProductCategoriesTool())
                ->__invoke(product_id: $product->getId(), category_ids: [$second->getId()]);

            $this->assertTrue($result['updated']);
            $this->assertContains($first->name, $result['categories'], 'A plain call must add, never replace');
            $this->assertContains($second->name, $result['categories']);
        });
    }

    public function testSetProductCategoriesWithReplaceDiscardsTheOldOnes(): void
    {
        $this->withoutSearch(function (): void {
            $company = auth()->user()->getCurrentCompany();
            $product = $this->product();

            $first = Categories::create([
                'apps_id' => $this->kanvasApp->getId(),
                'companies_id' => $company->getId(),
                'users_id' => auth()->user()->getId(),
                'name' => 'AgentCatC' . fake()->unique()->uuid(),
                'slug' => 'agent-cat-c-' . fake()->unique()->uuid(),
            ]);

            $second = Categories::create([
                'apps_id' => $this->kanvasApp->getId(),
                'companies_id' => $company->getId(),
                'users_id' => auth()->user()->getId(),
                'name' => 'AgentCatD' . fake()->unique()->uuid(),
                'slug' => 'agent-cat-d-' . fake()->unique()->uuid(),
            ]);

            $this->tool(new SetProductCategoriesTool())
                ->__invoke(product_id: $product->getId(), category_ids: [$first->getId()]);

            $result = $this->tool(new SetProductCategoriesTool())
                ->__invoke(product_id: $product->getId(), category_ids: [$second->getId()], replace: true);

            $this->assertTrue($result['updated']);
            $this->assertSame([$second->name], $result['categories']);
        });
    }

    public function testSetProductCategoriesRejectsAHallucinatedCategory(): void
    {
        $this->withoutSearch(function (): void {
            $result = $this->tool(new SetProductCategoriesTool())
                ->__invoke(product_id: $this->product()->getId(), category_ids: [999999999]);

            $this->assertSame('error', $result['status']);
            $this->assertStringContainsString('category_search', $result['message']);
        });
    }

    public function testSetProductAttributesCreatesAttributesByName(): void
    {
        $this->withoutSearch(function (): void {
            $product = $this->product();
            $name = 'AgentSpec' . fake()->unique()->uuid();

            $result = $this->tool(new SetProductAttributesTool())->__invoke(
                product_id: $product->getId(),
                attributes: json_encode([$name => 'Cotton']),
            );

            $this->assertTrue($result['updated']);
            $this->assertSame([$name], $result['attributes_set']);
            $this->assertGreaterThan(0, $product->refresh()->attributeValues()->count());
        });
    }

    public function testSetVariantAttributesAcceptsTheListShapeToo(): void
    {
        $this->withoutSearch(function (): void {
            $variant = $this->product()->variants()->first();
            $name = 'AgentSize' . fake()->unique()->uuid();

            $result = $this->tool(new SetVariantAttributesTool())->__invoke(
                variant_id: $variant->getId(),
                attributes: [['name' => $name, 'value' => 'XL']],
            );

            $this->assertTrue($result['updated']);
            $this->assertSame([$name], $result['attributes_set']);
        });
    }

    public function testSetProductAttributesWithAnEmptyPayloadIsRefused(): void
    {
        $this->withoutSearch(function (): void {
            $result = $this->tool(new SetProductAttributesTool())
                ->__invoke(product_id: $this->product()->getId(), attributes: '{}');

            $this->assertFalse($result['updated']);
            $this->assertSame('error', $result['status']);
        });
    }

    /**
     * The gap this batch was written for: a variant priced only through set_variant_stock had no
     * variants_channels row, and AddToCartAction firstOrFail()s on exactly that row.
     */
    public function testSettingStockSeedsTheDefaultChannelPriceSoTheVariantIsSellable(): void
    {
        $this->withoutSearch(function (): void {
            $result = $this->tool(new CreateProductTool())->__invoke(
                name: 'Agent Sellable ' . fake()->unique()->uuid(),
                sku: 'agent-' . fake()->unique()->uuid(),
                price: 42.0,
                quantity: 5,
            );

            $this->assertTrue($result['created']);
            $this->assertTrue($result['stock']['channel']['seeded']);
            $this->assertSame(42.0, $result['stock']['channel']['selling_price']);

            $variant = Variants::getById($result['variants'][0]['variant_id']);
            $this->assertSame(42.0, (float) $variant->getPriceInfoFromDefaultChannel()->price);
        });
    }

    public function testSeedingNeverOverwritesAnExistingChannelPrice(): void
    {
        $this->withoutSearch(function (): void {
            $variant = $this->product()->variants()->first();

            $this->tool(new SetVariantStockTool())
                ->__invoke(variant_id: $variant->getId(), price: 10.0, quantity: 1);

            $this->tool(new SetVariantChannelPriceTool())
                ->__invoke(variant_id: $variant->getId(), price: 99.0);

            $result = $this->tool(new SetVariantStockTool())
                ->__invoke(variant_id: $variant->getId(), price: 15.0);

            $this->assertFalse($result['channel']['seeded']);
            $this->assertSame(99.0, (float) $variant->getPriceInfoFromDefaultChannel()->price);
        });
    }

    /**
     * The seed guard has to ask about the DEFAULT channel, not about any channel. A variant priced on
     * a wholesale/B2B channel still has nothing the cart can read, and skipping the seed there leaves
     * exactly the unbuyable variant the seeding exists to prevent.
     */
    public function testAPriceOnANonDefaultChannelDoesNotSuppressTheDefaultChannelSeed(): void
    {
        $this->withoutSearch(function (): void {
            $company = auth()->user()->getCurrentCompany();
            $variant = $this->product()->variants()->first();

            $wholesale = Channels::create([
                'apps_id' => $this->kanvasApp->getId(),
                'companies_id' => $company->getId(),
                'users_id' => auth()->user()->getId(),
                'name' => 'AgentWholesale' . fake()->unique()->uuid(),
                'slug' => 'agent-wholesale-' . fake()->unique()->uuid(),
                'is_published' => 1,
                'is_default' => 0,
            ]);

            $this->tool(new SetVariantStockTool())
                ->__invoke(variant_id: $variant->getId(), quantity: 4);

            $this->tool(new SetVariantChannelPriceTool())
                ->__invoke(variant_id: $variant->getId(), price: 200.0, channel_id: $wholesale->getId());

            $result = $this->tool(new SetVariantStockTool())
                ->__invoke(variant_id: $variant->getId(), price: 30.0);

            $this->assertTrue($result['channel']['seeded'], 'A wholesale price must not block the default seed');
            $this->assertSame(30.0, (float) $variant->getPriceInfoFromDefaultChannel()->price);
        });
    }

    public function testSetVariantChannelPriceUpdatesOnlyWhatItIsGiven(): void
    {
        $this->withoutSearch(function (): void {
            $variant = $this->product()->variants()->first();

            $this->tool(new SetVariantStockTool())
                ->__invoke(variant_id: $variant->getId(), price: 20.0, quantity: 2);

            $seeded = VariantsChannels::where('products_variants_id', $variant->getId())->first();

            $result = $this->tool(new SetVariantChannelPriceTool())
                ->__invoke(variant_id: $variant->getId(), discounted_price: 12.5);

            $this->assertTrue($result['updated']);
            $this->assertSame(12.5, $result['discounted_price']);
            $this->assertSame(20.0, $result['price'], 'An omitted list price must keep its current value');
            $this->assertSame(
                (bool) $seeded->is_published,
                $result['is_published'],
                'An omitted is_published must not flip the channel listing'
            );
        });
    }

    public function testSetVariantChannelPriceTellsTheModelToStockItFirst(): void
    {
        $this->withoutSearch(function (): void {
            $variant = $this->product()->variants()->first();

            $result = $this->tool(new SetVariantChannelPriceTool())
                ->__invoke(variant_id: $variant->getId(), price: 5.0);

            $this->assertFalse($result['updated']);
            $this->assertStringContainsString('set_variant_stock', $result['message']);
        });
    }

    public function testSeedingADraftProductDoesNotPublishIt(): void
    {
        $this->withoutSearch(function (): void {
            $result = $this->tool(new CreateProductTool())->__invoke(
                name: 'Agent Draft ' . fake()->unique()->uuid(),
                sku: 'agent-' . fake()->unique()->uuid(),
                price: 7.5,
            );

            $this->assertTrue($result['stock']['channel']['seeded']);
            $this->assertFalse(
                (bool) Products::getById($result['product_id'])->is_published,
                'Seeding a channel price must not flip a deliberate draft live'
            );
        });
    }

    public function testChannelStatusDeactivatesAndReactivatesKeepingThePrice(): void
    {
        $this->withoutSearch(function (): void {
            $variant = $this->product()->variants()->first();

            // The seeded listing copies the product's published state, and a factory product is a
            // draft — so the listing starts inactive and the round trip begins by switching it on.
            $this->tool(new SetVariantStockTool())
                ->__invoke(variant_id: $variant->getId(), price: 55.0, quantity: 3);

            $on = $this->tool(new SetVariantChannelStatusTool())
                ->__invoke(variant_id: $variant->getId(), is_published: true);

            $this->assertTrue($on['updated']);
            $this->assertTrue($on['is_published']);

            $off = $this->tool(new SetVariantChannelStatusTool())
                ->__invoke(variant_id: $variant->getId(), is_published: false);

            $this->assertTrue($off['updated']);
            $this->assertFalse($off['is_published']);
            $this->assertSame(
                55.0,
                (float) $variant->getPriceInfoFromDefaultChannel()->price,
                'Switching a listing off and on must not disturb its price'
            );
        });
    }

    public function testChannelStatusIsANoOpWhenAlreadyInThatState(): void
    {
        $this->withoutSearch(function (): void {
            $variant = $this->product()->variants()->first();

            $this->tool(new SetVariantStockTool())
                ->__invoke(variant_id: $variant->getId(), price: 12.0);

            $result = $this->tool(new SetVariantChannelStatusTool())
                ->__invoke(variant_id: $variant->getId(), is_published: false);

            $this->assertFalse($result['updated']);
            $this->assertStringContainsString('already inactive', $result['message']);
        });
    }

    public function testChannelStatusRefusesWhenTheVariantIsNotListedYet(): void
    {
        $this->withoutSearch(function (): void {
            $variant = $this->product()->variants()->first();

            $result = $this->tool(new SetVariantChannelStatusTool())
                ->__invoke(variant_id: $variant->getId(), is_published: true);

            $this->assertSame('error', $result['status']);
            $this->assertStringContainsString('set_variant_channel_price', $result['message']);
        });
    }

    /**
     * Activating reports success on the channel row alone, but a shopper also needs the variant, its
     * product and the channel published — so an activate on a draft has to say why nothing appeared.
     */
    public function testActivatingOnADraftProductNamesWhatStillHidesIt(): void
    {
        $this->withoutSearch(function (): void {
            $created = $this->tool(new CreateProductTool())->__invoke(
                name: 'Agent Hidden ' . fake()->unique()->uuid(),
                sku: 'agent-' . fake()->unique()->uuid(),
                price: 9.0,
            );

            $variantId = $created['variants'][0]['variant_id'];

            $this->tool(new SetVariantChannelStatusTool())
                ->__invoke(variant_id: $variantId, is_published: false);

            $result = $this->tool(new SetVariantChannelStatusTool())
                ->__invoke(variant_id: $variantId, is_published: true);

            $this->assertTrue($result['updated']);
            $this->assertArrayHasKey('not_yet_visible_because', $result);
            $this->assertStringContainsString(
                'set_product_published',
                implode(' ', $result['not_yet_visible_because'])
            );
        });
    }

    /**
     * Listing a variant on a channel it has never been on needs a real price — the create path
     * defaults to 0.00, which would put it on the storefront as a giveaway.
     */
    public function testChannelPriceRefusesToListWithoutAPrice(): void
    {
        $this->withoutSearch(function (): void {
            $variant = $this->product()->variants()->first();

            $this->tool(new SetVariantStockTool())
                ->__invoke(variant_id: $variant->getId(), quantity: 1);

            $result = $this->tool(new SetVariantChannelPriceTool())
                ->__invoke(variant_id: $variant->getId(), is_published: true);

            $this->assertSame('error', $result['status']);
            $this->assertStringContainsString('0', $result['message']);
            $this->assertNull(
                VariantsChannels::where('products_variants_id', $variant->getId())->first(),
                'A refused listing must not leave a zero-priced row behind'
            );
        });
    }

    public function testChannelPriceRejectsAHallucinatedChannel(): void
    {
        $this->withoutSearch(function (): void {
            $variant = $this->product()->variants()->first();

            $result = $this->tool(new SetVariantChannelPriceTool())
                ->__invoke(variant_id: $variant->getId(), price: 5.0, channel_id: 999999999);

            $this->assertSame('error', $result['status']);
            $this->assertStringContainsString('list_channels', $result['message']);
        });
    }

    public function testDefaultChannelIsTheOneTheCartReads(): void
    {
        $company = auth()->user()->getCurrentCompany();

        $listed = $this->tool(new ListChannelsTool())->__invoke();
        $defaultId = $listed['channels'][0]['id'];

        $cartChannel = Channels::fromApp($this->kanvasApp)
            ->fromCompany($company)
            ->notDeleted()
            ->where('is_default', true)
            ->where('is_published', 1)
            ->first();

        $this->assertSame($cartChannel->getId(), $defaultId);
    }
}
