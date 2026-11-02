<?php

declare(strict_types=1);

namespace Tests\Insurance\Concerns;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductData;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Users\Models\Users;

trait CreatesVehicleProducts
{
    protected function createVehicleProduct(Users $owner, ?array $attributes = null, string $typeSlug = 'vehicle'): Products
    {
        $app = app(Apps::class);
        $creator = auth()->user();
        $company = $creator->getCurrentCompany();
        $type = ProductsTypes::firstOrCreate(
            ['slug' => $typeSlug, 'apps_id' => $app->getId(), 'companies_id' => $company->getId()],
            ['name' => ucfirst($typeSlug), 'users_id' => $creator->getId(), 'weight' => 0]
        );

        $product = new CreateProductAction(
            new ProductData(
                app: $app,
                company: $company,
                user: $creator,
                name: 'Toyota Corolla ' . uniqid(),
                productsType: $type,
                sku: 'vehicle-' . uniqid(),
                attributes: $attributes ?? [['name' => 'brand', 'value' => 'Toyota'], ['name' => 'model', 'value' => 'Corolla']],
            ),
            $creator,
        )->setRunWorkflow(false)->execute();

        $product->users_id = $owner->getId();
        $product->saveQuietly();

        return $product;
    }
}
