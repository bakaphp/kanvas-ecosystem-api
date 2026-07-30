<?php

declare(strict_types=1);

namespace Tests\Connectors\ScrapperApi;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Categories\Actions\CreateCategory;
use Kanvas\Inventory\Categories\DataTransferObject\Categories as CategoriesDto;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Users\Models\Users;
use Tests\TestCase;
use Throwable;

/**
 * No DatabaseTransactions on purpose: tagging writes tags_entities through the
 * inventory connection (Products relation) while the tags rows are created on the
 * social connection (CreateTagAction). Wrapping each connection in its own
 * transaction hides one from the other, so hasTag's cross-connection join sees
 * nothing. Committing (like the canonical Social/Integration/TagsTest) is the only
 * way to exercise this. Created rows are cleaned up in tearDown.
 */
class RotateHomepageTagCommandTest extends TestCase
{
    protected Apps $kanvasApp;

    /** @var array<int, int> */
    private array $createdProductIds = [];

    /** @var array<int, int> */
    private array $createdCategoryIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        (new InventorySetup($this->kanvasApp, $user, $user->getCurrentCompany()))->run();
    }

    protected function tearDown(): void
    {
        try {
            if (! empty($this->createdProductIds)) {
                DB::connection('social')->table('tags_entities')
                    ->where('taggable_type', Products::class)
                    ->whereIn('entity_id', $this->createdProductIds)
                    ->delete();
                DB::connection('inventory')->table('products_categories')
                    ->whereIn('products_id', $this->createdProductIds)
                    ->delete();
                DB::connection('inventory')->table('products')
                    ->whereIn('id', $this->createdProductIds)
                    ->update(['is_deleted' => 1]);
            }
            if (! empty($this->createdCategoryIds)) {
                DB::connection('inventory')->table('categories')
                    ->whereIn('id', $this->createdCategoryIds)
                    ->update(['is_deleted' => 1]);
            }
        } catch (Throwable) {
            // best-effort cleanup on a shared DB; never fail the test on teardown
        }

        parent::tearDown();
    }

    private function makeCategory(string $name): int
    {
        /** @var Users $user */
        $user = auth()->user();

        $category = new CreateCategory(
            new CategoriesDto(
                app: $this->kanvasApp,
                company: $user->getCurrentCompany(),
                user: $user,
                name: $name . uniqid(),
                weight: 1,
            ),
            $user
        )->execute();

        $this->createdCategoryIds[] = $category->getId();

        return $category->getId();
    }

    private function makeProductInCategory(int $categoryId, string $name): Products
    {
        /** @var Users $user */
        $user = auth()->user();

        $product = new CreateProductAction(
            new ProductDto(
                app: $this->kanvasApp,
                company: $user->getCurrentCompany(),
                user: $user,
                name: $name . uniqid(),
            ),
            $user
        )->execute();

        $product->categories()->attach($categoryId);
        $this->createdProductIds[] = $product->getId();

        return $product;
    }

    public function testRotatesHomepageTagPerCategory(): void
    {
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $categoryId = $this->makeCategory('Homepage Cat ');

        $products = [];
        for ($i = 0; $i < 8; $i++) {
            $products[] = $this->makeProductInCategory($categoryId, 'Homepage Product ' . $i . '-');
        }

        // Seed the current homepage selection with mixed casing so the raw LOWER() match is exercised.
        $products[0]->addTag('Homepage', $this->kanvasApp, $user, $company);
        $products[1]->addTag('homepage', $this->kanvasApp, $user, $company);

        $this->artisan('kanvas:scrapper-rotate-homepage-tag', [
            'app_id' => $this->kanvasApp->getId(),
            'company_id' => $company->getId(),
            '--count' => 5,
        ])->assertExitCode(0);

        $tagged = collect($products)->filter(
            fn (Products $product) => Products::find($product->getId())->hasTag(['Homepage', 'homepage'])
        );

        // Old-selection casing was cleared and exactly the requested count now carries the tag.
        $this->assertCount(5, $tagged);
    }

    public function testSkipsCategoryWithoutEnoughProducts(): void
    {
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $categoryId = $this->makeCategory('Sparse Cat ');

        for ($i = 0; $i < 2; $i++) {
            $this->makeProductInCategory($categoryId, 'Sparse Product ' . $i . '-');
        }

        $this->artisan('kanvas:scrapper-rotate-homepage-tag', [
            'app_id' => $this->kanvasApp->getId(),
            'company_id' => $company->getId(),
            '--count' => 5,
        ])
            ->expectsOutputToContain('need 5')
            ->assertExitCode(0);
    }
}
