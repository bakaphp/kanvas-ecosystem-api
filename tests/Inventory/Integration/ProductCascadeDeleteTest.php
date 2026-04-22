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
        $product = $this->createProductWithVariant();
        $variantId = $product->variants->first()->getId();

        $product->delete();

        $this->assertSame(1, (int) Products::withTrashed()->find($product->getId())->is_deleted);
        $this->assertSame(
            1,
            (int) Variants::withTrashed()->find($variantId)->is_deleted,
            'Variant must be soft-deleted via $cascadeDeletes when product->delete() runs'
        );
    }

    public function testCascadeIsSoftNotHard(): void
    {
        $product = $this->createProductWithVariant();
        $variantId = $product->variants->first()->getId();

        $product->delete();

        $this->assertNotNull(
            Products::withTrashed()->find($product->getId()),
            'Product row must still exist after delete (soft, not hard)'
        );
        $this->assertNotNull(
            Variants::withTrashed()->find($variantId),
            'Variant row must still exist after cascade (soft, not hard)'
        );
    }

    public function testCascadeRemovesVariantsFromNotDeletedScope(): void
    {
        $product = $this->createProductWithVariant();
        $variantId = $product->variants->first()->getId();

        $product->delete();

        $this->assertNull(
            Variants::notDeleted()->find($variantId),
            'notDeleted() scope must hide cascade-deleted variants'
        );
    }

    public function testCascadeWithMultipleVariants(): void
    {
        $product = $this->createProductWithVariant();
        $extra = VariantFactory::new()
            ->withProductId($product->getId())
            ->count(2)
            ->create()
            ->pluck('id')
            ->all();

        $variantIds = array_merge([$product->variants->first()->getId()], $extra);

        $product->delete();

        foreach ($variantIds as $variantId) {
            $this->assertSame(
                1,
                (int) Variants::withTrashed()->find($variantId)->is_deleted,
                "Variant {$variantId} must be cascade-deleted"
            );
        }
    }

    public function testSoftDeleteProductDoesNotCascadeToVariants(): void
    {
        $product = $this->createProductWithVariant();
        $variantId = $product->variants->first()->getId();

        $product->softDelete();

        $this->assertSame(1, (int) Products::withTrashed()->find($product->getId())->is_deleted);
        $this->assertSame(
            0,
            (int) Variants::withTrashed()->find($variantId)->is_deleted,
            'softDelete() fires the custom softDeleting event, not Laravel deleting. '
            . 'CascadeSoftDeletes does not run — callers must use delete() when cascade matters. '
            . 'This test locks that behavior so the orphan-variant bug does not regress silently.'
        );
    }

    private function createProductWithVariant(): Products
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

        $this->assertGreaterThan(0, $product->variants->count(), 'Product should be created with a default variant');

        return $product;
    }
}
