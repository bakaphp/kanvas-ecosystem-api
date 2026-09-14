<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Traits;

use Kanvas\Inventory\Categories\Actions\CreateCategory;
use Kanvas\Inventory\Categories\DataTransferObject\Categories as CategoriesDto;
use Kanvas\Inventory\ProductsTypes\Actions\CreateProductTypeAction;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypes as ProductsTypesDto;
use Throwable;

/**
 * Creating the buckets products get filed into. set_product_categories and the product_type_id
 * params can only reference what already exists, so without these an agent asked to file something
 * under a shelf the tenant does not have yet simply stops.
 *
 * Both domain actions are firstOrCreate on (company, app, slug), so calling twice with the same name
 * returns the existing row rather than a duplicate — the response says which happened, because a
 * model that cannot tell "created" from "already there" will keep retrying.
 */
trait ManagesCatalogTaxonomy
{
    use ResolvesCatalogEntities;

    /**
     * @return array<string, mixed>
     */
    protected function createCatalogCategory(
        string $name,
        ?int $parentId = null,
        ?string $description = null,
        ?string $code = null,
        ?bool $isPublished = null,
    ): array {
        $actor = $this->resolveCatalogActor();
        if (is_array($actor)) {
            return $actor;
        }

        $name = trim($name);

        if ($name === '') {
            return $this->catalogError('A category needs a name. Retry with one.', 'created');
        }

        if ($parentId !== null) {
            $parent = $this->resolveCatalogCategory($parentId);

            if (is_array($parent)) {
                return $parent;
            }
        }

        try {
            $category = new CreateCategory(
                new CategoriesDto(
                    app: $this->app,
                    company: $this->company,
                    user: $actor,
                    name: $name,
                    parent_id: $parentId,
                    is_published: $isPublished ?? true,
                    code: $code,
                    description: $description,
                ),
                $actor,
            )->execute();
        } catch (Throwable $e) {
            report($e);

            return $this->catalogError('Could not create the category: ' . $e->getMessage(), 'created');
        }

        return [
            'created' => $category->wasRecentlyCreated,
            'category_id' => (int) $category->getId(),
            'name' => $category->name,
            'slug' => $category->slug,
            'parent_id' => $category->parent_id,
            'is_published' => (bool) $category->is_published,
            'message' => $category->wasRecentlyCreated
                ? sprintf(
                    'Category "%s" created. Use set_product_categories to file products under it.',
                    $category->name,
                )
                : sprintf('A category "%s" already existed, so that one is being used.', $category->name),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function createCatalogProductType(
        string $name,
        ?string $description = null,
        ?bool $isPublished = null,
    ): array {
        $actor = $this->resolveCatalogActor();
        if (is_array($actor)) {
            return $actor;
        }

        $name = trim($name);

        if ($name === '') {
            return $this->catalogError('A product type needs a name. Retry with one.', 'created');
        }

        try {
            $productType = new CreateProductTypeAction(
                new ProductsTypesDto(
                    company: $this->company,
                    user: $actor,
                    name: $name,
                    description: $description,
                    isPublished: $isPublished ?? true,
                ),
                $actor,
            )->execute();
        } catch (Throwable $e) {
            report($e);

            return $this->catalogError('Could not create the product type: ' . $e->getMessage(), 'created');
        }

        return [
            'created' => $productType->wasRecentlyCreated,
            'product_type_id' => (int) $productType->getId(),
            'name' => $productType->name,
            'slug' => $productType->slug,
            'message' => $productType->wasRecentlyCreated
                ? sprintf(
                    'Product type "%s" created. Pass its id as product_type_id on create_product or '
                        . 'update_product.',
                    $productType->name,
                )
                : sprintf('A product type "%s" already existed, so that one is being used.', $productType->name),
        ];
    }
}
