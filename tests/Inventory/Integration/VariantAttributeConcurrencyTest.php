<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Attributes\Actions\CreateAttribute;
use Kanvas\Inventory\Attributes\DataTransferObject\Attributes as AttributesDto;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Products\Actions\AddAttributeAction as AddProductAttributeAction;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\ProductsAttributes;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Actions\AddAttributeAction;
use Kanvas\Inventory\Variants\Actions\CreateVariantsAction;
use Kanvas\Inventory\Variants\DataTransferObject\Variants as VariantsDto;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsAttributes;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * Regression for Sentry KANVAS-ECOSYSTEM-50J: concurrent product importers deadlocked on
 * products_variants_attributes. Two paths took the locks in opposite order — the insert
 * took an FK lock on the shared `attributes` row first, while Variants::$touches fired a
 * joined UPDATE that locked the child rows first.
 */
final class VariantAttributeConcurrencyTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'inventory'];

    public function testSavingAVariantDoesNotMassUpdateItsAttributes(): void
    {
        [$variant, $attribute] = $this->makeVariantWithAttribute();

        new AddAttributeAction($variant, $attribute, 'first')->execute();

        DB::connection('inventory')->flushQueryLog();
        DB::connection('inventory')->enableQueryLog();

        $variant->name = 'renamed-' . uniqid();
        $variant->save();

        $queryLog = DB::connection('inventory')->getQueryLog();
        DB::connection('inventory')->disableQueryLog();

        $touchQueries = array_filter(
            $queryLog,
            fn (array $query) => str_contains($query['query'], 'update')
                && str_contains($query['query'], 'products_variants_attributes')
        );

        $this->assertSame(
            [],
            array_values(array_column($touchQueries, 'query')),
            'Saving a variant must not mass-update products_variants_attributes.'
        );
    }

    public function testAddAttributeWritesInASingleStatement(): void
    {
        [$variant, $attribute] = $this->makeVariantWithAttribute();

        DB::connection('inventory')->flushQueryLog();
        DB::connection('inventory')->enableQueryLog();

        new AddAttributeAction($variant, $attribute, 'first')->execute();

        $queryLog = DB::connection('inventory')->getQueryLog();
        DB::connection('inventory')->disableQueryLog();

        $selects = array_filter(
            $queryLog,
            fn (array $query) => str_contains($query['query'], 'select')
                && str_contains($query['query'], 'products_variants_attributes')
        );

        $this->assertSame(
            [],
            array_values(array_column($selects, 'query')),
            'The write must be a single upsert — a select-then-insert leaves a race window.'
        );
    }

    public function testAddAttributeIsIdempotentAndKeepsTheLastValue(): void
    {
        [$variant, $attribute] = $this->makeVariantWithAttribute();

        new AddAttributeAction($variant, $attribute, 'first')->execute();
        new AddAttributeAction($variant, $attribute, 'second')->execute();

        $rows = VariantsAttributes::where('products_variants_id', $variant->getId())
            ->where('attributes_id', $attribute->getId())
            ->get();

        $this->assertCount(1, $rows);
        $this->assertEquals('second', $rows->first()->value);
    }

    public function testProductAttributeIsIdempotentAndKeepsTheLastValue(): void
    {
        [$variant, $attribute] = $this->makeVariantWithAttribute();
        $product = $variant->product;

        new AddProductAttributeAction($product, $attribute, 'first')->execute();
        new AddProductAttributeAction($product, $attribute, 'second')->execute();

        $rows = ProductsAttributes::where('products_id', $product->getId())
            ->where('attributes_id', $attribute->getId())
            ->get();

        $this->assertCount(1, $rows);
        $this->assertEquals('second', $rows->first()->value);
    }

    public function testAddAttributeRestoresADeletedAttribute(): void
    {
        [$variant, $attribute] = $this->makeVariantWithAttribute();

        new AddAttributeAction($variant, $attribute, 'first')->execute();

        DB::connection('inventory')
            ->table('products_variants_attributes')
            ->where('products_variants_id', $variant->getId())
            ->where('attributes_id', $attribute->getId())
            ->update(['is_deleted' => 1]);

        new AddAttributeAction($variant, $attribute, 'second')->execute();

        $row = DB::connection('inventory')
            ->table('products_variants_attributes')
            ->where('products_variants_id', $variant->getId())
            ->where('attributes_id', $attribute->getId())
            ->first();

        $this->assertEquals(0, $row->is_deleted);
        $this->assertStringContainsString('second', (string) $row->value);
    }

    public function testAttributesAreWrittenInAscendingAttributeIdOrder(): void
    {
        [$variant] = $this->makeVariantWithAttribute();

        $attributeIds = [];
        for ($i = 0; $i < 3; $i++) {
            $attributeIds[] = $this->makeAttribute()->getId();
        }
        sort($attributeIds);

        $input = [];
        foreach (array_reverse($attributeIds) as $index => $attributeId) {
            $input[] = ['id' => $attributeId, 'name' => 'ordered-' . $index, 'value' => 'value-' . $index];
        }

        DB::connection('inventory')->flushQueryLog();
        DB::connection('inventory')->enableQueryLog();

        $variant->addAttributes(auth()->user(), $input);

        $queryLog = DB::connection('inventory')->getQueryLog();
        DB::connection('inventory')->disableQueryLog();

        $writtenOrder = [];
        foreach ($queryLog as $query) {
            if (! str_contains($query['query'], 'insert') || ! str_contains($query['query'], 'products_variants_attributes')) {
                continue;
            }

            foreach ($query['bindings'] as $binding) {
                if (is_numeric($binding) && in_array((int) $binding, $attributeIds, true)) {
                    $writtenOrder[] = (int) $binding;

                    break;
                }
            }
        }

        $this->assertSame($attributeIds, $writtenOrder);
    }

    /**
     * @return array{0: Variants, 1: Attributes}
     */
    private function makeVariantWithAttribute(): array
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new InventorySetup($app, $user, $company)->run();

        $product = new CreateProductAction(
            new ProductDto(
                app: $app,
                company: $company,
                user: $user,
                name: 'DeadlockProduct-' . uniqid(),
            ),
            $user
        )->execute();

        $variant = new CreateVariantsAction(
            new VariantsDto(
                product: $product,
                name: 'DeadlockVariant-' . uniqid(),
                sku: fake()->unique()->uuid(),
            ),
            $user
        )->execute();

        return [$variant, $this->makeAttribute()];
    }

    private function makeAttribute(): Attributes
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        $name = 'Year-' . uniqid();

        return new CreateAttribute(
            new AttributesDto(
                company: $user->getCurrentCompany(),
                app: $app,
                user: $user,
                name: $name,
                slug: strtolower($name),
                attributeType: null,
                isVisible: true,
            ),
            $user
        )->execute();
    }
}
