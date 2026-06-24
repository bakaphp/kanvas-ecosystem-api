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
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Actions\SetVariantSortingRatingFromCategoryAction;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class SetVariantSortingRatingFromCategoryActionTest extends TestCase
{
    use DatabaseTransactions;

    protected Apps $kanvasApp;
    protected Users $user;
    protected Products $product;
    protected Variants $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $this->user = $user;
        $company = $user->getCurrentCompany();

        (new InventorySetup($this->kanvasApp, $user, $company))->run();

        $this->product = new CreateProductAction(
            new ProductDto(
                app: $this->kanvasApp,
                company: $company,
                user: $user,
                name: 'Test Product ' . uniqid(),
            ),
            $user
        )->execute();

        $this->variant = $this->product->variants()->where('is_deleted', 0)->first();
    }

    public function testSingleCategoryRatingEqualsWeight(): void
    {
        $category = $this->createCategory('Alpha', 5);
        $this->product->categories()->attach($category->id);

        $result = new SetVariantSortingRatingFromCategoryAction($this->variant)->execute();

        $this->assertSame('updated', $result['status']);
        $this->assertSame(5.0, $result['rating']);

        $this->variant->refresh();
        $this->assertSame(5.0, $this->variant->rating);
    }

    public function testMultipleCategoriesRatingEqualsMaxWeight(): void
    {
        $low = $this->createCategory('Low', 1);
        $high = $this->createCategory('High', 9);
        $mid = $this->createCategory('Mid', 4);

        $this->product->categories()->attach([$low->id, $high->id, $mid->id]);

        $result = new SetVariantSortingRatingFromCategoryAction($this->variant)->execute();

        $this->assertSame('updated', $result['status']);
        $this->assertSame(9.0, $result['rating']);
    }

    public function testAlphaTieBreakAmongEqualWeights(): void
    {
        // apple (7) and Banana (7) are tied — alpha tie-break (case-insensitive) picks apple first,
        // but both yield rating=7. Cherry (5) is lower and will not win.
        // This test exercises the ORDER BY LOWER(name) ASC code path.
        $apple = $this->createCategory('apple', 7);
        $banana = $this->createCategory('Banana', 7);
        $cherry = $this->createCategory('Cherry', 5);

        $this->product->categories()->attach([$apple->id, $banana->id, $cherry->id]);

        $result = new SetVariantSortingRatingFromCategoryAction($this->variant)->execute();

        $this->assertSame('updated', $result['status']);
        $this->assertSame(7.0, $result['rating']);
    }

    public function testZeroNonDeletedCategoriesYieldsZeroRating(): void
    {
        // First give the variant a non-zero rating so we can confirm it is reset
        $this->variant->rating = 8.0;
        $this->variant->save();

        // Attach no categories — product has none
        $result = new SetVariantSortingRatingFromCategoryAction($this->variant)->execute();

        $this->assertSame('updated', $result['status']);
        $this->assertSame(0.0, $result['rating']);

        $this->variant->refresh();
        $this->assertSame(0.0, $this->variant->rating);
    }

    public function testSoftDeletedHighestWeightCategoryIsIgnored(): void
    {
        $deleted = $this->createCategory('Deleted Heavy', 9);
        $active = $this->createCategory('Active Light', 5);

        $this->product->categories()->attach([$deleted->id, $active->id]);

        // Soft-delete the heavy category
        $deleted->is_deleted = 1;
        $deleted->save();

        $result = new SetVariantSortingRatingFromCategoryAction($this->variant)->execute();

        $this->assertSame('updated', $result['status']);
        $this->assertSame(5.0, $result['rating']);
    }

    public function testRatingUnchangedReturnsUnchangedStatus(): void
    {
        $category = $this->createCategory('Stable', 7);
        $this->product->categories()->attach($category->id);

        // Pre-set the variant's rating to the resolved value
        $this->variant->rating = 7.0;
        $this->variant->save();
        $updatedAtBefore = $this->variant->fresh()->updated_at;

        // Sleep 1 second so any accidental save would bump updated_at
        sleep(1);

        $result = new SetVariantSortingRatingFromCategoryAction($this->variant)->execute();

        $this->assertSame('unchanged', $result['status']);
        $this->assertSame(7.0, $result['rating']);

        // Verify updated_at did not move — confirms save() was not called
        $this->assertEquals($updatedAtBefore, $this->variant->fresh()->updated_at);
    }

    public function testVariantWithNoProductReturnsSkipped(): void
    {
        // Soft-delete the parent product so $variant->product returns null
        $this->product->is_deleted = 1;
        $this->product->save();

        // Reload the variant so its eager-loaded product relation is cleared
        $freshVariant = Variants::find($this->variant->getId());

        $result = new SetVariantSortingRatingFromCategoryAction($freshVariant)->execute();

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('no_product', $result['reason']);
        $this->assertSame($freshVariant->getId(), $result['variant_id']);
    }

    private function createCategory(string $name, int $weight): Categories
    {
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return (new CreateCategory(
            new CategoriesDto(
                app: $this->kanvasApp,
                company: $company,
                user: $user,
                name: $name . '-' . uniqid(),
                weight: $weight,
            ),
            $user
        ))->execute();
    }
}
