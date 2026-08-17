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
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;

/**
 * A seed, not a sync: the copy on these rows is ours and has no upstream to
 * reconcile against, so a re-run only fills in what is missing. That is why
 * CreateProductAction — an upsert that rewrites name and description — is never
 * reached for a row that already exists.
 */
class SyncInsuranceProductsAction
{
    public const PRODUCT_TYPE_SLUG = 'insurance-plan';

    public function __construct(
        protected InsuranceProviderInterface&ProductCatalogProviderInterface $provider,
        protected Apps $app,
        protected Companies $insurerCompany,
    ) {
    }

    /**
     * @return list<Products>
     */
    public function execute(): array
    {
        // Products are owned by the insurer, so they are created as the insurer's own
        // user — CreateProductAction asserts the user belongs to the company, which
        // whoever configured the integration on the aliado's side would not.
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

    /**
     * Unlike the copy, publish state mirrors a hard capability, so it is reconciled
     * every run: a line the insurer won't let us issue must not be sellable, and one
     * that becomes issuable later lights up keeping its id, copy and history.
     *
     * Set explicitly because CreateProductAction only honours `is_published` for a
     * user with the ability, and the column defaults to 0.
     */
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

    /**
     * @return list<array{name: string, value: string}>
     */
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

    /**
     * Provider-prefixed so two insurers selling the same code cannot collide, and
     * stable so a re-run recognises what it wrote last time.
     */
    protected function slug(InsuranceProduct $product): string
    {
        return $this->provider->name() . '-' . strtolower(str_replace(['_', ' '], '-', $product->code));
    }

    /**
     * Deliberately not filtered by notDeleted: CreateProductAction matches on the
     * same three columns, so a soft-deleted row still owns the slug and recreating
     * it would collide.
     */
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
