<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Traits;

use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Products\Actions\AddCategoryAction;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\Actions\DuplicateProductAction;
use Kanvas\Inventory\Products\Actions\RemoveAttributeAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Throwable;

/**
 * Shared body of the create/update/delete product tools, on both the Neuron and the Laravel-AI side.
 * Host needs either framework's HasKanvasContext; createCatalogProduct also needs
 * ManagesCatalogVariants, because price and stock land on the default variant it creates.
 *
 * Creation goes through CreateProductAction, which also lays down the default variant and fires the
 * catalog workflow. Update deliberately does NOT go through UpdateProductAction: that action carries
 * full-replace semantics for the GraphQL mutation, which sends the whole product every time — it
 * nulls products_types_id whenever the DTO has no type, and it force-deletes and rewrites every
 * attribute value on every call. Both are silent data loss under a partial field edit, which is all
 * an LLM ever sends, so the update path writes just the columns it was handed and then repeats the
 * action's two side effects (search sync + workflow fire) by hand.
 */
trait ManagesCatalogProducts
{
    use NormalizesCatalogAttributes;
    use ResolvesCatalogEntities;

    /**
     * @return array<string, mixed>
     */
    protected function createCatalogProduct(
        string $name,
        ?string $description = null,
        ?string $shortDescription = null,
        ?string $sku = null,
        ?string $upc = null,
        ?float $weight = null,
        ?string $warrantyTerms = null,
        ?bool $isPublished = null,
        ?float $price = null,
        ?float $quantity = null,
        ?int $warehouseId = null,
        ?int $productTypeId = null,
    ): array {
        $actor = $this->resolveCatalogActor();
        if (is_array($actor)) {
            return $actor;
        }

        $name = trim($name);

        if ($name === '') {
            return $this->catalogError('A product needs a name. Retry with one.', 'created');
        }

        $productType = null;

        if ($productTypeId !== null) {
            $productType = $this->resolveCatalogProductType($productTypeId);

            if (is_array($productType)) {
                return $productType;
            }
        }

        try {
            $product = new CreateProductAction(
                new ProductDto(
                    app: $this->app,
                    company: $this->company,
                    user: $actor,
                    name: $name,
                    description: $description,
                    productsType: $productType,
                    short_description: $shortDescription,
                    warranty_terms: $warrantyTerms,
                    upc: $upc,
                    is_published: $isPublished ?? false,
                    sku: $sku,
                    weight: $weight,
                ),
                $actor,
            )->execute();
        } catch (Throwable $e) {
            report($e);

            return $this->catalogError('Could not create the product: ' . $e->getMessage(), 'created');
        }

        $response = [
            'created' => true,
            ...$this->presentCatalogProduct($product->refresh()),
            'message' => sprintf(
                'Product "%s" created as %s.',
                $product->name,
                $product->is_published ? 'published' : 'a draft — publish it with set_product_published',
            ),
        ];

        // CreateProductAction lays down a default variant; price and stock live on that variant's
        // warehouse row, not on the product, so a create that was given either has to finish there.
        if ($price === null && $quantity === null) {
            return $response;
        }

        $defaultVariant = $product->variants()->first();

        if ($defaultVariant === null) {
            return $response;
        }

        $response['stock'] = $this->setCatalogVariantStock(
            variantId: (int) $defaultVariant->getId(),
            warehouseId: $warehouseId,
            quantity: $quantity,
            price: $price,
        );

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    protected function updateCatalogProduct(
        int $productId,
        ?string $name = null,
        ?string $description = null,
        ?string $shortDescription = null,
        ?string $upc = null,
        ?float $weight = null,
        ?string $warrantyTerms = null,
        ?int $productTypeId = null,
    ): array {
        $result = $this->resolveCatalogProduct($productId);
        if (is_array($result)) {
            return $result;
        }
        $product = $result;

        $changes = array_filter(
            [
                'name' => $name === null ? null : trim($name),
                'description' => $description,
                'short_description' => $shortDescription,
                'upc' => $upc,
                'weight' => $weight,
                'warranty_terms' => $warrantyTerms,
            ],
            fn ($value) => $value !== null && $value !== '',
        );

        if ($productTypeId !== null) {
            $productType = $this->resolveCatalogProductType($productTypeId);

            if (is_array($productType)) {
                return $productType;
            }

            $changes['products_types_id'] = $productType->getId();
        }

        if ($changes === []) {
            return $this->catalogError(
                'You called update_product without any field to change. Pass at least one of name, '
                    . 'description, short_description, upc, weight, warranty_terms or product_type_id.',
                'updated',
            );
        }

        try {
            $product->update($changes);

            $product->shouldBeSearchable() ? $product->searchable() : $product->unsearchable();
            $product->fireWorkflow(WorkflowEnum::UPDATED->value, true);
        } catch (Throwable $e) {
            report($e);

            return $this->catalogError('Could not update the product: ' . $e->getMessage(), 'updated');
        }

        return [
            'updated' => true,
            ...$this->presentCatalogProduct($product->refresh()),
            'changed_fields' => array_keys($changes),
            'message' => sprintf('Product "%s" updated.', $product->name),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function deleteCatalogProduct(int $productId): array
    {
        $result = $this->resolveCatalogProduct($productId);
        if (is_array($result)) {
            return $result;
        }
        $product = $result;

        $name = $product->name;
        $variantCount = $product->variants()->count();

        try {
            // VariantObserver::deleting refuses to remove a product's last variant, and the cascade
            // from $product->delete() trips it — so the variants come off first with events muted,
            // exactly as the deleteProduct GraphQL mutation does.
            Variants::withoutEvents(function () use ($product): void {
                foreach ($product->variants as $variant) {
                    $variant->delete();
                }
            });

            $product->delete();
        } catch (Throwable $e) {
            report($e);

            return $this->catalogError('Could not delete the product: ' . $e->getMessage(), 'deleted');
        }

        return [
            'deleted' => true,
            'product_id' => $productId,
            'name' => $name,
            'variants_deleted' => $variantCount,
            'message' => sprintf(
                'Product "%s" and its %d variant(s) were deleted.',
                $name,
                $variantCount,
            ),
        ];
    }

    /**
     * @param list<int> $categoryIds
     * @return array<string, mixed>
     */
    protected function setCatalogProductCategories(int $productId, array $categoryIds, bool $replace = false): array
    {
        $result = $this->resolveCatalogProduct($productId);
        if (is_array($result)) {
            return $result;
        }
        $product = $result;

        $categoryIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));

        if ($categoryIds === []) {
            return $this->catalogError(
                'No category ids given. Use category_search to find them, then pass them in '
                    . 'category_ids.',
                'updated',
            );
        }

        $categories = [];

        foreach ($categoryIds as $categoryId) {
            $category = $this->resolveCatalogCategory($categoryId);

            if (is_array($category)) {
                return $category;
            }

            $categories[] = $category;
        }

        try {
            if ($replace) {
                $product->productsCategories()->forceDelete();
            }

            foreach ($categories as $category) {
                new AddCategoryAction($product, $category)->execute();
            }

            $product->shouldBeSearchable() ? $product->searchable() : $product->unsearchable();
        } catch (Throwable $e) {
            report($e);

            return $this->catalogError(
                'Could not file the product under those categories: ' . $e->getMessage(),
                'updated',
            );
        }

        // categories.name is translatable, so a column pluck() returns the raw {"en": …} JSON —
        // the cast only applies reading it off the model.
        $current = $product->categories()->get()->map(fn (Categories $category) => $category->name)
            ->values()
            ->toArray();

        return [
            'updated' => true,
            'product_id' => (int) $product->getId(),
            'name' => $product->name,
            'categories' => $current,
            'message' => sprintf(
                'Product "%s" is now in %d categor%s: %s.',
                $product->name,
                count($current),
                count($current) === 1 ? 'y' : 'ies',
                implode(', ', $current),
            ),
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    protected function setCatalogProductAttributes(int $productId, array $attributes, array $remove = []): array
    {
        $actor = $this->resolveCatalogActor();
        if (is_array($actor)) {
            return $actor;
        }

        $result = $this->resolveCatalogProduct($productId);
        if (is_array($result)) {
            return $result;
        }
        $product = $result;

        $pairs = $this->toCatalogAttributePairs($attributes);
        $remove = $this->toCatalogAttributeNames($remove);

        if ($pairs === [] && $remove === []) {
            return $this->catalogError(
                'No attributes to set or remove. Pass a JSON object of name/value pairs, e.g. '
                    . '{"Material": "Cotton", "Warranty": "2 years"}, or a list of names in remove.',
                'updated',
            );
        }

        try {
            if ($pairs !== []) {
                $product->addAttributes($actor, $pairs);
            }

            $removed = $this->removeCatalogAttributesByName(
                $remove,
                fn (Attributes $attribute) => new RemoveAttributeAction($product, $attribute)->execute(),
            );

            $product->shouldBeSearchable() ? $product->searchable() : $product->unsearchable();
        } catch (Throwable $e) {
            report($e);

            return $this->catalogError('Could not set the product attributes: ' . $e->getMessage(), 'updated');
        }

        return [
            'updated' => true,
            'product_id' => (int) $product->getId(),
            'name' => $product->name,
            'attributes_set' => array_column($pairs, 'name'),
            ...$this->catalogRemovalOutcome($remove, $removed),
            'message' => sprintf(
                'Set %d and removed %d attribute(s) on product "%s".',
                count($pairs),
                count($removed),
                $product->name,
            ),
        ];
    }

    /**
     * The product-level counterpart of variant_detail. The write tools can set categories, attributes
     * and a product type, and until this existed nothing could read them back — an agent could only
     * see what its own last write returned, and was blind to the product on the next turn.
     *
     * @return array<string, mixed>
     */
    protected function detailCatalogProduct(int $productId): array
    {
        $result = $this->resolveCatalogProduct($productId);
        if (is_array($result)) {
            return $result;
        }
        $product = $result;

        $product->load([
            'productsType:id,name',
            'status:id,name',
            'variants.variantWarehouses.warehouse:id,name',
            'variants.variantChannels.channel:id,name,is_default',
        ]);

        return [
            ...$this->presentCatalogProductFields($product),
            'html_description' => $product->html_description,
            'warranty_terms' => $product->warranty_terms,
            'status' => $product->status?->name,
            'attributes' => collect($product->searchableAttributes())->map(fn ($attribute) => [
                'name' => $attribute['name'],
                'value' => $attribute['value'],
            ])->values()->toArray(),
            'files' => $product->getFiles()->map(fn ($file) => [
                'uuid' => $file->uuid,
                'name' => $file->name,
                'url' => $file->url,
            ])->values()->toArray(),
            'variants' => $product->variants->map(fn (Variants $variant) => [
                'variant_id' => (int) $variant->getId(),
                'name' => $variant->name,
                'sku' => $variant->sku,
                'is_published' => (bool) $variant->is_published,
                'stock' => $variant->variantWarehouses->map(fn ($row) => [
                    'warehouse_id' => (int) $row->warehouses_id,
                    'warehouse_name' => $row->warehouse?->name,
                    'quantity' => (float) $row->quantity,
                    'price' => (float) $row->price,
                ])->values()->toArray(),
                // The selling price, which is not the warehouse price — see set_variant_channel_price.
                'channels' => $variant->variantChannels->map(fn ($row) => [
                    'channel_id' => (int) $row->channels_id,
                    'channel_name' => $row->channel?->name,
                    'is_default_channel' => (bool) $row->channel?->is_default,
                    'price' => (float) $row->price,
                    'discounted_price' => (float) $row->discounted_price,
                    'is_active' => (bool) $row->is_published,
                ])->values()->toArray(),
            ])->values()->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function duplicateCatalogProduct(int $productId): array
    {
        $actor = $this->resolveCatalogActor();
        if (is_array($actor)) {
            return $actor;
        }

        $result = $this->resolveCatalogProduct($productId);
        if (is_array($result)) {
            return $result;
        }
        $product = $result;

        try {
            $copy = new DuplicateProductAction($product, $actor)->execute();
        } catch (Throwable $e) {
            report($e);

            return $this->catalogError('Could not duplicate the product: ' . $e->getMessage(), 'created');
        }

        return [
            'created' => true,
            ...$this->presentCatalogProduct($copy->refresh()),
            'copied_from_product_id' => (int) $product->getId(),
            // DuplicateVariantAction copies the variant rows but not their warehouse or channel rows,
            // so the copy has no stock and no selling price until those are set.
            'message' => sprintf(
                'Product "%s" copied from "%s". Its variants have no stock or price yet — use '
                    . 'set_variant_stock and set_variant_channel_price on each one.',
                $copy->name,
                $product->name,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentCatalogProduct(Products $product): array
    {
        return [
            ...$this->presentCatalogProductFields($product),
            'variants' => $product->variants->map(fn (Variants $variant) => [
                'variant_id' => (int) $variant->getId(),
                'name' => $variant->name,
                'sku' => $variant->sku,
            ])->values()->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentCatalogProductFields(Products $product): array
    {
        return [
            'product_id' => (int) $product->getId(),
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
            'short_description' => $product->short_description,
            'upc' => $product->upc,
            'weight' => (float) $product->weight,
            'is_published' => (bool) $product->is_published,
            'product_type' => $product->productsType?->name,
            'categories' => $product->categories()->get()->map(fn (Categories $category) => $category->name)
                ->values()
                ->toArray(),
        ];
    }
}
