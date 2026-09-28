<?php

declare(strict_types=1);

namespace Tests\Inventory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\Actions\UpdateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Actions\UpdateVariantsAction;
use Kanvas\Inventory\Variants\DataTransferObject\Variants as VariantsDto;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * Covers the DTO `files` field on the update actions: passing `files: [['url' => ..., 'name' => ...]]`
 * to UpdateProductAction / UpdateVariantsAction attaches the images to the entity, and an update with
 * an empty `files` array leaves the existing files untouched (no destructive overwrite).
 */
class UpdateProductVariantFilesActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'inventory'];

    protected function setUp(): void
    {
        parent::setUp();

        // The seeded app has no per-app engine override, so Scout resolves the engine from
        // config('scout.driver') (typesense in .env). Neutralize it to NullEngine so product/variant
        // saves and the explicit searchable() calls don't hit a live index — we only assert file attach.
        config(['scout.driver' => 'null']);
    }

    public function testUpdateProductAttachesFilesFromDto(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new InventorySetup($app, $user, $company)->run();

        $product = new CreateProductAction(
            new ProductDto(
                app: $app,
                company: $company,
                user: $user,
                name: 'UpdateProductFiles-' . uniqid(),
            ),
            $user
        )->execute();

        $fileUrl = 'https://example.com/product-' . uniqid() . '.png';

        new UpdateProductAction(
            $product,
            new ProductDto(
                app: $app,
                company: $company,
                user: $user,
                name: $product->name,
                files: [['url' => $fileUrl, 'name' => 'product.png']],
            ),
            $user
        )->execute();

        $this->assertEqualsCanonicalizing(
            [$fileUrl],
            $product->getFiles()->pluck('url')->all()
        );
    }

    public function testUpdateProductWithEmptyFilesKeepsExistingFiles(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new InventorySetup($app, $user, $company)->run();

        $product = new CreateProductAction(
            new ProductDto(
                app: $app,
                company: $company,
                user: $user,
                name: 'UpdateProductKeepFiles-' . uniqid(),
            ),
            $user
        )->execute();

        $fileUrl = 'https://example.com/product-' . uniqid() . '.png';
        $product->addFileFromUrl($fileUrl, 'product.png');

        new UpdateProductAction(
            $product,
            new ProductDto(
                app: $app,
                company: $company,
                user: $user,
                name: 'RenamedProduct-' . uniqid(),
                files: [],
            ),
            $user
        )->execute();

        $this->assertEqualsCanonicalizing(
            [$fileUrl],
            $product->getFiles()->pluck('url')->all()
        );
    }

    public function testUpdateVariantAttachesFilesFromDto(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new InventorySetup($app, $user, $company)->run();

        $product = new CreateProductAction(
            new ProductDto(
                app: $app,
                company: $company,
                user: $user,
                name: 'UpdateVariantFiles-' . uniqid(),
            ),
            $user
        )->execute();

        /** @var Variants $variant */
        $variant = $product->variants()->where('is_deleted', 0)->firstOrFail();

        $fileUrl = 'https://example.com/variant-' . uniqid() . '.png';

        new UpdateVariantsAction(
            $variant,
            new VariantsDto(
                product: $product,
                name: $variant->name,
                sku: $variant->sku,
                files: [['url' => $fileUrl, 'name' => 'variant.png']],
            ),
            $user
        )->disableWorkflow()->execute();

        $this->assertEqualsCanonicalizing(
            [$fileUrl],
            $variant->getFiles()->pluck('url')->all()
        );
    }

    public function testUpdateVariantWithEmptyFilesKeepsExistingFiles(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new InventorySetup($app, $user, $company)->run();

        $product = new CreateProductAction(
            new ProductDto(
                app: $app,
                company: $company,
                user: $user,
                name: 'UpdateVariantKeepFiles-' . uniqid(),
            ),
            $user
        )->execute();

        /** @var Variants $variant */
        $variant = $product->variants()->where('is_deleted', 0)->firstOrFail();

        $fileUrl = 'https://example.com/variant-' . uniqid() . '.png';
        $variant->addFileFromUrl($fileUrl, 'variant.png');

        new UpdateVariantsAction(
            $variant,
            new VariantsDto(
                product: $product,
                name: 'RenamedVariant-' . uniqid(),
                sku: $variant->sku,
                files: [],
            ),
            $user
        )->disableWorkflow()->execute();

        $this->assertEqualsCanonicalizing(
            [$fileUrl],
            $variant->getFiles()->pluck('url')->all()
        );
    }

    public function testOverwriteVariantFilesWithSoftDeleteRemovesOmittedFiles(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new InventorySetup($app, $user, $company)->run();

        $product = new CreateProductAction(
            new ProductDto(
                app: $app,
                company: $company,
                user: $user,
                name: 'OverwriteVariantPrune-' . uniqid(),
            ),
            $user
        )->execute();

        /** @var Variants $variant */
        $variant = $product->variants()->where('is_deleted', 0)->firstOrFail();

        $oldFileUrl = 'https://example.com/variant-old-' . uniqid() . '.png';
        $variant->addFileFromUrl($oldFileUrl, 'old.png');

        $newFileUrl = 'https://example.com/variant-new-' . uniqid() . '.png';

        // softDelete: true — this is the VariantService replace path: files not in the new set are pruned.
        $variant->overWriteFiles(
            [['url' => $newFileUrl, 'name' => 'new.png']],
            $app,
            true
        );

        $this->assertEqualsCanonicalizing(
            [$newFileUrl],
            $variant->getFiles()->pluck('url')->all()
        );
    }

    public function testOverwriteVariantFilesWithoutSoftDeleteKeepsOmittedFiles(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new InventorySetup($app, $user, $company)->run();

        $product = new CreateProductAction(
            new ProductDto(
                app: $app,
                company: $company,
                user: $user,
                name: 'OverwriteVariantKeep-' . uniqid(),
            ),
            $user
        )->execute();

        /** @var Variants $variant */
        $variant = $product->variants()->where('is_deleted', 0)->firstOrFail();

        $oldFileUrl = 'https://example.com/variant-old-' . uniqid() . '.png';
        $variant->addFileFromUrl($oldFileUrl, 'old.png');

        $newFileUrl = 'https://example.com/variant-new-' . uniqid() . '.png';

        // softDelete defaults to false — the omitted old file survives, the new one is added alongside it.
        $variant->overWriteFiles([['url' => $newFileUrl, 'name' => 'new.png']], $app);

        $this->assertEqualsCanonicalizing(
            [$oldFileUrl, $newFileUrl],
            $variant->getFiles()->pluck('url')->all()
        );
    }

    public function testOverwriteProductFilesWithSoftDeleteRemovesOmittedFiles(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new InventorySetup($app, $user, $company)->run();

        $product = new CreateProductAction(
            new ProductDto(
                app: $app,
                company: $company,
                user: $user,
                name: 'OverwriteProductPrune-' . uniqid(),
            ),
            $user
        )->execute();

        $oldFileUrl = 'https://example.com/product-old-' . uniqid() . '.png';
        $product->addFileFromUrl($oldFileUrl, 'old.png');

        $newFileUrl = 'https://example.com/product-new-' . uniqid() . '.png';

        $product->overWriteFiles(
            [['url' => $newFileUrl, 'name' => 'new.png']],
            $app,
            true
        );

        $this->assertEqualsCanonicalizing(
            [$newFileUrl],
            $product->getFiles()->pluck('url')->all()
        );
    }
}
