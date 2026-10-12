<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\Contracts\InsuranceProviderInterface;
use Kanvas\Insurance\Contracts\ProductCatalogProviderInterface;
use Kanvas\Insurance\DataTransferObject\InsuranceProduct;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Services\InsuranceProductSlug;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;

class SyncInsuranceProductsAction
{
    public const PRODUCT_TYPE_SLUG = 'insurance-plan';

    public function __construct(
        protected InsuranceProviderInterface&ProductCatalogProviderInterface $provider,
        protected Apps $app,
        protected Companies $insurerCompany,
    ) {
    }

    public function execute(): array
    {
        $user = $this->insurerCompany->user;

        if ($user === null) {
            throw new ValidationException(
                'Insurer company ' . $this->insurerCompany->getId() . ' has no owner user to create products as'
            );
        }

        $productType = $this->resolveProductType($user);
        $created = [];

        foreach ($this->provider->products() as $product) {
            $slug = $this->slug($product);
            $existing = $this->findExisting($slug);

            if ($existing !== null) {
                $this->applyAvailability($existing, $product);

                continue;
            }

            $new = new CreateProductAction(
                new ProductDto(
                    app: $this->app,
                    company: $this->insurerCompany,
                    user: $user,
                    name: $product->name,
                    description: $product->description,
                    productsType: $productType,
                    is_published: $product->isAvailable,
                    sku: $slug,
                    attributes: $this->attributes($product),
                    slug: $slug,
                ),
                $user,
            )->setRunWorkflow(false)->execute();

            $this->applyAvailability($new, $product);
            $created[] = $new;
        }

        return $created;
    }

    protected function applyAvailability(Products $product, InsuranceProduct $definition): void
    {
        if ((bool) $product->is_published === $definition->isAvailable) {
            return;
        }

        $product->is_published = $definition->isAvailable;

        if ($definition->isAvailable && $product->published_at === null) {
            $product->published_at = Carbon::now();
        }

        $product->saveQuietly();
    }

    protected function attributes(InsuranceProduct $product): array
    {
        return [
            ['name' => InsuranceCustomFieldEnum::PROVIDER->value, 'value' => $this->provider->name()],
            ['name' => InsuranceCustomFieldEnum::PRODUCT_CODE->value, 'value' => $product->code],
            [
                'name' => InsuranceCustomFieldEnum::REQUIRES_INSPECTION->value,
                'value' => $product->requiresInspection ? '1' : '0',
            ],
        ];
    }

    protected function slug(InsuranceProduct $product): string
    {
        return InsuranceProductSlug::for($this->provider->name(), $product->code);
    }

    protected function findExisting(string $slug): ?Products
    {
        return Products::where([
            'slug' => $slug,
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->insurerCompany->getId(),
        ])->first();
    }

    protected function resolveProductType(UserInterface $user): ProductsTypes
    {
        return ProductsTypes::firstOrCreate(
            [
                'slug' => self::PRODUCT_TYPE_SLUG,
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->insurerCompany->getId(),
            ],
            [
                'name' => 'Insurance Plan',
                'users_id' => $user->getId(),
                'weight' => 0,
            ]
        );
    }
}
