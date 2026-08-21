<?php

declare(strict_types=1);

namespace Tests\Inventory\Products;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Products\Models\Products;
use Tests\TestCase;

/**
 * `categories_flat` keys become Typesense sub-fields (`categories_flat.<name>`), so a category
 * with a blank name registers the invalid field `categories_flat.` and Typesense rejects the whole
 * import batch with "Field `categories_flat.` has an incorrect type" (Sentry KANVAS-ECOSYSTEM-628).
 * Recreating the collection does not help — the key is invalid under any schema.
 */
final class ProductSearchableCategoriesFlatTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'inventory'];

    public function testBlankCategoryNamesAreNotIndexed(): void
    {
        $indexed = $this->indexProductWithCategoryNames(['Shoes', '', '   ', null]);

        $this->assertSame(['Shoes' => 1], $indexed['categories_flat']);
        $this->assertArrayNotHasKey('', $indexed['categories_flat']);
    }

    public function testCategoryNamesAreTrimmedIntoKeys(): void
    {
        $indexed = $this->indexProductWithCategoryNames(['  Gift Cards  ']);

        $this->assertSame(['Gift Cards' => 1], $indexed['categories_flat']);
    }

    public function testProductWithoutCategoriesIndexesAnEmptyMap(): void
    {
        $this->assertSame([], $this->indexProductWithCategoryNames([])['categories_flat']);
    }

    private function indexProductWithCategoryNames(array $names): array
    {
        $company = Companies::factory()->create();

        /** @var Products $product */
        $product = Products::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->create(['is_published' => 1, 'is_deleted' => 0]);

        $product->load('variants');
        $product->setRelation(
            'categories',
            new Collection(array_map(fn ($name) => new Categories(['name' => $name]), $names)),
        );

        return $product->toSearchableArray();
    }
}
