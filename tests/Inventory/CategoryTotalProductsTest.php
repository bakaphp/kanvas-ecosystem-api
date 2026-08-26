<?php

declare(strict_types=1);

namespace Tests\Inventory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Categories\Actions\CreateCategory;
use Kanvas\Inventory\Categories\DataTransferObject\Categories as CategoriesDto;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class CategoryTotalProductsTest extends TestCase
{
    use DatabaseTransactions;

    protected Apps $kanvasApp;
    protected Users $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $this->user = $user;

        new InventorySetup($this->kanvasApp, $user, $user->getCurrentCompany())->run();
    }

    public function testTotalProductsIsZeroForEmptyCategory(): void
    {
        $this->assertSame(0, $this->createCategory()->getTotalProducts());
    }

    public function testTotalProductsCountsAttachedProducts(): void
    {
        $category = $this->createCategory();

        $this->createProduct()->categories()->attach($category->getId());
        $this->createProduct()->categories()->attach($category->getId());

        $this->assertSame(2, $this->freshCategory($category)->getTotalProducts());
    }

    public function testTotalProductsGrowsOnEverySubsequentAttach(): void
    {
        $category = $this->createCategory();

        $this->createProduct()->categories()->attach($category->getId());
        $this->assertSame(1, $this->freshCategory($category)->getTotalProducts());

        $this->createProduct()->categories()->attach($category->getId());
        $this->assertSame(2, $this->freshCategory($category)->getTotalProducts());

        $this->createProduct()->categories()->attach($category->getId());
        $this->assertSame(3, $this->freshCategory($category)->getTotalProducts());
    }

    public function testTotalProductsShrinksOnDetach(): void
    {
        $category = $this->createCategory();
        $product = $this->createProduct();

        $product->categories()->attach($category->getId());
        $this->assertSame(1, $this->freshCategory($category)->getTotalProducts());

        $product->categories()->detach($category->getId());
        $this->assertSame(0, $this->freshCategory($category)->getTotalProducts());
    }

    public function testSoftDeletedProductIsNotCounted(): void
    {
        $category = $this->createCategory();
        $product = $this->createProduct();

        $product->categories()->attach($category->getId());
        $this->assertSame(1, $this->freshCategory($category)->getTotalProducts());

        $product->is_deleted = 1;
        $product->save();

        $this->assertSame(0, $this->freshCategory($category)->getTotalProducts());
    }

    public function testProductsOfAnotherCategoryAreNotCounted(): void
    {
        $category = $this->createCategory();
        $other = $this->createCategory();

        $this->createProduct()->categories()->attach($other->getId());

        $this->assertSame(0, $this->freshCategory($category)->getTotalProducts());
        $this->assertSame(1, $this->freshCategory($other)->getTotalProducts());
    }

    public function testTotalProductsCostsNoQueryPerCategory(): void
    {
        $product = $this->createProduct();
        $ids = [];

        foreach (range(1, 3) as $ignored) {
            $category = $this->createCategory();
            $product->categories()->attach($category->getId());
            $ids[] = $category->getId();
        }

        $connection = DB::connection('inventory');
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        $counts = Categories::whereIn('id', $ids)
            ->get()
            ->map(fn (Categories $category) => $category->getTotalProducts())
            ->all();

        $queries = $connection->getQueryLog();
        $connection->disableQueryLog();

        $this->assertSame([1, 1, 1], $counts);
        $this->assertCount(1, $queries, 'total_products must ride along on the row, not fan out a COUNT per category');
    }

    private function freshCategory(Categories $category): Categories
    {
        return Categories::findOrFail($category->getId());
    }

    private function createCategory(): Categories
    {
        return new CreateCategory(
            new CategoriesDto(
                app: $this->kanvasApp,
                company: $this->user->getCurrentCompany(),
                user: $this->user,
                name: 'Category ' . uniqid(),
            ),
            $this->user
        )->execute();
    }

    private function createProduct(): Products
    {
        return new CreateProductAction(
            new ProductDto(
                app: $this->kanvasApp,
                company: $this->user->getCurrentCompany(),
                user: $this->user,
                name: 'Product ' . uniqid(),
            ),
            $this->user
        )->execute();
    }
}
