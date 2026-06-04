<?php

declare(strict_types=1);

namespace Tests\Inventory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Categories\Actions\CreateCategory;
use Kanvas\Inventory\Categories\DataTransferObject\Categories as CategoriesDto;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Products\Models\ProductsCategories;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class ProductsCategoriesCompositeKeyTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'inventory'];

    private Apps $kanvasApp;
    private Users $kanvasUser;
    private Products $product;
    private Categories $categoryA;
    private Categories $categoryB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $this->kanvasUser = $user;
        $company = $user->getCurrentCompany();

        new InventorySetup($this->kanvasApp, $user, $company)->run();

        $this->product = new CreateProductAction(
            new ProductDto(
                app: $this->kanvasApp,
                company: $company,
                user: $user,
                name: 'PivotCompositeKey-' . uniqid(),
            ),
            $user
        )->execute();

        $this->categoryA = $this->createCategory('PivotCategoryA');
        $this->categoryB = $this->createCategory('PivotCategoryB');
    }

    public function testSaveAndFindRoundTripWithCompositeKey(): void
    {
        $row = new ProductsCategories([
            'products_id' => $this->product->getId(),
            'categories_id' => $this->categoryA->getId(),
        ]);
        $row->save();

        $found = ProductsCategories::find([
            $this->product->getId(),
            $this->categoryA->getId(),
        ]);

        $this->assertNotNull($found);
        $this->assertSame($this->product->getId(), (int) $found->products_id);
        $this->assertSame($this->categoryA->getId(), (int) $found->categories_id);
    }

    public function testSoftDeleteScopesByCompositeKeyOnly(): void
    {
        $rowA = new ProductsCategories([
            'products_id' => $this->product->getId(),
            'categories_id' => $this->categoryA->getId(),
        ]);
        $rowA->save();

        $rowB = new ProductsCategories([
            'products_id' => $this->product->getId(),
            'categories_id' => $this->categoryB->getId(),
        ]);
        $rowB->save();

        $rowA->delete();

        $aRefetched = ProductsCategories::withoutGlobalScopes()
            ->where('products_id', $this->product->getId())
            ->where('categories_id', $this->categoryA->getId())
            ->first();
        $bRefetched = ProductsCategories::withoutGlobalScopes()
            ->where('products_id', $this->product->getId())
            ->where('categories_id', $this->categoryB->getId())
            ->first();

        $this->assertNotNull($aRefetched);
        $this->assertNotNull($bRefetched);
        $this->assertTrue((bool) $aRefetched->is_deleted);
        $this->assertFalse((bool) $bRefetched->is_deleted);
    }

    private function createCategory(string $name): Categories
    {
        $company = $this->kanvasUser->getCurrentCompany();

        return new CreateCategory(
            new CategoriesDto(
                app: $this->kanvasApp,
                company: $company,
                user: $this->kanvasUser,
                name: $name . '-' . uniqid(),
            ),
            $this->kanvasUser
        )->execute();
    }
}
