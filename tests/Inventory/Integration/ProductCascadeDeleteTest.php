<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Support\Setup;
use Kanvas\Inventory\Variants\Factories\VariantFactory;
use Kanvas\Inventory\Variants\Models\Variants;
use Tests\TestCase;

final class ProductCascadeDeleteTest extends TestCase
{
    public function testDeleteProductCascadesToVariants(): void
    {
        $product = $this->createProductWithExtraVariants(1);
        $variantIds = $product->variants->pluck('id')->all();

        $this->assertGreaterThanOrEqual(2, count($variantIds));

        Variants::withoutEvents(function () use ($variantIds) {
            foreach ($variantIds as $id) {
                Variants::find($id)->delete();
            }
        });
        $product->delete();

        $this->assertSame(1, (int) Products::withTrashed()->find($product->getId())->is_deleted);
        foreach ($variantIds as $id) {
            $this->assertSame(
                1,
                (int) Variants::withTrashed()->find($id)->is_deleted,
                "Variant {$id} must be soft-deleted together with the product"
            );
        }
    }

    public function testCascadeIsSoftNotHard(): void
    {
        $product = $this->createProductWithExtraVariants(1);
        $variantIds = $product->variants->pluck('id')->all();

        Variants::withoutEvents(function () use ($variantIds) {
            foreach ($variantIds as $id) {
                Variants::find($id)->delete();
            }
        });
        $product->delete();

        $this->assertNotNull(
            Products::withTrashed()->find($product->getId()),
            'Product row must still exist after delete (soft, not hard)'
        );
        foreach ($variantIds as $id) {
            $this->assertNotNull(
                Variants::withTrashed()->find($id),
                "Variant {$id} row must still exist after cascade (soft, not hard)"
            );
        }
    }

    public function testCascadedVariantsAreHiddenFromNotDeletedScope(): void
    {
        $product = $this->createProductWithExtraVariants(1);
        $variantIds = $product->variants->pluck('id')->all();

        Variants::withoutEvents(function () use ($variantIds) {
            foreach ($variantIds as $id) {
                Variants::find($id)->delete();
            }
        });
        $product->delete();

        foreach ($variantIds as $id) {
            $this->assertNull(
                Variants::notDeleted()->find($id),
                "Variant {$id} must be excluded by the notDeleted scope after delete"
            );
        }
    }

    public function testCascadeHandlesMultipleExtraVariants(): void
    {
        $product = $this->createProductWithExtraVariants(3);
        $variantIds = $product->variants->pluck('id')->all();

        $this->assertGreaterThanOrEqual(4, count($variantIds));

        Variants::withoutEvents(function () use ($variantIds) {
            foreach ($variantIds as $id) {
                Variants::find($id)->delete();
            }
        });
        $product->delete();

        foreach ($variantIds as $id) {
            $this->assertSame(
                1,
                (int) Variants::withTrashed()->find($id)->is_deleted,
                "Variant {$id} must be soft-deleted"
            );
        }
    }

    public function testSoftDeleteFlipsIsDeletedWithoutCascading(): void
    {
        $product = $this->createProductWithExtraVariants(1);
        $variantIds = $product->variants->pluck('id')->all();

        $result = $product->softDelete();

        $this->assertTrue($result, 'softDelete() must return true on success');
        $this->assertSame(
            1,
            (int) Products::withTrashed()->find($product->getId())->is_deleted,
            'softDelete() must actually flip is_deleted on the product. '
            . 'A regression here likely means protected $is_deleted; was re-introduced '
            . 'on the Products model and is shadowing Eloquent attribute magic.'
        );
        foreach ($variantIds as $id) {
            $this->assertSame(
                0,
                (int) Variants::withTrashed()->find($id)->is_deleted,
                "Variant {$id} must stay active: softDelete() fires softDeleting, "
                . 'not Laravel deleting, so CascadeSoftDeletes does not run.'
            );
        }
    }

    public function testVariantSoftDeleteFlipsIsDeleted(): void
    {
        $product = $this->createProductWithExtraVariants(1);
        $variant = $product->variants->first();

        $result = $variant->softDelete();

        $this->assertTrue($result);
        $this->assertSame(
            1,
            (int) Variants::withTrashed()->find($variant->getId())->is_deleted,
            'Variants::softDelete() must flip is_deleted. '
            . 'A regression here means protected $is_deleted; was re-introduced on Variants.'
        );
    }

    public function testCascadeConfigurationIsDeclared(): void
    {
        $product = new Products();
        $reflection = new \ReflectionClass($product);
        $cascadeDeletes = $reflection->getProperty('cascadeDeletes')->getValue($product);

        $this->assertContains(
            'variants',
            $cascadeDeletes,
            'Products must declare variants in $cascadeDeletes so CascadeSoftDeletes runs on delete'
        );
        $this->assertTrue(
            in_array(
                \Dyrynda\Database\Support\CascadeSoftDeletes::class,
                class_uses_recursive($product),
                true
            ),
            'Products must use the CascadeSoftDeletes trait'
        );
    }

    private function createProductWithExtraVariants(int $extraVariants): Products
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new Setup($app, $user, $company)->run();

        $product = new CreateProductAction(
            new Product(
                app: $app,
                company: $company,
                user: $user,
                name: fake()->name(),
                sku: 'cascade-' . uniqid(),
                warehouses: [[
                    'quantity' => 1,
                    'price' => 0.1,
                ]],
            ),
            $user,
        )->execute();

        if ($extraVariants > 0) {
            VariantFactory::new()
                ->withProductId($product->getId())
                ->withAppId($app->getId())
                ->withCompanyId($company->getId())
                ->withUserId($user->getId())
                ->count($extraVariants)
                ->create();
        }

        return $product->refresh()->load('variants');
    }
}
