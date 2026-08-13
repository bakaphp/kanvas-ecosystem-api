<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Products\Models\ProductsAttributes;
use Kanvas\Inventory\Support\Setup;
use Tests\TestCase;

final class ProductAttributeValueFilterTest extends TestCase
{
    /**
     * products_attributes.value carries two encodings for the same attribute: the spatie
     * translation map written through the model, and a bare scalar on rows that bypassed it
     * (bulk inserts and pre-translatable legacy data). A plain `where('value', ...)` only ever
     * sees one of them, so the filter has to normalize before comparing.
     */
    public function testFilterMatchesBothRawAndTranslatedValues(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        new Setup($app, $user, $company)->run();

        $attributeName = 'sold_' . uniqid();

        $translatedProduct = $this->createProduct($app, $company, $user);
        $translatedProduct->addAttribute($attributeName, '0');

        $rawProduct = $this->createProduct($app, $company, $user);
        $rawProduct->addAttribute($attributeName, '0');

        $attributeId = (int) $translatedProduct->getAttributeByName($attributeName)->attributes_id;

        DB::connection('inventory')
            ->table('products_attributes')
            ->where('products_id', $rawProduct->getId())
            ->where('attributes_id', $attributeId)
            ->update(['value' => '0']);

        $this->assertSame('{"en":"0"}', $this->rawStoredValue($translatedProduct->getId(), $attributeId));
        $this->assertSame('0', $this->rawStoredValue($rawProduct->getId(), $attributeId));

        $naive = Products::query()
            ->whereHas(
                'attributeValues',
                fn ($q) => $q->where('attributes_id', $attributeId)->where('value', '0')
            )
            ->pluck('products.id')
            ->all();

        $this->assertNotContains(
            $translatedProduct->getId(),
            $naive,
            'an un-normalized comparison must miss the translated encoding — this is the bug being fixed'
        );

        $matched = Products::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->filterByAttributeValue(value: '0', attributesId: $attributeId)
            ->pluck('products.id')
            ->all();

        $this->assertContains($rawProduct->getId(), $matched);
        $this->assertContains($translatedProduct->getId(), $matched);
    }

    public function testFilterByAttributeSlugAndValue(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        new Setup($app, $user, $company)->run();

        $attributeName = 'condition_' . uniqid();

        $wanted = $this->createProduct($app, $company, $user);
        $wanted->addAttribute($attributeName, 'used');

        $unwanted = $this->createProduct($app, $company, $user);
        $unwanted->addAttribute($attributeName, 'new');

        $slug = $wanted->getAttributeByName($attributeName)->slug;

        $matched = Products::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->filterByAttributeValue(value: 'used', slug: $slug)
            ->pluck('products.id')
            ->all();

        $this->assertContains($wanted->getId(), $matched);
        $this->assertNotContains($unwanted->getId(), $matched);
    }

    public function testProductsQueryExposesAttributeValuesFilter(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        new Setup($app, $user, $company)->run();

        $attributeName = 'gqlsold_' . uniqid();

        $product = $this->createProduct($app, $company, $user);
        $product->addAttribute($attributeName, '0');

        $attributeId = $product->getAttributeByName($attributeName)->attributes_id;

        $productAttribute = ProductsAttributes::where('products_id', $product->getId())
            ->where('attributes_id', $attributeId)
            ->firstOrFail();
        $productAttribute->setTranslation('value', 'en', '0');
        $productAttribute->save();

        $response = $this->graphQL('
            query($filters: [ProductAttributeFilterInput!]) {
                products(attributeValues: $filters) {
                    data { id }
                }
            }
        ', ['filters' => [['attribute_id' => (string) $attributeId, 'value' => '0']]])
            ->assertSuccessful();

        $ids = array_column($response->json('data.products.data'), 'id');

        $this->assertContains((string) $product->getId(), $ids);
    }

    public function testAttributeValuesFiltersOnExistenceWithoutAValue(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        new Setup($app, $user, $company)->run();

        $attributeName = 'haswarranty_' . uniqid();

        $withAttribute = $this->createProduct($app, $company, $user);
        $withAttribute->addAttribute($attributeName, 'yes');

        $withoutAttribute = $this->createProduct($app, $company, $user);

        $slug = $withAttribute->getAttributeByName($attributeName)->slug;

        $matched = Products::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->filterByAttributeValue(slug: $slug)
            ->pluck('products.id')
            ->all();

        $this->assertContains($withAttribute->getId(), $matched);
        $this->assertNotContains($withoutAttribute->getId(), $matched);
    }

    /**
     * Read the column straight off the connection — going through the model would return the
     * value already decoded by the translatable accessor, hiding the encoding under test.
     */
    private function rawStoredValue(int $productId, int $attributeId): string
    {
        return (string) DB::connection('inventory')
            ->table('products_attributes')
            ->where('products_id', $productId)
            ->where('attributes_id', $attributeId)
            ->value('value');
    }

    private function createProduct(Apps $app, mixed $company, mixed $user): Products
    {
        return new CreateProductAction(
            new Product(
                app: $app,
                company: $company,
                user: $user,
                name: fake()->name,
                sku: fake()->unique()->uuid(),
            ),
            $user
        )->execute();
    }
}
