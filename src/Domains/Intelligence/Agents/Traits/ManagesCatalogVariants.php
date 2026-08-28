<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Traits;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Status\Models\Status;
use Kanvas\Inventory\Variants\Actions\AddVariantToChannelAction;
use Kanvas\Inventory\Variants\Actions\CreateVariantsAction;
use Kanvas\Inventory\Variants\Actions\DeleteVariantsAction;
use Kanvas\Inventory\Variants\Actions\DuplicateVariantAction;
use Kanvas\Inventory\Variants\Actions\RemoveAttributeAction;
use Kanvas\Inventory\Variants\Actions\UpdateToChannelAction;
use Kanvas\Inventory\Variants\Actions\UpdateToWarehouseAction;
use Kanvas\Inventory\Variants\Actions\UpdateVariantsAction;
use Kanvas\Inventory\Variants\DataTransferObject\VariantChannel as VariantChannelDto;
use Kanvas\Inventory\Variants\DataTransferObject\Variants as VariantsDto;
use Kanvas\Inventory\Variants\DataTransferObject\VariantsWarehouses as VariantsWarehousesDto;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Throwable;

/**
 * Shared body of the create/update/delete variant and set-stock tools, on both the Neuron and the
 * Laravel-AI side. Host needs either framework's HasKanvasContext plus ResolvesCatalogEntities.
 *
 * Unlike the product update path, UpdateVariantsAction is partial-edit safe for most columns — but
 * name, sku, slug and is_published are read off the DTO unconditionally, so the DTO is seeded from
 * the current variant and only the given fields are overlaid.
 */
trait ManagesCatalogVariants
{
    use NormalizesCatalogAttributes;
    use ResolvesCatalogEntities;

    /**
     * @return array<string, mixed>
     */
    protected function createCatalogVariant(
        int $productId,
        string $name,
        string $sku,
        ?string $description = null,
        ?string $shortDescription = null,
        ?string $ean = null,
        ?string $barcode = null,
        ?float $weight = null,
        ?bool $isPublished = null,
        ?float $price = null,
        ?float $quantity = null,
        ?int $warehouseId = null,
    ): array {
        $actor = $this->resolveCatalogActor();
        if (is_array($actor)) {
            return $actor;
        }

        $result = $this->resolveCatalogProduct($productId);
        if (is_array($result)) {
            return $result;
        }
        $product = $result;

        $name = trim($name);
        $sku = trim($sku);

        if ($name === '' || $sku === '') {
            return $this->catalogError('A variant needs both a name and a sku. Retry with both.', 'created');
        }

        try {
            $variant = new CreateVariantsAction(
                new VariantsDto(
                    product: $product,
                    name: $name,
                    sku: $sku,
                    description: $description,
                    short_description: $shortDescription,
                    ean: $ean,
                    barcode: $barcode,
                    weight: $weight,
                    is_published: $isPublished ?? true,
                ),
                $actor,
            )->execute();
        } catch (ValidationException $e) {
            // A taken SKU is the model's mistake to fix, not an incident — hand it back as guidance
            // rather than reporting it.
            return $this->catalogError(
                'The variant was rejected: ' . $e->getMessage() . ' SKUs are unique per company — '
                    . 'use variant_search to see what is already taken, then retry with a different sku.',
                'created',
            );
        } catch (Throwable $e) {
            report($e);

            return $this->catalogError('Could not create the variant: ' . $e->getMessage(), 'created');
        }

        $response = [
            'created' => true,
            ...$this->presentCatalogVariant($variant->refresh()),
            'message' => sprintf(
                'Variant "%s" (%s) created on product "%s".',
                $variant->name,
                $variant->sku,
                $product->name,
            ),
        ];

        if ($price === null && $quantity === null) {
            return $response;
        }

        $response['stock'] = $this->setCatalogVariantStock(
            variantId: (int) $variant->getId(),
            warehouseId: $warehouseId,
            quantity: $quantity,
            price: $price,
        );

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    protected function updateCatalogVariant(
        int $variantId,
        ?string $name = null,
        ?string $sku = null,
        ?string $description = null,
        ?string $shortDescription = null,
        ?string $ean = null,
        ?string $barcode = null,
        ?float $weight = null,
        ?bool $isPublished = null,
    ): array {
        $actor = $this->resolveCatalogActor();
        if (is_array($actor)) {
            return $actor;
        }

        $result = $this->resolveCatalogVariant($variantId);
        if (is_array($result)) {
            return $result;
        }
        $variant = $result;

        if ($variant->product === null) {
            return $this->catalogError(
                "Variant {$variantId} has no parent product, so it cannot be updated. Do not retry.",
                'updated',
            );
        }

        $given = array_filter(
            [
                'name' => $name === null ? null : trim($name),
                'sku' => $sku === null ? null : trim($sku),
                'description' => $description,
                'short_description' => $shortDescription,
                'ean' => $ean,
                'barcode' => $barcode,
                'weight' => $weight,
                'is_published' => $isPublished,
            ],
            fn ($value) => $value !== null && $value !== '',
        );

        if ($given === []) {
            return $this->catalogError(
                'You called update_variant without any field to change. Pass at least one of name, '
                    . 'sku, description, short_description, ean, barcode, weight or is_published.',
                'updated',
            );
        }

        try {
            $updated = new UpdateVariantsAction(
                $variant,
                new VariantsDto(
                    product: $variant->product,
                    name: $given['name'] ?? $variant->name,
                    sku: $given['sku'] ?? $variant->sku,
                    description: $given['description'] ?? $variant->description,
                    // The decimal/int columns come back as strings off the connection, and the DTO is
                    // strictly typed — seed them cast or the update dies on a TypeError.
                    status_id: $variant->status_id === null ? null : (int) $variant->status_id,
                    short_description: $given['short_description'] ?? $variant->short_description,
                    ean: $given['ean'] ?? $variant->ean,
                    barcode: $given['barcode'] ?? $variant->barcode,
                    serial_number: $variant->serial_number,
                    // Seeded, not derived: UpdateVariantsAction falls back to Str::slug($name), so
                    // leaving this null would silently re-slug the variant on any name edit.
                    slug: $variant->slug,
                    weight: $given['weight'] ?? ($variant->weight === null ? null : (float) $variant->weight),
                    is_published: $given['is_published'] ?? (bool) $variant->is_published,
                ),
                $actor,
            )->execute();
        } catch (ValidationException $e) {
            return $this->catalogError(
                'The variant was rejected: ' . $e->getMessage() . ' SKUs are unique per company — '
                    . 'retry with a different sku.',
                'updated',
            );
        } catch (Throwable $e) {
            report($e);

            return $this->catalogError('Could not update the variant: ' . $e->getMessage(), 'updated');
        }

        return [
            'updated' => true,
            ...$this->presentCatalogVariant($updated->refresh()),
            'changed_fields' => array_keys($given),
            'message' => sprintf('Variant "%s" (%s) updated.', $updated->name, $updated->sku),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function deleteCatalogVariant(int $variantId): array
    {
        $actor = $this->resolveCatalogActor();
        if (is_array($actor)) {
            return $actor;
        }

        $result = $this->resolveCatalogVariant($variantId);
        if (is_array($result)) {
            return $result;
        }
        $variant = $result;

        if ($variant->product === null) {
            return $this->catalogError(
                "Variant {$variantId} has no parent product, so it cannot be deleted. Do not retry.",
                'deleted',
            );
        }

        // The product's last variant is what makes it sellable, so removing it leaves a product that
        // looks fine in a listing and can never be bought. Deleting the product is the honest move.
        if ($variant->product->variants()->count() <= 1) {
            return $this->catalogError(
                sprintf(
                    'Variant "%s" is the only variant on product "%s". Deleting it would leave a product with '
                        . 'nothing to sell. Use delete_product to remove the whole product instead, or '
                        . 'set_product_published to take it off the storefront.',
                    $variant->name,
                    $variant->product->name,
                ),
                'deleted',
            );
        }

        $name = $variant->name;
        $sku = $variant->sku;

        try {
            new DeleteVariantsAction($variant, $actor)->execute();
        } catch (ValidationException $e) {
            // VariantObserver enforces the same last-variant rule the guard above does; if it fires
            // anyway the count raced us, and it is still the model's problem to route around.
            return $this->catalogError(
                $e->getMessage() . ' Use delete_product to remove the whole product instead.',
                'deleted',
            );
        } catch (Throwable $e) {
            report($e);

            return $this->catalogError('Could not delete the variant: ' . $e->getMessage(), 'deleted');
        }

        return [
            'deleted' => true,
            'variant_id' => $variantId,
            'name' => $name,
            'sku' => $sku,
            'message' => sprintf('Variant "%s" (%s) was deleted.', $name, $sku),
        ];
    }

    /**
     * Price, cost and stock live on the variant's warehouse row, not on the variant, so they need
     * their own write. The DTO is seeded from the existing row because UpdateToWarehouseAction
     * writes every column it holds — building a fresh DTO would reset is_default, is_on_sale and
     * the rest of the merchandising flags to false behind the merchant's back.
     *
     * @return array<string, mixed>
     */
    protected function setCatalogVariantStock(
        int $variantId,
        ?int $warehouseId = null,
        ?float $quantity = null,
        ?float $price = null,
        ?float $cost = null,
        ?string $sku = null,
    ): array {
        $result = $this->resolveCatalogVariant($variantId);
        if (is_array($result)) {
            return $result;
        }
        $variant = $result;

        if ($quantity === null && $price === null && $cost === null && $sku === null) {
            return $this->catalogError(
                'You called set_variant_stock without anything to set. Pass at least one of quantity, '
                    . 'price, cost or sku.',
                'updated',
            );
        }

        $warehouse = $this->resolveCatalogWarehouse($warehouseId);
        if (is_array($warehouse)) {
            return $warehouse;
        }

        $existing = $this->findVariantWarehouseRow($variant, $warehouse);

        $statusId = $existing?->status_id ?? Status::getDefault($this->company, $this->app)?->getId();

        try {
            new UpdateToWarehouseAction(
                new VariantsWarehousesDto(
                    variant: $variant,
                    warehouse: $warehouse,
                    quantity: $quantity ?? (float) ($existing?->quantity ?? 0),
                    price: $price ?? (float) ($existing?->price ?? 0),
                    sku: $sku ?? $existing?->sku ?? $variant->sku,
                    position: (int) ($existing?->position ?? 0),
                    serial_number: $existing?->serial_number,
                    status_id: $statusId === null ? null : (int) $statusId,
                    is_oversellable: (bool) ($existing?->is_oversellable ?? false),
                    is_default: (bool) ($existing?->is_default ?? false),
                    is_best_seller: (bool) ($existing?->is_best_seller ?? false),
                    is_on_sale: (bool) ($existing?->is_on_sale ?? false),
                    is_on_promo: (bool) ($existing?->is_on_promo ?? false),
                    can_pre_order: (bool) ($existing?->can_pre_order ?? false),
                    is_coming_son: (bool) ($existing?->is_coming_son ?? false),
                    is_new: (bool) ($existing?->is_new ?? false),
                    config: $existing?->config,
                    cost: $cost ?? (float) ($existing?->cost ?? 0),
                    max_capacity: $existing?->max_capacity === null ? null : (float) $existing->max_capacity,
                    latitude: $existing?->latitude === null ? null : (float) $existing->latitude,
                    longitude: $existing?->longitude === null ? null : (float) $existing->longitude,
                ),
            )->execute();
        } catch (Throwable $e) {
            report($e);

            return $this->catalogError('Could not update the variant stock: ' . $e->getMessage(), 'updated');
        }

        $row = $this->findVariantWarehouseRow($variant, $warehouse);

        $response = [
            'updated' => true,
            'variant_id' => (int) $variant->getId(),
            'sku' => $row?->sku,
            'warehouse_id' => (int) $warehouse->getId(),
            'warehouse_name' => $warehouse->name,
            'quantity' => (float) ($row?->quantity ?? 0),
            'price' => (float) ($row?->price ?? 0),
            'cost' => (float) ($row?->cost ?? 0),
            'message' => sprintf(
                'Variant "%s" now has %s units at %s in warehouse "%s".',
                $variant->name,
                (float) ($row?->quantity ?? 0),
                (float) ($row?->price ?? 0),
                $warehouse->name,
            ),
        ];

        if ($row !== null && $price !== null) {
            $response['channel'] = $this->seedDefaultChannelPrice($row, $price);
        }

        return $response;
    }

    /**
     * A warehouse price is not what a customer pays: AddToCartAction reads the variants_channels
     * pivot via getPriceInfoFromDefaultChannel(), which firstOrFail()s. Without a row there a variant
     * priced and stocked through these tools throws on add-to-cart, so the first price we write also
     * seeds the default channel.
     *
     * Seeding only — an existing channel price is a merchandising decision and is never overwritten
     * here; set_variant_channel_price owns that. The seeded row copies the product's own published
     * state on purpose: AddVariantToChannelAction publishes the parent product when handed
     * is_published=true, which would flip a deliberate draft live as a side effect of setting stock.
     *
     * @return array<string, mixed>
     */
    private function seedDefaultChannelPrice(VariantsWarehouses $warehouseRow, float $price): array
    {
        $channel = $this->resolveCatalogChannel(null);

        if (is_array($channel)) {
            return [
                'seeded' => false,
                'message' => $channel['message'] . ' Until it has one this variant cannot be added to a cart.',
            ];
        }

        // Scoped to the default channel, not to any channel on the row: a variant already priced on a
        // B2B or wholesale channel still has nothing for the cart to read, and skipping the seed there
        // leaves exactly the unbuyable variant this seeding exists to prevent.
        $existing = $this->findVariantChannelListing($warehouseRow, $channel);

        if ($existing !== null) {
            return [
                'seeded' => false,
                'message' => 'The variant already has a price on the default channel; set_variant_channel_price '
                    . 'changes it.',
            ];
        }

        try {
            $variantChannel = new AddVariantToChannelAction(
                $warehouseRow,
                $channel,
                VariantChannelDto::fromArray([
                    'price' => $price,
                    'is_published' => (bool) $warehouseRow->variant->product?->is_published,
                ]),
            )->execute();
        } catch (Throwable $e) {
            report($e);

            return [
                'seeded' => false,
                'message' => 'Could not set the selling price on the default channel: ' . $e->getMessage(),
            ];
        }

        return [
            'seeded' => true,
            'channel_id' => (int) $channel->getId(),
            'channel_name' => $channel->name,
            'selling_price' => (float) $variantChannel->price,
            'message' => sprintf(
                'Selling price set to %s on channel "%s".',
                (float) $variantChannel->price,
                $channel->name,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function setCatalogVariantChannelPrice(
        int $variantId,
        ?float $price = null,
        ?float $discountedPrice = null,
        ?bool $isPublished = null,
        ?int $channelId = null,
        ?int $warehouseId = null,
    ): array {
        $result = $this->resolveCatalogVariant($variantId);
        if (is_array($result)) {
            return $result;
        }
        $variant = $result;

        if ($price === null && $discountedPrice === null && $isPublished === null) {
            return $this->catalogError(
                'You called set_variant_channel_price without anything to set. Pass at least one of '
                    . 'price, discounted_price or is_published.',
                'updated',
            );
        }

        $channel = $this->resolveCatalogChannel($channelId);
        if (is_array($channel)) {
            return $channel;
        }

        $warehouse = $this->resolveCatalogWarehouse($warehouseId);
        if (is_array($warehouse)) {
            return $warehouse;
        }

        $warehouseRow = $this->findVariantWarehouseRow($variant, $warehouse);

        if ($warehouseRow === null) {
            return $this->catalogError(
                sprintf(
                    'Variant "%s" is not stocked in warehouse "%s", and a channel price hangs off the warehouse '
                        . 'row. Call set_variant_stock for this variant first, then retry.',
                    $variant->name,
                    $warehouse->name,
                ),
                'updated',
            );
        }

        $existing = $this->findVariantChannelListing($warehouseRow, $channel);

        // Listing a variant for the first time needs a real price. Without this, activating one that
        // was never on the channel writes price 0.00 and puts it on the storefront as a giveaway.
        if ($existing === null && $price === null) {
            return $this->catalogError(
                sprintf(
                    'Variant "%s" is not listed on channel "%s" yet, so it has no price there — listing it now '
                        . 'without one would put it on sale at 0. Retry with a price.',
                    $variant->name,
                    $channel->name,
                ),
                'updated',
            );
        }

        try {
            if ($existing !== null) {
                // Repricing goes through UpdateToChannelAction, which defaults every field from the
                // current row — a partial edit stays partial, and the create path's
                // publish-the-parent-product side effect stays out of an ordinary price change.
                $variantChannel = new UpdateToChannelAction(
                    $existing,
                    new VariantChannelDto(
                        price: $price ?? (float) $existing->price,
                        discounted_price: $discountedPrice ?? (float) $existing->discounted_price,
                        is_published: $isPublished ?? (bool) $existing->is_published,
                        config: $existing->config,
                    ),
                )->execute();
            } else {
                $variantChannel = new AddVariantToChannelAction(
                    $warehouseRow,
                    $channel,
                    VariantChannelDto::fromArray([
                        'price' => $price ?? 0.0,
                        'discounted_price' => $discountedPrice ?? 0.0,
                        'is_published' => $isPublished ?? (bool) $variant->product?->is_published,
                    ]),
                )->execute();
            }
        } catch (Throwable $e) {
            report($e);

            return $this->catalogError('Could not set the channel price: ' . $e->getMessage(), 'updated');
        }

        return [
            'updated' => true,
            'variant_id' => (int) $variant->getId(),
            'sku' => $variant->sku,
            'channel_id' => (int) $channel->getId(),
            'channel_name' => $channel->name,
            'warehouse_id' => (int) $warehouse->getId(),
            'price' => (float) $variantChannel->price,
            'discounted_price' => (float) $variantChannel->discounted_price,
            'is_published' => (bool) $variantChannel->is_published,
            'message' => sprintf(
                'Variant "%s" now sells at %s on channel "%s"%s.',
                $variant->name,
                (float) ($variantChannel->discounted_price ?: $variantChannel->price),
                $channel->name,
                $variantChannel->is_published ? '' : ' (channel listing not published)',
            ),
        ];
    }

    /**
     * The two pivot lookups the stock and channel tools all need. `variants_channels` is keyed on
     * `product_variants_warehouse_id` — the same key AddVariantToChannelAction upserts on — so every
     * caller resolves the warehouse row first and asks from there. Spelling the lookup three
     * different ways is how one of them quietly stops matching the rows the others write.
     */
    private function findVariantWarehouseRow(Variants $variant, Warehouses $warehouse): ?VariantsWarehouses
    {
        return VariantsWarehouses::where('products_variants_id', $variant->getId())
            ->where('warehouses_id', $warehouse->getId())
            ->first();
    }

    private function findVariantChannelListing(
        VariantsWarehouses $warehouseRow,
        Channels $channel,
    ): ?VariantsChannels {
        return VariantsChannels::where('product_variants_warehouse_id', $warehouseRow->getId())
            ->where('channels_id', $channel->getId())
            ->first();
    }

    /**
     * Activates or deactivates a variant's listing on one channel — the storefront on/off switch,
     * separate from pricing so an agent asked to "take this off the web channel" has a tool whose
     * name says that.
     *
     * Only ever flips an existing listing: creating one needs a price, and inventing a price to
     * satisfy an activate request is how a variant lands on a storefront at 0.
     *
     * @return array<string, mixed>
     */
    protected function setCatalogVariantChannelStatus(
        int $variantId,
        bool $isPublished,
        ?int $channelId = null,
        ?int $warehouseId = null,
    ): array {
        $result = $this->resolveCatalogVariant($variantId);
        if (is_array($result)) {
            return $result;
        }
        $variant = $result;

        $channel = $this->resolveCatalogChannel($channelId);
        if (is_array($channel)) {
            return $channel;
        }

        $warehouse = $this->resolveCatalogWarehouse($warehouseId);
        if (is_array($warehouse)) {
            return $warehouse;
        }

        $warehouseRow = $this->findVariantWarehouseRow($variant, $warehouse);
        $listing = $warehouseRow === null ? null : $this->findVariantChannelListing($warehouseRow, $channel);

        if ($listing === null) {
            return $this->catalogError(
                sprintf(
                    'Variant "%s" is not listed on channel "%s" yet, so there is nothing to switch on or off. '
                        . 'Use set_variant_channel_price to list it with a price first. Check list_channels for '
                        . 'the channels and variant_detail for the ones it is already on.',
                    $variant->name,
                    $channel->name,
                ),
                'updated',
            );
        }

        if ((bool) $listing->is_published === $isPublished) {
            return [
                'updated' => false,
                'variant_id' => (int) $variant->getId(),
                'sku' => $variant->sku,
                'channel_id' => (int) $channel->getId(),
                'channel_name' => $channel->name,
                'is_published' => $isPublished,
                'message' => sprintf(
                    'Variant "%s" is already %s on channel "%s".',
                    $variant->name,
                    $isPublished ? 'active' : 'inactive',
                    $channel->name,
                ),
            ];
        }

        try {
            new UpdateToChannelAction(
                $listing,
                new VariantChannelDto(
                    price: (float) $listing->price,
                    discounted_price: (float) $listing->discounted_price,
                    is_published: $isPublished,
                    config: $listing->config,
                ),
            )->execute();
        } catch (Throwable $e) {
            report($e);

            return $this->catalogError(
                'Could not change the channel listing: ' . $e->getMessage(),
                'updated',
            );
        }

        return [
            'updated' => true,
            'variant_id' => (int) $variant->getId(),
            'sku' => $variant->sku,
            'channel_id' => (int) $channel->getId(),
            'channel_name' => $channel->name,
            'is_published' => $isPublished,
            ...$this->catalogVisibilityCaveats($variant, $channel, $isPublished),
            'message' => sprintf(
                'Variant "%s" is now %s on channel "%s".',
                $variant->name,
                $isPublished ? 'active' : 'inactive',
                $channel->name,
            ),
        ];
    }

    /**
     * A channel listing is only one of four flags a shopper's view depends on. Switching it on while
     * the variant, its product or the channel itself is unpublished leaves the agent reporting
     * success on something nobody can see, so the blockers are named in the result.
     *
     * @return array<string, mixed>
     */
    private function catalogVisibilityCaveats(Variants $variant, Channels $channel, bool $isPublished): array
    {
        if (! $isPublished) {
            return [];
        }

        $blockers = [];

        if (! $variant->is_published) {
            $blockers[] = 'the variant itself is unpublished (update_variant with is_published=true)';
        }

        if ($variant->product !== null && ! $variant->product->is_published) {
            $blockers[] = 'its product is a draft (set_product_published)';
        }

        if (! $channel->is_published) {
            $blockers[] = sprintf('channel "%s" is unpublished', $channel->name);
        }

        return $blockers === [] ? [] : ['not_yet_visible_because' => $blockers];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    protected function setCatalogVariantAttributes(int $variantId, array $attributes, array $remove = []): array
    {
        $actor = $this->resolveCatalogActor();
        if (is_array($actor)) {
            return $actor;
        }

        $result = $this->resolveCatalogVariant($variantId);
        if (is_array($result)) {
            return $result;
        }
        $variant = $result;

        $pairs = $this->toCatalogAttributePairs($attributes);
        $remove = $this->toCatalogAttributeNames($remove);

        if ($pairs === [] && $remove === []) {
            return $this->catalogError(
                'No attributes to set or remove. Pass a JSON object of name/value pairs, e.g. '
                    . '{"Colour": "Red", "Size": "XL"}, or a list of names in remove.',
                'updated',
            );
        }

        try {
            if ($pairs !== []) {
                $variant->addAttributes($actor, $pairs);
            }

            $removed = $this->removeCatalogAttributesByName(
                $remove,
                fn (Attributes $attribute) => new RemoveAttributeAction($variant, $attribute)->execute(),
            );
        } catch (Throwable $e) {
            report($e);

            return $this->catalogError('Could not set the variant attributes: ' . $e->getMessage(), 'updated');
        }

        return [
            'updated' => true,
            'variant_id' => (int) $variant->getId(),
            'sku' => $variant->sku,
            'attributes_set' => array_column($pairs, 'name'),
            ...$this->catalogRemovalOutcome($remove, $removed),
            'message' => sprintf(
                'Set %d and removed %d attribute(s) on variant "%s".',
                count($pairs),
                count($removed),
                $variant->name,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function duplicateCatalogVariant(int $variantId): array
    {
        $actor = $this->resolveCatalogActor();
        if (is_array($actor)) {
            return $actor;
        }

        $result = $this->resolveCatalogVariant($variantId);
        if (is_array($result)) {
            return $result;
        }
        $variant = $result;

        if ($variant->product === null) {
            return $this->catalogError(
                "Variant {$variantId} has no parent product, so it cannot be duplicated. Do not retry.",
                'created',
            );
        }

        try {
            $copy = new DuplicateVariantAction($variant, $variant->product, $actor)->execute();
        } catch (ValidationException $e) {
            return $this->catalogError(
                'The copy was rejected: ' . $e->getMessage() . ' Rename or remove the existing copy first.',
                'created',
            );
        } catch (Throwable $e) {
            report($e);

            return $this->catalogError('Could not duplicate the variant: ' . $e->getMessage(), 'created');
        }

        return [
            'created' => true,
            ...$this->presentCatalogVariant($copy->refresh()),
            'copied_from_variant_id' => (int) $variant->getId(),
            // The copy carries no warehouse or channel row, so it is neither stocked nor sellable yet.
            'message' => sprintf(
                'Variant "%s" (%s) copied from "%s". It has no stock or price yet — use set_variant_stock '
                    . 'and set_variant_channel_price, and update_variant to give it a real sku.',
                $copy->name,
                $copy->sku,
                $variant->name,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentCatalogVariant(Variants $variant): array
    {
        return [
            'variant_id' => (int) $variant->getId(),
            'product_id' => (int) $variant->products_id,
            'name' => $variant->name,
            'sku' => $variant->sku,
            'slug' => $variant->slug,
            'description' => $variant->description,
            'short_description' => $variant->short_description,
            'ean' => $variant->ean,
            'barcode' => $variant->barcode,
            'weight' => (float) $variant->weight,
            'is_published' => (bool) $variant->is_published,
        ];
    }
}
