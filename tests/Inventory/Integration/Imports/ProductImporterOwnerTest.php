<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration\Imports;

use Kanvas\Apps\Models\Apps;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Actions\CreateProductTypeAction;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypes as ProductsTypesDto;
use Kanvas\Inventory\Regions\Actions\CreateRegionAction;
use Kanvas\Inventory\Regions\DataTransferObject\Region;
use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * Optional import owner: $ownerUser stamps products/variants users_id while the
 * acting user still gates publishing; no owner keeps the old behavior.
 */
final class ProductImporterOwnerTest extends TestCase
{
    public function testImportStampsOwnerOnProductAndVariantsWhenOwnerProvided(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $region = $this->makeRegion($user, $company, $app);
        $this->makeWarehouse($user, $company, $app, $region);
        $productType = $this->makeProductType($user, $company);

        $owner = Users::factory()->create();
        $slug = 'owner-prod-' . uniqid();
        $sku = 'OWNER-SKU-' . uniqid();

        new ProductImporterAction(
            ProductImporter::from([
                'name' => 'Owner Product',
                'slug' => $slug,
                'sku' => $sku,
                'description' => 'Owned by a different user',
                'variants' => [
                    ['name' => 'Owner Variant', 'sku' => $sku, 'price' => 10.0, 'quantity' => 5],
                ],
                'productType' => ['name' => $productType->name, 'weight' => 1],
            ]),
            $company,
            $user,
            $region,
            $app,
            false,
            $owner,
        )->execute();

        $product = Products::where('slug', $slug)
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->first();

        $this->assertNotNull($product);
        $this->assertSame(
            $owner->getId(),
            (int) $product->users_id,
            'Product users_id must be the selected owner, not the importer',
        );
        $this->assertTrue(
            (bool) $product->is_published,
            'Importer is admin, so the product still publishes even though the owner is a regular user',
        );

        $variants = $product->variants()->get();
        $this->assertGreaterThan(0, $variants->count());
        foreach ($variants as $variant) {
            $this->assertSame(
                $owner->getId(),
                (int) $variant->users_id,
                'Each variant users_id must be the selected owner',
            );
        }
    }

    public function testImportUsesActingUserWhenNoOwnerProvided(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $region = $this->makeRegion($user, $company, $app);
        $this->makeWarehouse($user, $company, $app, $region);
        $productType = $this->makeProductType($user, $company);

        $slug = 'default-owner-prod-' . uniqid();
        $sku = 'DEFAULT-SKU-' . uniqid();

        new ProductImporterAction(
            ProductImporter::from([
                'name' => 'Default Owner Product',
                'slug' => $slug,
                'sku' => $sku,
                'variants' => [
                    ['name' => 'Default Variant', 'sku' => $sku, 'price' => 10.0, 'quantity' => 5],
                ],
                'productType' => ['name' => $productType->name, 'weight' => 1],
            ]),
            $company,
            $user,
            $region,
            $app,
            false,
        )->execute();

        $product = Products::where('slug', $slug)
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->first();

        $this->assertNotNull($product);
        $this->assertSame(
            $user->getId(),
            (int) $product->users_id,
            'Without an owner the importer owns the product, exactly as before',
        );

        foreach ($product->variants()->get() as $variant) {
            $this->assertSame($user->getId(), (int) $variant->users_id);
        }
    }

    private function makeRegion($user, $company, $app): object
    {
        return new CreateRegionAction(
            new Region(
                $company,
                $app,
                $user,
                Currencies::getById(1),
                'Region ' . uniqid(),
                'r-' . uniqid(),
                null,
                1,
            ),
            $user,
        )->execute();
    }

    private function makeWarehouse($user, $company, $app, $region): void
    {
        new CreateWarehouseAction(
            new Warehouses(
                $company,
                $app,
                $user,
                $region,
                'Warehouse ' . uniqid(),
                'Test Location',
                true,
                true,
            ),
            $user,
        )->execute();
    }

    private function makeProductType($user, $company): object
    {
        return new CreateProductTypeAction(
            new ProductsTypesDto(
                $company,
                $user,
                'Owner Test Type ' . uniqid(),
                'Type used by the owner import test',
                1,
                true,
            ),
            $user,
        )->execute();
    }
}
