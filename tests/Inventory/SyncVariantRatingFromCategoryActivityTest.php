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
use Kanvas\Inventory\Variants\WorkflowActivity\SyncVariantRatingFromCategoryActivity;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\TestCase;

class SyncVariantRatingFromCategoryActivityTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['workflow'];

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
                name: 'Activity Test Product ' . uniqid(),
            ),
            $user
        )->execute();

        $this->variant = $this->product->variants()->where('is_deleted', 0)->first();
    }

    public function testVariantEntityRunsActionAndReturnsUpdated(): void
    {
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $category = (new CreateCategory(
            new CategoriesDto(
                app: $this->kanvasApp,
                company: $company,
                user: $user,
                name: 'Activity Cat ' . uniqid(),
                weight: 6,
            ),
            $user
        ))->execute();

        $this->product->categories()->attach($category->id);

        $activity = new SyncVariantRatingFromCategoryActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );
        $result = $activity->execute($this->variant, $this->kanvasApp, []);

        $this->assertSame('updated', $result['status']);
        $this->assertSame(6.0, $result['rating']);
    }

    public function testWrongEntityTypeReturnsSkipped(): void
    {
        $activity = new SyncVariantRatingFromCategoryActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );
        $result = $activity->execute($this->product, $this->kanvasApp, []);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('wrong_entity_type', $result['reason']);
        $this->assertSame(Products::class, $result['received']);
    }

    public function testCrossTenantVariantReturnsFailWorkflow(): void
    {
        $uniqueId = uniqid();

        // Insert a bare Apps row to get a different ID without full app setup
        $otherApp = new Apps();
        $otherApp->name = 'Other App ' . $uniqueId;
        $otherApp->url = 'https://other-' . $uniqueId . '.example.com';
        $otherApp->domain = 'other-' . $uniqueId . '.example.com';
        $otherApp->description = 'Cross-tenant test app';
        $otherApp->is_actived = 1;
        $otherApp->ecosystem_auth = 0;
        $otherApp->payments_active = 0;
        $otherApp->is_public = 1;
        $otherApp->domain_based = 0;
        $otherApp->save();

        // The variant belongs to $this->app; call the activity with a different app
        $activity = new SyncVariantRatingFromCategoryActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );
        $result = $activity->execute($this->variant, $otherApp, []);

        // failWorkflow returns the array as-is and internally sets status to FAILED
        $this->assertSame('error', $result['status']);
        $this->assertSame('Variant apps_id mismatch', $result['message']);
        $this->assertSame($this->variant->getId(), $result['variant_id']);
    }
}
