<?php

declare(strict_types=1);

namespace Tests\Inventory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Categories\Actions\CreateCategory;
use Kanvas\Inventory\Categories\DataTransferObject\Categories as CategoriesDto;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\WorkflowActivity\SetProductVariantsSortingRatingFromCategoryActivity;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\TestCase;

class SetProductVariantsSortingRatingFromCategoryActivityTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['workflow'];

    protected Apps $kanvasApp;
    protected Users $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $this->user = $user;
        $company = $user->getCurrentCompany();

        (new InventorySetup($this->kanvasApp, $user, $company))->run();
    }

    public function testProductWithMultipleVariantsProcessesAll(): void
    {
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $product = new CreateProductAction(
            new ProductDto(
                app: $this->kanvasApp,
                company: $company,
                user: $user,
                name: 'Multi Variant Product ' . uniqid(),
            ),
            $user
        )->execute();

        // ProductFactory creates 1 variant automatically; add 2 more to reach 3 total
        Variants::factory()
            ->withProductId($product->getId())
            ->create();

        Variants::factory()
            ->withProductId($product->getId())
            ->create();

        $category = (new CreateCategory(
            new CategoriesDto(
                app: $this->kanvasApp,
                company: $company,
                user: $user,
                name: 'Multi Cat ' . uniqid(),
                weight: 4,
            ),
            $user
        ))->execute();

        $product->categories()->attach($category->id);

        $activity = new SetProductVariantsSortingRatingFromCategoryActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );
        $result = $activity->execute($product, $this->kanvasApp, []);

        $this->assertSame('success', $result['status']);
        $this->assertSame($product->getId(), $result['product_id']);
        $this->assertSame(3, $result['variant_count']);
        $this->assertSame(3, $result['updated_count']);
        $this->assertCount(3, $result['results']);

        foreach ($result['results'] as $variantResult) {
            $this->assertSame('updated', $variantResult['status']);
            $this->assertSame(4.0, $variantResult['rating']);
        }
    }

    public function testProductWithZeroVariantsReturnsSuccessWithZeroCounts(): void
    {
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $product = new CreateProductAction(
            new ProductDto(
                app: $this->kanvasApp,
                company: $company,
                user: $user,
                name: 'No Variant Product ' . uniqid(),
            ),
            $user
        )->execute();

        // Soft-delete the auto-created variant so the product has zero active ones
        $product->variants()->update(['is_deleted' => 1]);

        $category = (new CreateCategory(
            new CategoriesDto(
                app: $this->kanvasApp,
                company: $company,
                user: $user,
                name: 'No Variant Cat ' . uniqid(),
                weight: 3,
            ),
            $user
        ))->execute();

        $product->categories()->attach($category->id);

        $activity = new SetProductVariantsSortingRatingFromCategoryActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );
        $result = $activity->execute($product, $this->kanvasApp, []);

        $this->assertSame('success', $result['status']);
        $this->assertSame($product->getId(), $result['product_id']);
        $this->assertSame(0, $result['variant_count']);
        $this->assertSame(0, $result['updated_count']);
        $this->assertSame([], $result['results']);
    }
}
