<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\CreateProductTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\CreateVariantTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\DeleteProductTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\DeleteVariantTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\SetVariantStockTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\UpdateProductTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Inventory\UpdateVariantTool;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Tests\TestCase;

/**
 * No DatabaseTransactions here: CreateProductAction wraps its work in its own inventory transaction
 * so it can retry the gap-lock deadlock concurrent inserts hit, and demoting that to a savepoint
 * kills the retry. Same reason SetProductPublishedToolTest leaves its rows behind.
 */
class ProductCatalogCrudToolsTest extends TestCase
{
    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);

        $company = auth()->user()->getCurrentCompany();

        // Only when the tenant has no default warehouse: Setup::run() also creates a category, a
        // channel and a status, and re-running it per test piles those onto a database the tests
        // never clean, which is what pushes the "list all" tool tests past their result limits.
        if (Warehouses::getDefault($company, $this->kanvasApp) === null) {
            new InventorySetup($this->kanvasApp, auth()->user(), $company)->run();
        }
    }

    /**
     * Products and Variants are both Scout-searchable and every write fires a MakeSearchable job
     * against Typesense. Tests don't need the index, so mute syncing for both models.
     */
    private function withoutSearch(callable $callback): void
    {
        Products::withoutSyncingToSearch(fn () => Variants::withoutSyncingToSearch($callback));
    }

    private function sku(): string
    {
        return 'agent-' . fake()->unique()->uuid();
    }

    private function tool(object $tool): object
    {
        return $tool->withContext($this->kanvasApp, auth()->user()->getCurrentCompany(), auth()->user());
    }

    public function testCreateProductLandsAsADraftWithADefaultVariant(): void
    {
        $this->withoutSearch(function (): void {
            $sku = $this->sku();

            $result = $this->tool(new CreateProductTool())->__invoke(
                name: 'Agent Widget ' . fake()->unique()->uuid(),
                description: 'Built by an agent.',
                sku: $sku,
            );

            $this->assertTrue($result['created']);
            $this->assertFalse($result['is_published'], 'A product an agent creates must default to a draft');
            $this->assertNotEmpty($result['variants']);
            $this->assertSame($sku, $result['variants'][0]['sku']);

            $product = Products::getById($result['product_id']);
            $this->assertSame('Built by an agent.', $product->description);
        });
    }

    public function testCreateProductWithPriceAndQuantityStocksTheDefaultVariant(): void
    {
        $this->withoutSearch(function (): void {
            $result = $this->tool(new CreateProductTool())->__invoke(
                name: 'Agent Priced ' . fake()->unique()->uuid(),
                sku: $this->sku(),
                price: 49.5,
                quantity: 7,
            );

            $this->assertTrue($result['created']);
            $this->assertArrayHasKey('stock', $result);
            $this->assertTrue($result['stock']['updated']);
            $this->assertSame(49.5, $result['stock']['price']);
            $this->assertSame(7.0, $result['stock']['quantity']);
        });
    }

    public function testUpdateProductWritesOnlyTheGivenFields(): void
    {
        $this->withoutSearch(function (): void {
            $product = Products::factory()
                ->company(auth()->user()->getCurrentCompany()->getId())
                ->create([
                    'description' => 'Original description',
                    'short_description' => 'Original short',
                ]);

            $result = $this->tool(new UpdateProductTool())->__invoke(
                product_id: $product->getId(),
                name: 'Renamed By Agent',
            );

            $this->assertTrue($result['updated']);
            $this->assertSame(['name'], $result['changed_fields']);

            $fresh = Products::getById($product->getId());
            $this->assertSame('Renamed By Agent', $fresh->name);
            $this->assertSame('Original description', $fresh->description);
            $this->assertSame('Original short', $fresh->short_description);
        });
    }

    /**
     * The regression that made update_product bypass UpdateProductAction: that action force-deletes
     * and rewrites every attribute value on each call, which erases them under a partial edit.
     */
    public function testUpdateProductKeepsAttributesAndProductType(): void
    {
        $this->withoutSearch(function (): void {
            $product = Products::factory()
                ->company(auth()->user()->getCurrentCompany()->getId())
                ->create();

            $product->addAttributes(auth()->user(), [['name' => 'Colour', 'value' => 'Red']]);

            $attributeCount = $product->attributeValues()->count();
            $productTypeId = $product->products_types_id;
            $this->assertGreaterThan(0, $attributeCount, 'Fixture must have an attribute to protect');

            $this->tool(new UpdateProductTool())->__invoke(
                product_id: $product->getId(),
                description: 'Just the description changed',
            );

            $fresh = Products::getById($product->getId());
            $this->assertSame($attributeCount, $fresh->attributeValues()->count());
            $this->assertSame($productTypeId, $fresh->products_types_id);
        });
    }

    public function testUpdateProductWithNoFieldsIsRefused(): void
    {
        $this->withoutSearch(function (): void {
            $product = Products::factory()
                ->company(auth()->user()->getCurrentCompany()->getId())
                ->create();

            $result = $this->tool(new UpdateProductTool())->__invoke(product_id: $product->getId());

            $this->assertFalse($result['updated']);
            $this->assertSame('error', $result['status']);
        });
    }

    public function testDeleteProductRemovesItAndItsVariants(): void
    {
        $this->withoutSearch(function (): void {
            $product = Products::factory()
                ->company(auth()->user()->getCurrentCompany()->getId())
                ->create();

            $variantId = $product->variants()->first()->getId();

            $result = $this->tool(new DeleteProductTool())->__invoke(product_id: $product->getId());

            $this->assertTrue($result['deleted'], json_encode($result));
            $this->assertTrue((bool) Products::withTrashed()->find($product->getId())->is_deleted);
            $this->assertTrue((bool) Variants::withTrashed()->find($variantId)->is_deleted);
        });
    }

    public function testCreateVariantAddsASellableSkuToAProduct(): void
    {
        $this->withoutSearch(function (): void {
            $product = Products::factory()
                ->company(auth()->user()->getCurrentCompany()->getId())
                ->create();

            $sku = $this->sku();

            $result = $this->tool(new CreateVariantTool())->__invoke(
                product_id: $product->getId(),
                name: 'Large / Black',
                sku: $sku,
                price: 19.99,
                quantity: 3,
            );

            $this->assertTrue($result['created']);
            $this->assertSame($sku, $result['sku']);
            $this->assertSame(19.99, $result['stock']['price']);
            $this->assertSame(3.0, $result['stock']['quantity']);
        });
    }

    public function testCreateVariantWithATakenSkuReturnsGuidanceNotAnException(): void
    {
        $this->withoutSearch(function (): void {
            $product = Products::factory()
                ->company(auth()->user()->getCurrentCompany()->getId())
                ->create();

            $taken = $product->variants()->first()->sku;

            $other = Products::factory()
                ->company(auth()->user()->getCurrentCompany()->getId())
                ->create();

            $result = $this->tool(new CreateVariantTool())->__invoke(
                product_id: $other->getId(),
                name: 'Clashing',
                sku: $taken,
            );

            $this->assertFalse($result['created']);
            $this->assertSame('error', $result['status']);
            $this->assertStringContainsString('unique', $result['message']);
        });
    }

    public function testUpdateVariantLeavesUntouchedFieldsAlone(): void
    {
        $this->withoutSearch(function (): void {
            $product = Products::factory()
                ->company(auth()->user()->getCurrentCompany()->getId())
                ->create();

            $variant = $product->variants()->first();
            $originalSku = $variant->sku;
            $originalSlug = $variant->slug;

            $result = $this->tool(new UpdateVariantTool())->__invoke(
                variant_id: $variant->getId(),
                name: 'Renamed Variant',
            );

            $this->assertTrue($result['updated'], json_encode($result));

            $fresh = Variants::getById($variant->getId());
            $this->assertSame('Renamed Variant', $fresh->name);
            $this->assertSame($originalSku, $fresh->sku);
            $this->assertSame($originalSlug, $fresh->slug, 'A name edit must not silently re-slug the variant');
        });
    }

    public function testDeleteVariantRefusesOnAProductsLastVariant(): void
    {
        $this->withoutSearch(function (): void {
            $product = Products::factory()
                ->company(auth()->user()->getCurrentCompany()->getId())
                ->create();

            $variant = $product->variants()->first();

            $result = $this->tool(new DeleteVariantTool())->__invoke(variant_id: $variant->getId());

            $this->assertFalse($result['deleted']);
            $this->assertStringContainsString('delete_product', $result['message']);
            $this->assertFalse((bool) Variants::getById($variant->getId())->is_deleted);
        });
    }

    public function testDeleteVariantRemovesOneOfSeveral(): void
    {
        $this->withoutSearch(function (): void {
            $product = Products::factory()
                ->company(auth()->user()->getCurrentCompany()->getId())
                ->create();

            $second = $this->tool(new CreateVariantTool())->__invoke(
                product_id: $product->getId(),
                name: 'Second',
                sku: $this->sku(),
            );

            $result = $this->tool(new DeleteVariantTool())->__invoke(variant_id: $second['variant_id']);

            $this->assertTrue($result['deleted']);
            $this->assertTrue((bool) Variants::withTrashed()->find($second['variant_id'])->is_deleted);
        });
    }

    /**
     * The reason set_variant_stock seeds its DTO from the existing row: UpdateToWarehouseAction
     * writes every column it holds, so a fresh DTO would silently clear the merchandising flags.
     */
    public function testSetVariantStockPreservesTheMerchandisingFlags(): void
    {
        $this->withoutSearch(function (): void {
            $company = auth()->user()->getCurrentCompany();

            $product = Products::factory()->company($company->getId())->create();
            $variant = $product->variants()->first();
            $warehouse = Warehouses::getDefault($company, $this->kanvasApp);

            VariantsWarehouses::updateOrCreate(
                [
                    'products_variants_id' => $variant->getId(),
                    'warehouses_id' => $warehouse->getId(),
                ],
                [
                    'sku' => $variant->sku,
                    'quantity' => 2,
                    'price' => 10,
                    'is_on_sale' => 1,
                    'is_default' => 1,
                ]
            );

            $result = $this->tool(new SetVariantStockTool())->__invoke(
                variant_id: $variant->getId(),
                price: 25.0,
            );

            $this->assertTrue($result['updated']);
            $this->assertSame(25.0, $result['price']);
            $this->assertSame(2.0, $result['quantity'], 'An omitted quantity must keep its current value');

            $row = VariantsWarehouses::where('products_variants_id', $variant->getId())
                ->where('warehouses_id', $warehouse->getId())
                ->first();

            $this->assertTrue((bool) $row->is_on_sale);
            $this->assertTrue((bool) $row->is_default);
        });
    }

    public function testSetVariantStockWithNothingToSetIsRefused(): void
    {
        $this->withoutSearch(function (): void {
            $product = Products::factory()
                ->company(auth()->user()->getCurrentCompany()->getId())
                ->create();

            $result = $this->tool(new SetVariantStockTool())
                ->__invoke(variant_id: $product->variants()->first()->getId());

            $this->assertFalse($result['updated']);
            $this->assertSame('error', $result['status']);
        });
    }

    public function testHallucinatedIdsReturnStructuredErrors(): void
    {
        $update = $this->tool(new UpdateProductTool())->__invoke(product_id: 999999999, name: 'Nope');
        $this->assertSame('error', $update['status']);
        $this->assertArrayNotHasKey('updated', $update);

        $variant = $this->tool(new UpdateVariantTool())->__invoke(variant_id: 999999999, name: 'Nope');
        $this->assertSame('error', $variant['status']);

        $stock = $this->tool(new SetVariantStockTool())->__invoke(variant_id: 999999999, price: 1.0);
        $this->assertSame('error', $stock['status']);
    }
}
