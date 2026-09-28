<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Traits;

use Baka\Enums\StateEnums;
use Baka\Support\Str;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\ProductsTypes\Repositories\ProductsTypesRepository;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Tenant-scoped lookups for the catalog CRUD tools, shared by the Neuron and Laravel-AI sides.
 * Host needs either framework's HasKanvasContext — both expose $app, $company and contextUser().
 *
 * Every lookup goes through getByIdFromCompanyApp, so a hallucinated id resolves to nothing rather
 * than another tenant's row, and each returns either the model or a structured error the model can
 * act on. The Neuron-only ResolvesProductForTool / ResolvesVariantForTool traits do the same for
 * the read tools; these live here because the Laravel mirrors need them too.
 */
trait ResolvesCatalogEntities
{
    /**
     * The one error shape the catalog tools speak. Hand-writing the literal at each of the ~35 return
     * sites is how a family drifts into three dialects, and the model then cannot tell a refusal from
     * a failure. `$outcome` is the tool's own falsey flag — created/updated/deleted — so a caller
     * reads the outcome it was already checking.
     *
     * @return array<string, mixed>
     */
    protected function catalogError(string $message, ?string $outcome = null): array
    {
        return [
            ...($outcome === null ? [] : [$outcome => false]),
            'status' => 'error',
            'message' => $message,
        ];
    }

    /**
     * @return Products|array{status: string, message: string}
     */
    protected function resolveCatalogProduct(int $productId): Products|array
    {
        try {
            /** @var Products $product */
            $product = Products::getByIdFromCompanyApp($productId, $this->company, $this->app);

            return $product;
        } catch (ModelNotFoundException) {
            return $this->catalogError(
                "Product {$productId} does not exist in this company. You invented this product_id — "
                    . 'never do that. Use list_available_products or inventory_search to find the real '
                    . 'product_id, then retry this tool with it.',
            );
        }
    }

    /**
     * @return Variants|array{status: string, message: string}
     */
    protected function resolveCatalogVariant(int $variantId): Variants|array
    {
        try {
            /** @var Variants $variant */
            $variant = Variants::getByIdFromCompanyApp($variantId, $this->company, $this->app);

            return $variant;
        } catch (ModelNotFoundException) {
            return $this->catalogError(
                "Variant {$variantId} does not exist in this company. You invented this variant_id — "
                    . 'never do that. Use variant_search or variant_detail to find the real variant_id, then '
                    . 'retry this tool with it.',
            );
        }
    }

    /**
     * The warehouse a stock/price write lands on. Null id means the company's default warehouse,
     * which is what a model that never asked about warehouses should get.
     *
     * @return Warehouses|array{status: string, message: string}
     */
    protected function resolveCatalogWarehouse(?int $warehouseId): Warehouses|array
    {
        if ($warehouseId !== null) {
            try {
                /** @var Warehouses $warehouse */
                $warehouse = Warehouses::getByIdFromCompanyApp($warehouseId, $this->company, $this->app);

                return $warehouse;
            } catch (ModelNotFoundException) {
                return $this->catalogError(
                    "Warehouse {$warehouseId} does not exist in this company. Omit warehouse_id to "
                        . "use the company's default warehouse.",
                );
            }
        }

        $default = Warehouses::getDefault($this->company, $this->app);

        if (! $default instanceof Warehouses) {
            return $this->catalogError(
                'This company has no default warehouse, so there is nowhere to store stock or price. '
                    . 'Ask an administrator to create one, then retry with an explicit warehouse_id.',
            );
        }

        return $default;
    }

    /**
     * The channel a price write lands on. Null id means the tenant's default channel — resolved with
     * the same criteria Variants::getPriceInfoFromDefaultChannel() uses, because that is what the
     * cart reads; matching on is_default alone would happily return a channel the cart never sees.
     *
     * @return Channels|array{status: string, message: string}
     */
    protected function resolveCatalogChannel(?int $channelId): Channels|array
    {
        if ($channelId !== null) {
            try {
                /** @var Channels $channel */
                $channel = Channels::getByIdFromCompanyApp($channelId, $this->company, $this->app);

                return $channel;
            } catch (ModelNotFoundException) {
                return $this->catalogError(
                    "Channel {$channelId} does not exist in this company. Use list_channels to see the "
                        . "real ones, or omit channel_id to use the company's default channel.",
                );
            }
        }

        $default = Channels::fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->where('is_default', true)
            ->where('is_published', StateEnums::ON->getValue())
            ->first();

        if (! $default instanceof Channels) {
            return $this->catalogError(
                'This company has no published default channel, so there is nowhere to put a selling '
                    . 'price. Ask an administrator to create one, then retry with an explicit channel_id.',
            );
        }

        return $default;
    }

    /**
     * Attributes are addressed by name everywhere in the catalog tools, because that is what a model
     * knows — ResolvesAttributesTrait creates one on write when the name is new. Removal has no such
     * escape hatch, so it needs the lookup: app-scoped, and widened to the platform-global rows
     * (companies_id 0) the same way attribute_search lists them.
     */
    protected function resolveCatalogAttributeByName(string $name): ?Attributes
    {
        // Matched on slug, not name: attributes.name is translatable and stored as {"en": "Colour"},
        // so an equality check against the plain name never hits. ResolvesAttributesTrait slugs the
        // name when it creates one, which makes the slug the stable handle for a name the model typed.
        return Attributes::fromApp($this->app)
            ->notDeleted()
            ->whereIn('companies_id', [0, $this->company->getId()])
            ->where('slug', Str::slug($name))
            ->first();
    }

    /**
     * @return Categories|array{status: string, message: string}
     */
    protected function resolveCatalogCategory(int $categoryId): Categories|array
    {
        try {
            /** @var Categories $category */
            $category = Categories::getByIdFromCompanyApp($categoryId, $this->company, $this->app);

            return $category;
        } catch (ModelNotFoundException) {
            return $this->catalogError(
                "Category {$categoryId} does not exist in this company. Use category_search to find "
                    . 'the real category ids, then retry.',
            );
        }
    }

    /**
     * Product types can be app-global (companies_id 0), so this goes through the repository's
     * global-aware lookup rather than the plain company-scoped one.
     *
     * @return ProductsTypes|array{status: string, message: string}
     */
    protected function resolveCatalogProductType(int $productTypeId): ProductsTypes|array
    {
        try {
            /** @var ProductsTypes $productType */
            $productType = ProductsTypesRepository::getByIdOrGlobal($productTypeId, $this->company, $this->app);

            return $productType;
        } catch (Throwable) {
            return $this->catalogError(
                "Product type {$productTypeId} does not exist for this company. Use "
                    . 'list_product_types to see the real ones, or omit product_type_id.',
            );
        }
    }

    /**
     * The domain actions attribute the write to a user and check they belong to the company, so a
     * run with no identified user can't be allowed to fall through to a null.
     *
     * @return Users|array{status: string, message: string}
     */
    protected function resolveCatalogActor(): Users|array
    {
        $user = $this->contextUser();

        if (! $user instanceof Users) {
            return $this->catalogError(
                'This run has no identified user, so the catalog write cannot be attributed. '
                    . 'Do not retry.',
            );
        }

        return $user;
    }
}
