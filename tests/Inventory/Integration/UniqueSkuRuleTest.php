<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration;

use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Support\Setup;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Validations\UniqueSkuRule;
use Tests\TestCase;

final class UniqueSkuRuleTest extends TestCase
{
    public function testAllowsBrandNewSku(): void
    {
        [$app, $company] = $this->bootstrapInventory();

        $failed = false;
        new UniqueSkuRule($app, $company)->validate(
            'sku',
            'brand-new-sku-' . uniqid(),
            function () use (&$failed) {
                $failed = true;
            }
        );

        $this->assertFalse($failed, 'UniqueSkuRule should pass for an unused SKU');
    }

    public function testRejectsDuplicateActiveSku(): void
    {
        [$app, $company, $existingSku] = $this->createProductWithSku();

        $failed = false;
        new UniqueSkuRule($app, $company)->validate(
            'sku',
            $existingSku,
            function () use (&$failed) {
                $failed = true;
            }
        );

        $this->assertTrue($failed, 'UniqueSkuRule must fail when the SKU is in use by an active variant');
    }

    public function testAllowsSkuOfSoftDeletedVariant(): void
    {
        [$app, $company, $existingSku, $product] = $this->createProductWithSku();

        $this->softDeleteProductWithVariants($product);

        $failed = false;
        new UniqueSkuRule($app, $company)->validate(
            'sku',
            $existingSku,
            function () use (&$failed) {
                $failed = true;
            }
        );

        $this->assertFalse(
            $failed,
            'UniqueSkuRule must ignore soft-deleted variants so the SKU can be reused'
        );
    }

    public function testExcludesCurrentVariantWhenUpdating(): void
    {
        [$app, $company, $existingSku, $product] = $this->createProductWithSku();
        $variant = $product->variants->first();

        $failed = false;
        new UniqueSkuRule($app, $company, $variant)->validate(
            'sku',
            $existingSku,
            function () use (&$failed) {
                $failed = true;
            }
        );

        $this->assertFalse($failed, 'UniqueSkuRule must ignore the variant being updated');
    }

    public function testCanCreateNewProductWithSkuOfSoftDeletedOne(): void
    {
        [$app, $company, $user] = $this->bootstrapInventory();
        $sku = 'reused-sku-' . uniqid();

        $firstProduct = new CreateProductAction(
            new Product(
                app: $app,
                company: $company,
                user: $user,
                name: fake()->name(),
                sku: $sku,
                warehouses: [[
                    'quantity' => 1,
                    'price' => 0.1,
                ]],
            ),
            $user,
        )->execute();

        $this->softDeleteProductWithVariants($firstProduct);

        $secondProduct = new CreateProductAction(
            new Product(
                app: $app,
                company: $company,
                user: $user,
                name: fake()->name(),
                sku: $sku,
                warehouses: [[
                    'quantity' => 1,
                    'price' => 0.1,
                ]],
            ),
            $user,
        )->execute();

        $this->assertNotSame(
            $firstProduct->getId(),
            $secondProduct->getId(),
            'A new product should be created when reusing the SKU of a soft-deleted one'
        );
        $this->assertSame(
            $sku,
            $secondProduct->variants->first()->sku,
            'The new variant should carry the reused SKU'
        );
        $this->assertSame(0, (int) $secondProduct->variants->first()->is_deleted);
    }

    public function testStillBlocksSkuWhenOnlyProductSoftDeletedAndVariantActive(): void
    {
        [$app, $company, $user] = $this->bootstrapInventory();
        $sku = 'orphan-variant-sku-' . uniqid();

        $firstProduct = new CreateProductAction(
            new Product(
                app: $app,
                company: $company,
                user: $user,
                name: fake()->name(),
                sku: $sku,
                warehouses: [[
                    'quantity' => 1,
                    'price' => 0.1,
                ]],
            ),
            $user,
        )->execute();

        \Illuminate\Support\Facades\DB::connection('inventory')
            ->table('products')
            ->where('id', $firstProduct->getId())
            ->update(['is_deleted' => 1]);

        $this->expectException(ValidationException::class);

        new CreateProductAction(
            new Product(
                app: $app,
                company: $company,
                user: $user,
                name: fake()->name(),
                sku: $sku,
                warehouses: [[
                    'quantity' => 1,
                    'price' => 0.1,
                ]],
            ),
            $user,
        )->execute();
    }

    private function softDeleteProductWithVariants(Products $product): void
    {
        Variants::withoutEvents(function () use ($product) {
            foreach ($product->variants as $variant) {
                $variant->delete();
            }
        });
        $product->delete();
    }

    private function bootstrapInventory(): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        new Setup($app, $user, $company)->run();

        return [$app, $company, $user];
    }

    private function createProductWithSku(): array
    {
        [$app, $company, $user] = $this->bootstrapInventory();

        $sku = 'unique-sku-rule-' . uniqid();

        $product = new CreateProductAction(
            new Product(
                app: $app,
                company: $company,
                user: $user,
                name: fake()->name(),
                sku: $sku,
                warehouses: [[
                    'quantity' => 1,
                    'price' => 0.1,
                ]],
            ),
            $user,
        )->execute();

        return [$app, $company, $sku, $product];
    }
}
