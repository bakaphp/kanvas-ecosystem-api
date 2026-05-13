<?php

declare(strict_types=1);

namespace Tests\Inventory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Categories\Actions\CreateCategory;
use Kanvas\Inventory\Categories\DataTransferObject\Categories as CategoriesDto;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class BackfillVariantRatingCommandTest extends TestCase
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
        $company = $user->getCurrentCompany();

        (new InventorySetup($this->kanvasApp, $user, $company))->run();
    }

    public function testRunWithValidAppProcessesVariants(): void
    {
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Soft-delete any pre-existing variants on this app so the count is predictable
        Variants::fromApp($this->kanvasApp)->where('is_deleted', 0)->update(['is_deleted' => 1]);

        $category = (new CreateCategory(
            new CategoriesDto(
                app: $this->kanvasApp,
                company: $company,
                user: $user,
                name: 'Backfill Cat ' . uniqid(),
                weight: 8,
            ),
            $user
        ))->execute();

        for ($i = 0; $i < 3; $i++) {
            $product = new CreateProductAction(
                new ProductDto(
                    app: $this->kanvasApp,
                    company: $company,
                    user: $user,
                    name: 'Backfill Product ' . $i . '-' . uniqid(),
                ),
                $user
            )->execute();

            $product->categories()->attach($category->id);
        }

        $this->artisan(
            'kanvas-inventory:backfill-variant-rating-from-category',
            ['app_id' => $this->kanvasApp->getId()]
        )
            ->expectsOutputToContain('Done.')
            ->expectsOutputToContain('Processed=3')
            ->assertExitCode(0);

        $variants = Variants::fromApp($this->kanvasApp)->where('is_deleted', 0)->get();
        foreach ($variants as $variant) {
            $this->assertSame(8.0, (float) $variant->rating);

            // Verify rating was propagated to the product
            $product = Products::find($variant->products_id);
            $this->assertSame(8.0, (float) $product->rating, "Product {$product->id} rating should match variant rating");
        }
    }

    public function testRunWithUnknownAppReturnsFailure(): void
    {
        $this->artisan(
            'kanvas-inventory:backfill-variant-rating-from-category',
            ['app_id' => 999999999]
        )
            ->expectsOutputToContain('App 999999999 not found')
            ->assertExitCode(1);
    }

    public function testRunWithEmptyAppExitsSuccessWithZeroProcessed(): void
    {
        /** @var Users $user */
        $user = auth()->user();

        // Soft-delete all variants so this app has zero active ones
        Variants::fromApp($this->kanvasApp)->where('is_deleted', 0)->update(['is_deleted' => 1]);

        $this->artisan(
            'kanvas-inventory:backfill-variant-rating-from-category',
            ['app_id' => $this->kanvasApp->getId()]
        )
            ->expectsOutputToContain('Done.')
            ->expectsOutputToContain('Processed=0')
            ->assertExitCode(0);
    }
}
