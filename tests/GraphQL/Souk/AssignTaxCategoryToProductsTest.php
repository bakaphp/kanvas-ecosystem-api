<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Souk\Taxes\Actions\CreateTaxCategoryAction;
use Kanvas\Souk\Taxes\Actions\DeleteTaxCategoryAction;
use Kanvas\Souk\Taxes\Models\TaxCategory;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

final class AssignTaxCategoryToProductsTest extends TestCase
{
    use DatabaseTransactions;
    use InventoryCases;

    protected $connectionsToTransact = ['commerce'];

    private TaxCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = new CreateTaxCategoryAction(
            app(Apps::class),
            ['code' => 'cigars_' . uniqid(), 'name' => 'Cigars'],
        )->execute();
    }

    public function testAssignsTheCategoryToEverySelectedProduct(): void
    {
        $ids = [$this->productId(), $this->productId(), $this->productId()];

        $this->assignMutation($ids, $this->category->code)
            ->assertJson(['data' => ['assignTaxCategoryToProducts' => 3]]);

        foreach ($ids as $id) {
            $this->assertSame($this->category->code, Products::findOrFail($id)->tax_category);
        }
    }

    public function testNullClearsTheCategory(): void
    {
        $id = $this->productId();
        $this->assignMutation([$id], $this->category->code)->assertGraphQLErrorFree();

        $this->assignMutation([$id], null)
            ->assertJson(['data' => ['assignTaxCategoryToProducts' => 1]]);

        $this->assertNull(Products::findOrFail($id)->tax_category);
    }

    public function testCodeOutsideTheCatalogIsRejectedAndNothingChanges(): void
    {
        $id = $this->productId();

        $error = $this->assignMutation([$id], 'tabacco')->json('errors.0.message');

        $this->assertSame('Unknown tax category: tabacco', $error);
        $this->assertNull(Products::findOrFail($id)->tax_category);
    }

    public function testProductOfAnotherCompanyIsNotTouched(): void
    {
        $own = $this->productId();
        $foreign = $this->productId();
        Products::whereKey($foreign)->update(['companies_id' => 0]);

        $this->assignMutation([$own, $foreign], $this->category->code)
            ->assertJson(['data' => ['assignTaxCategoryToProducts' => 1]]);

        $this->assertNull(Products::findOrFail($foreign)->tax_category);
    }

    public function testCategoryInUseByAProductCannotBeDeleted(): void
    {
        $this->assignMutation([$this->productId()], $this->category->code)->assertGraphQLErrorFree();

        $this->expectExceptionMessage("Tax category {$this->category->code} is still assigned to tax rates or products");

        new DeleteTaxCategoryAction($this->category)->execute();
    }

    private function productId(): int
    {
        return (int) $this->graphQLData($this->createProduct(), 'createProduct')['id'];
    }

    private function assignMutation(array $productIds, ?string $taxCategory): TestResponse
    {
        return $this->graphQL('
            mutation($ids: [ID!]!, $category: String) {
                assignTaxCategoryToProducts(product_ids: $ids, tax_category: $category)
            }
        ', ['ids' => $productIds, 'category' => $taxCategory]);
    }
}
