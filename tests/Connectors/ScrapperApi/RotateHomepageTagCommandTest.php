<?php

declare(strict_types=1);

namespace Tests\Connectors\ScrapperApi;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Categories\Actions\CreateCategory;
use Kanvas\Inventory\Categories\DataTransferObject\Categories as CategoriesDto;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class RotateHomepageTagCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'inventory', 'social'];

    protected Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        (new InventorySetup($this->kanvasApp, $user, $user->getCurrentCompany()))->run();
    }

    public function testRotatesHomepageTagPerCategory(): void
    {
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $category = new CreateCategory(
            new CategoriesDto(
                app: $this->kanvasApp,
                company: $company,
                user: $user,
                name: 'Homepage Cat ' . uniqid(),
                weight: 1,
            ),
            $user
        )->execute();

        $products = [];
        for ($i = 0; $i < 8; $i++) {
            $product = new CreateProductAction(
                new ProductDto(
                    app: $this->kanvasApp,
                    company: $company,
                    user: $user,
                    name: 'Homepage Product ' . $i . '-' . uniqid(),
                ),
                $user
            )->execute();

            $product->categories()->attach($category->id);
            $products[] = $product;
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

        $category = new CreateCategory(
            new CategoriesDto(
                app: $this->kanvasApp,
                company: $company,
                user: $user,
                name: 'Sparse Cat ' . uniqid(),
                weight: 1,
            ),
            $user
        )->execute();

        for ($i = 0; $i < 2; $i++) {
            $product = new CreateProductAction(
                new ProductDto(
                    app: $this->kanvasApp,
                    company: $company,
                    user: $user,
                    name: 'Sparse Product ' . $i . '-' . uniqid(),
                ),
                $user
            )->execute();

            $product->categories()->attach($category->id);
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
