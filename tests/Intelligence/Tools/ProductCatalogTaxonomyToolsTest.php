<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\CreateCategoryTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\CreateProductTypeTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\DuplicateProductTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\DuplicateVariantTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\ProductDetailTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\SetProductAttributesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\SetProductCategoriesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\SetVariantAttributesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\SetVariantStockTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\UpdateProductTool;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Tests\TestCase;

/**
 * Covers the read-back, taxonomy-creation, attribute-removal and duplicate tools. No
 * DatabaseTransactions, for the CreateProductAction retry reason spelled out in
 * ProductCatalogCrudToolsTest.
 */
class ProductCatalogTaxonomyToolsTest extends TestCase
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

    public function testProductDetailReadsBackWhatTheWriteToolsSet(): void
    {
        $this->withoutSearch(function (): void {
            $product = $this->product();
            $variant = $product->variants()->first();
            $spec = 'AgentSpec' . fake()->unique()->uuid();

            $category = $this->tool(new CreateCategoryTool())
                ->__invoke(name: 'AgentDetailCat' . fake()->unique()->uuid());

            $this->tool(new SetProductCategoriesTool())
                ->__invoke(product_id: $product->getId(), category_ids: [$category['category_id']]);

            $this->tool(new SetProductAttributesTool())
                ->__invoke(product_id: $product->getId(), attributes: json_encode([$spec => 'Cotton']));

            $this->tool(new SetVariantStockTool())
                ->__invoke(variant_id: $variant->getId(), price: 31.0, quantity: 6);

            $detail = $this->tool(new ProductDetailTool())->__invoke(product_id: $product->getId());

            $this->assertSame((int) $product->getId(), $detail['product_id']);
            $this->assertContains($category['name'], $detail['categories']);
            $this->assertContains($spec, array_column($detail['attributes'], 'name'));

            $variantRow = $detail['variants'][0];
            $this->assertSame(6.0, $variantRow['stock'][0]['quantity']);
            $this->assertSame(31.0, $variantRow['channels'][0]['price']);
            $this->assertTrue($variantRow['channels'][0]['is_default_channel']);
        });
    }

    public function testProductDetailRejectsAHallucinatedId(): void
    {
        $result = $this->tool(new ProductDetailTool())->__invoke(product_id: 999999999);

        $this->assertSame('error', $result['status']);
    }

    /**
     * AddAttributeAction treats an empty value as a no-op, so an agent trying to clear a spec by
     * setting it to '' silently succeeds at nothing. Removal has to be its own parameter.
     */
    public function testProductAttributesCanBeRemovedByName(): void
    {
        $this->withoutSearch(function (): void {
            $product = $this->product();
            $spec = 'AgentDrop' . fake()->unique()->uuid();

            $this->tool(new SetProductAttributesTool())
                ->__invoke(product_id: $product->getId(), attributes: json_encode([$spec => 'Yes']));

            $before = $product->refresh()->attributeValues()->count();
            $this->assertGreaterThan(0, $before);

            $result = $this->tool(new SetProductAttributesTool())
                ->__invoke(product_id: $product->getId(), remove: [$spec]);

            $this->assertTrue($result['updated']);
            $this->assertSame([$spec], $result['attributes_removed']);
            $this->assertSame($before - 1, $product->refresh()->attributeValues()->count());
        });
    }

    public function testRemovingAnUnknownAttributeIsReportedNotSwallowed(): void
    {
        $this->withoutSearch(function (): void {
            $result = $this->tool(new SetProductAttributesTool())
                ->__invoke(product_id: $this->product()->getId(), remove: ['NoSuchSpec' . fake()->unique()->uuid()]);

            $this->assertSame([], $result['attributes_removed']);
            $this->assertCount(1, $result['attributes_not_found']);
        });
    }

    public function testVariantAttributesCanBeRemovedByName(): void
    {
        $this->withoutSearch(function (): void {
            $variant = $this->product()->variants()->first();
            $spec = 'AgentVarDrop' . fake()->unique()->uuid();

            $this->tool(new SetVariantAttributesTool())
                ->__invoke(variant_id: $variant->getId(), attributes: json_encode([$spec => 'XL']));

            $result = $this->tool(new SetVariantAttributesTool())
                ->__invoke(variant_id: $variant->getId(), remove: [$spec]);

            $this->assertTrue($result['updated']);
            $this->assertSame([$spec], $result['attributes_removed']);
        });
    }

    public function testSettingAndRemovingNothingIsRefused(): void
    {
        $this->withoutSearch(function (): void {
            $result = $this->tool(new SetProductAttributesTool())
                ->__invoke(product_id: $this->product()->getId(), attributes: '{}');

            $this->assertFalse($result['updated']);
            $this->assertSame('error', $result['status']);
        });
    }

    public function testCreateCategoryReusesAnExistingNameRatherThanDuplicating(): void
    {
        $name = 'AgentDupCat' . fake()->unique()->uuid();

        $first = $this->tool(new CreateCategoryTool())->__invoke(name: $name);
        $second = $this->tool(new CreateCategoryTool())->__invoke(name: $name);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created'], 'A second call with the same name must not create a duplicate');
        $this->assertSame($first['category_id'], $second['category_id']);
        $this->assertStringContainsString('already existed', $second['message']);
    }

    public function testCreateCategoryNestsUnderAParent(): void
    {
        $parent = $this->tool(new CreateCategoryTool())
            ->__invoke(name: 'AgentParent' . fake()->unique()->uuid());

        $child = $this->tool(new CreateCategoryTool())
            ->__invoke(name: 'AgentChild' . fake()->unique()->uuid(), parent_id: $parent['category_id']);

        $this->assertTrue($child['created']);
        $this->assertSame($parent['category_id'], (int) $child['parent_id']);
    }

    public function testCreateCategoryRejectsAHallucinatedParent(): void
    {
        $result = $this->tool(new CreateCategoryTool())
            ->__invoke(name: 'AgentOrphan' . fake()->unique()->uuid(), parent_id: 999999999);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('category_search', $result['message']);
    }

    public function testCreateProductTypeIsUsableAsAProductTypeId(): void
    {
        $this->withoutSearch(function (): void {
            $type = $this->tool(new CreateProductTypeTool())
                ->__invoke(name: 'AgentType' . fake()->unique()->uuid());

            $this->assertTrue($type['created']);

            $product = $this->product();

            $updated = $this->tool(new UpdateProductTool())
                ->__invoke(product_id: $product->getId(), product_type_id: $type['product_type_id']);

            $this->assertTrue($updated['updated']);
            $this->assertSame($type['name'], $updated['product_type']);
        });
    }

    public function testDuplicateProductCopiesItAndSaysWhatIsMissing(): void
    {
        $this->withoutSearch(function (): void {
            $product = $this->product();

            $result = $this->tool(new DuplicateProductTool())->__invoke(product_id: $product->getId());

            $this->assertTrue($result['created']);
            $this->assertSame((int) $product->getId(), $result['copied_from_product_id']);
            $this->assertStringContainsString('(Copy)', $result['name']);
            $this->assertStringContainsString('set_variant_stock', $result['message']);
            $this->assertNotEmpty($result['variants'], 'A copied product must carry its variants');
        });
    }

    public function testDuplicateVariantAddsASkuToTheSameProduct(): void
    {
        $this->withoutSearch(function (): void {
            $product = $this->product();
            $variant = $product->variants()->first();

            $result = $this->tool(new DuplicateVariantTool())->__invoke(variant_id: $variant->getId());

            $this->assertTrue($result['created']);
            $this->assertSame((int) $variant->getId(), $result['copied_from_variant_id']);
            $this->assertSame((int) $product->getId(), $result['product_id']);
            $this->assertSame(2, $product->variants()->count());
        });
    }
}
