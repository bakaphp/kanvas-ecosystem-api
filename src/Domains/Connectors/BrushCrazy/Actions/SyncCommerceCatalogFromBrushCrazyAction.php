<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Actions;

use Baka\Contracts\AppInterface;
use Baka\Traits\ScalarCoercionTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Concerns\SyncsAttachedPhotos;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Enums\SaleLineKindEnum;
use Kanvas\Connectors\BrushCrazy\Mappers\PaintingMapper;
use Kanvas\Connectors\BrushCrazy\Mappers\ProductMapper;
use Kanvas\Connectors\BrushCrazy\Support\IdMapRepository;
use Kanvas\Connectors\BrushCrazy\Support\PaintingRelations;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Products\Models\ProductsCategories;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Actions\AddVariantToChannelAction;
use Kanvas\Inventory\Variants\DataTransferObject\VariantChannel;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use RuntimeException;

/**
 * The studio's whole catalog: one Product + Variant per BrushCrazy product the studio stocks
 * (`inventory_items`), prices (`product_settings`) or ever sold, plus one generic line per
 * SaleLineKindEnum for the sale items that have no product (class seats, gift cards, deposits,
 * tips, ...). Each product is linked to its category and its group from the taxonomy action, and
 * its warehouse row carries the studio's stock count and cost.
 *
 * BrushCrazy products are global; here each studio gets its own copy, priced at that studio's
 * `product_settings` override. Resolution is by external id (BRUSHCRAZY_PRODUCT_ID on the Product,
 * BRUSHCRAZY_LOOKUP_KEY on the Variant), never by name; the `bc-` slug is only the recovery path for
 * a row whose mapping was never written (see writeQuietly()).
 *
 * Writes go out with model events off: the product observer fires a workflow per channel and
 * re-indexes search on every save, none of which an imported history catalog should trigger.
 */
class SyncCommerceCatalogFromBrushCrazyAction
{
    use ScalarCoercionTrait;
    use SyncsAttachedPhotos;

    /** BrushCrazy morph aliases this action mirrors. */
    public const array MORPHS = [
        self::PRODUCT_MORPH,
        self::PRODUCT_SETTING_MORPH,
        self::INVENTORY_ITEM_MORPH,
        ...SyncCatalogTaxonomyFromBrushCrazyAction::MORPHS,
    ];

    public const string PRODUCT_MORPH = 'product';

    public const string PRODUCT_SETTING_MORPH = 'product-setting';

    public const string INVENTORY_ITEM_MORPH = 'inventory-item';

    public const string FEATURED_TAG = 'featured';

    protected IdMapRepository $idMaps;

    /** @var array<string, Variants>|null lookup key => variant */
    protected ?array $variants = null;

    protected ?Regions $region = null;

    protected ?Warehouses $warehouse = null;

    protected bool $warehouseResolved = false;

    protected ?Channels $channel = null;

    protected bool $channelResolved = false;

    /** @var array<int, Products> products whose channel price changed in this call, keyed by id */
    protected array $pricedProducts = [];

    /** @var array<int, bool> products already looked up at the source and not found there */
    protected array $missingProducts = [];

    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected StudioContext $context,
        ?IdMapRepository $idMaps = null,
        protected ?Client $client = null,
        protected ?SyncCatalogTaxonomyFromBrushCrazyAction $taxonomy = null,
    ) {
        $this->idMaps = $idMaps ?? new IdMapRepository();
    }

    /**
     * @return array{products: int, lines: int, category_types: int, categories: int, groups: int}
     */
    public function execute(): array
    {
        $this->region();

        $taxonomy = $this->taxonomy()->execute();

        $studioId = $this->context->bcStudioId;

        $productIds = $this->client()->table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.studio_id', $studioId)
            ->whereNotNull('sale_items.product_id')
            ->distinct()
            ->pluck('sale_items.product_id')
            ->merge($this->studioProductIds('inventory_items'))
            ->merge($this->studioProductIds('product_settings'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $synced = 0;

        foreach (array_chunk($productIds, 500) as $chunk) {
            $synced += $this->syncProducts($chunk);
        }

        foreach (SaleLineKindEnum::cases() as $kind) {
            $this->genericVariant($kind);
        }

        return ['products' => $synced, 'lines' => count(SaleLineKindEnum::cases())] + $taxonomy;
    }

    /**
     * The webhook entry point for catalog pointers. A `product` pointer is a broadcast (products are
     * global), so it only refreshes a product this studio already carries; `product-setting` and
     * `inventory-item` belong to one studio and bring the product in even when it is new there.
     * Returns the webhook result counter the pointer belongs to.
     */
    public function fromPointer(string $morph, int $entityId): string
    {
        if (in_array($morph, SyncCatalogTaxonomyFromBrushCrazyAction::MORPHS, true)) {
            return $this->taxonomy()->fromPointer($morph, $entityId);
        }

        if ($morph === self::PRODUCT_SETTING_MORPH || $morph === self::INVENTORY_ITEM_MORPH) {
            $row = $this->client()
                ->table($morph === self::PRODUCT_SETTING_MORPH ? 'product_settings' : 'inventory_items')
                ->where('id', $entityId)
                ->first(['product_id', 'studio_id']);

            if ($row === null || $row->product_id === null) {
                return SyncSaleFromBrushCrazyAction::MISSING;
            }

            if ((int) $row->studio_id !== $this->context->bcStudioId) {
                return SyncSaleFromBrushCrazyAction::MISROUTED;
            }

            return $this->syncProducts([(int) $row->product_id]) > 0
                ? SyncSaleFromBrushCrazyAction::SYNCED
                : SyncSaleFromBrushCrazyAction::MISSING;
        }

        $known = $this->idMaps->loadFor(
            $this->context->companyId(),
            Products::class,
            CustomFieldEnum::BRUSHCRAZY_PRODUCT_ID,
            [$entityId],
        );

        if ($known === []) {
            return SyncSaleFromBrushCrazyAction::SKIPPED;
        }

        return $this->syncProducts([$entityId]) > 0
            ? SyncSaleFromBrushCrazyAction::SYNCED
            : SyncSaleFromBrushCrazyAction::MISSING;
    }

    /**
     * Soft-deleted products are read too: a sale from 2019 still points at a product discontinued
     * since, and its line needs that variant.
     *
     * @param  array<int, int>  $bcProductIds
     */
    public function syncProducts(array $bcProductIds): int
    {
        if ($bcProductIds === []) {
            return 0;
        }

        $products = $this->client()->table('products')->whereIn('id', $bcProductIds)->get();
        $settings = $this->client()->table('product_settings')
            ->where('studio_id', $this->context->bcStudioId)
            ->whereIn('product_id', $bcProductIds)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->keyBy('product_id')
            ->all();

        // Ordered by id so keyBy keeps the newest live row when a studio has duplicates.
        $inventory = $this->client()->table('inventory_items')
            ->where('studio_id', $this->context->bcStudioId)
            ->whereIn('product_id', $bcProductIds)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'product_id', 'quantity', 'cost'])
            ->keyBy('product_id')
            ->all();

        $featured = $this->client()->table('studio_products')
            ->where('studio_id', $this->context->bcStudioId)
            ->whereIn('product_id', $bcProductIds)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $this->syncProductRows(
            $products,
            $settings,
            $inventory,
            $this->productPhotos($bcProductIds),
            $featured
        );
    }

    /**
     * Null without a media base url, which leaves every product's photos as they are.
     *
     * @param  array<int, int>  $bcProductIds
     * @return array<int, array{media_id: int, url: string}>|null  BrushCrazy product id => photo
     */
    protected function productPhotos(array $bcProductIds): ?array
    {
        $mediaBaseUrl = self::mediaBaseUrlOf($this->app);

        if ($mediaBaseUrl === null) {
            return null;
        }

        $photos = [];
        $media = PaintingRelations::mediaQuery($this->client(), [self::PRODUCT_MORPH => $bcProductIds])->get()->all();

        foreach (PaintingMapper::photos($media, $mediaBaseUrl) as $key => $photo) {
            $photos[(int) substr($key, strlen(self::PRODUCT_MORPH) + 1)] = $photo;
        }

        return $photos;
    }

    /**
     * The write path for source rows already in hand — shared by syncProducts() and the tests.
     *
     * @param  iterable<object>  $products
     * @param  array<int|string, object>  $settingsByProductId  this studio's product_settings
     * @param  array<int|string, object>  $inventoryByProductId  this studio's inventory_items
     * @param  array<int, array{media_id: int, url: string}>|null  $photosByProductId  null leaves photos alone
     * @param  array<int, int>|null  $featuredProductIds  this studio's studio_products; null leaves the tag alone
     */
    public function syncProductRows(
        iterable $products,
        array $settingsByProductId = [],
        array $inventoryByProductId = [],
        ?array $photosByProductId = null,
        ?array $featuredProductIds = null
    ): int {
        $rows = [];

        foreach ($products as $product) {
            $rows[(int) $product->id] = $product;
        }

        if ($rows === []) {
            return 0;
        }

        $knownProducts = $this->idMaps->loadFor(
            $this->context->companyId(),
            Products::class,
            CustomFieldEnum::BRUSHCRAZY_PRODUCT_ID,
            array_keys($rows),
        );

        $synced = [];

        foreach ($rows as $bcProductId => $row) {
            $mapped = ProductMapper::map(
                $row,
                $settingsByProductId[$bcProductId] ?? null,
                $inventoryByProductId[$bcProductId] ?? null,
            );
            $knownProductId = $knownProducts[(string) $bcProductId] ?? null;

            $product = $this->writeQuietly(
                Products::class,
                $knownProductId,
                $mapped['product'],
                ['created_at' => $row->created_at ?? null],
            );

            if ($knownProductId === null) {
                $product->disableWorkflows();
                $product->set(CustomFieldEnum::BRUSHCRAZY_PRODUCT_ID->value, $bcProductId);
            }

            if ($photosByProductId !== null) {
                $this->syncPhotos(
                    $product,
                    isset($photosByProductId[$bcProductId]) ? [PaintingMapper::PHOTO_FIELD => $photosByProductId[$bcProductId]] : []
                );
            }

            if ($featuredProductIds !== null) {
                $this->syncFeatured($product, in_array($bcProductId, $featuredProductIds, true));
            }

            $this->syncCategories($product, array_filter([
                $this->taxonomy()->categoryIdFor($this->intOrNull($row->category_id ?? null)),
                $this->taxonomy()->groupCategoryIdFor($this->intOrNull($row->group_id ?? null)),
            ]));

            $this->upsertVariant(
                SaleLineKindEnum::productLookupKey($bcProductId),
                $product,
                $mapped['variant'],
                $mapped['price'],
                $mapped['stock']
            );

            $synced[] = $product;
        }

        // Written with model events off, so Scout and the GraphQL cache never saw these saves. Last,
        // because the search document is built from the channel prices written above.
        new EloquentCollection($synced)->searchable();

        foreach ($this->pricedProducts as $product) {
            $product->clearLightHouseCache(withKanvasConfiguration: false);
        }

        $this->pricedProducts = [];

        return count($rows);
    }

    /**
     * A product sold after the catalog ran (the webhook path) is pulled on demand; a product the
     * source no longer has at all lands on the line kind's generic variant instead of failing the
     * whole sale.
     */
    public function variantFor(?int $bcProductId, SaleLineKindEnum $kind): Variants
    {
        if ($bcProductId === null) {
            return $this->genericVariant($kind);
        }

        $key = SaleLineKindEnum::productLookupKey($bcProductId);

        if (! isset($this->variants()[$key]) && ! isset($this->missingProducts[$bcProductId])) {
            $this->syncProducts([$bcProductId]);
            $this->missingProducts[$bcProductId] = ! isset($this->variants[$key]);
        }

        return $this->variants()[$key] ?? $this->genericVariant($kind);
    }

    public function genericVariant(SaleLineKindEnum $kind): Variants
    {
        $key = $kind->lookupKey();

        if (isset($this->variants()[$key])) {
            return $this->variants[$key];
        }

        $mapped = ProductMapper::genericLine($kind);
        $knownProductId = $this->idMaps->load(
            $this->context->companyId(),
            Products::class,
            CustomFieldEnum::BRUSHCRAZY_LOOKUP_KEY,
        )[$key] ?? null;

        $product = $this->writeQuietly(Products::class, $knownProductId, $mapped['product']);

        if ($knownProductId === null) {
            $product->disableWorkflows();
            $product->set(CustomFieldEnum::BRUSHCRAZY_LOOKUP_KEY->value, $key);
        }

        return $this->upsertVariant(
            $key,
            $product,
            $mapped['variant'],
            0.0
        );
    }

    /**
     * Souk requires a region on every order. A studio company created by the import never went
     * through user onboarding, so it may have no inventory defaults yet; the stock Inventory setup
     * creates them (region, warehouse, channel, status, ...) and is idempotent.
     */
    public function region(): Regions
    {
        if ($this->region !== null) {
            return $this->region;
        }

        $region = Regions::getDefault($this->context->company, $this->app);

        if ($region === null) {
            new InventorySetup($this->app, $this->user, $this->context->company)->run();
            $region = Regions::getDefault($this->context->company, $this->app);
        }

        if (! $region instanceof Regions) {
            throw new RuntimeException("Studio company {$this->context->companyId()} has no default inventory region.");
        }

        return $this->region = $region;
    }

    public function warehouse(): ?Warehouses
    {
        if (! $this->warehouseResolved) {
            $this->region();
            $warehouse = $this->context->warehouse ?? Warehouses::getDefault($this->context->company, $this->app);
            $this->warehouse = $warehouse instanceof Warehouses ? $warehouse : null;
            $this->warehouseResolved = true;
        }

        return $this->warehouse;
    }

    /**
     * A studio's `studio_products` are its homepage's featured products; the shop reads them by tag.
     */
    protected function syncFeatured(Products $product, bool $isFeatured): void
    {
        if ($product->hasTag([self::FEATURED_TAG]) === $isFeatured) {
            return;
        }

        $product->disableWorkflows();

        if ($isFeatured) {
            $product->addTag(self::FEATURED_TAG, $this->app, $this->user, $this->context->company);
        } else {
            $product->removeTag(self::FEATURED_TAG);
        }
    }

    /**
     * The product's links to BrushCrazy categories (its category and its group) end up exactly
     * $categoryIds: a link the source dropped is removed, a missing one added. Links to categories
     * made in Kanvas are left alone.
     *
     * Removed, not soft-deleted: Products::categories() does not filter the pivot's is_deleted, so a
     * soft-deleted link keeps the product listed under its old category on the storefront.
     *
     * @param  array<int, int>  $categoryIds
     */
    protected function syncCategories(Products $product, array $categoryIds): void
    {
        $links = ProductsCategories::withTrashed()
            ->where('products_id', $product->getId())
            ->whereIn(
                'categories_id',
                Categories::withTrashed()
                    ->where('apps_id', $this->app->getId())
                    ->where('companies_id', $this->context->companyId())
                    ->where(fn ($query) => $query->where('slug', 'like', 'bc-category-%')->orWhere('slug', 'like', 'bc-group-%'))
                    ->select('id')
            )
            ->get();

        $stale = [];

        foreach ($links as $link) {
            if (! in_array((int) $link->categories_id, $categoryIds, true)) {
                $stale[] = (int) $link->categories_id;
            } elseif ($link->is_deleted) {
                $link->is_deleted = false;
                $link->saveQuietly();
            }
        }

        if ($stale !== []) {
            ProductsCategories::withTrashed()
                ->where('products_id', $product->getId())
                ->whereIn('categories_id', $stale)
                ->toBase()
                ->delete();
        }

        $linked = array_diff($links->map(fn ($link) => (int) $link->categories_id)->all(), $stale);

        foreach (array_diff($categoryIds, $linked) as $categoryId) {
            $link = new ProductsCategories();
            $link->products_id = $product->getId();
            $link->categories_id = $categoryId;
            $link->saveQuietly();
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array{quantity: int, cost: float}|null  $stock  the studio's inventory row, if it has one
     */
    protected function upsertVariant(
        string $lookupKey,
        Products $product,
        array $attributes,
        float $price,
        ?array $stock = null
    ): Variants {
        $knownVariant = $this->variants()[$lookupKey] ?? null;

        /** @var Variants $variant */
        $variant = $this->writeQuietly(
            Variants::class,
            $knownVariant?->getId(),
            $attributes + ['products_id' => $product->getId()],
        );

        if ($knownVariant === null) {
            $variant->disableWorkflows();
            $variant->set(CustomFieldEnum::BRUSHCRAZY_LOOKUP_KEY->value, $lookupKey);
        }

        $warehouse = $this->warehouse();

        if ($warehouse !== null) {
            $warehouseRow = VariantsWarehouses::withTrashed()->firstOrNew([
                'products_variants_id' => $variant->getId(),
                'warehouses_id' => $warehouse->getId(),
            ]);

            // BrushCrazy's count wins when the studio stocks the product. Without one, a re-sync keeps
            // what is there (quantity lost its column default in a later migration).
            if ($stock !== null) {
                $warehouseRow->quantity = $stock['quantity'];
                $warehouseRow->cost = $stock['cost'];
            } elseif (! $warehouseRow->exists) {
                $warehouseRow->quantity = 0;
            }

            $warehouseRow->fill([
                'price' => $price,
                'sku' => $variant->sku,
                'position' => 0,
            ]);

            if (! $warehouseRow->exists || $warehouseRow->isDirty()) {
                $warehouseRow->saveQuietly();
            }

            $this->publishToChannel(
                $product,
                $variant,
                $warehouseRow,
                $price
            );
        }

        return $this->variants[$lookupKey] = $variant;
    }

    /**
     * The storefront, the cart and Souk's order lines all price a variant from its default-channel
     * row, not the warehouse one. Written only when it changed: AddVariantToChannelAction records a
     * price-history row on every call. An unpublished product (the generic sale lines) gets an
     * unpublished row, so the action never republishes it.
     */
    protected function publishToChannel(
        Products $product,
        Variants $variant,
        VariantsWarehouses $warehouseRow,
        float $price
    ): void {
        $channel = $this->channel();

        if ($channel === null) {
            return;
        }

        $isPublished = (bool) $product->is_published && $price > 0;

        $current = VariantsChannels::withTrashed()
            ->where('product_variants_warehouse_id', $warehouseRow->getId())
            ->where('channels_id', $channel->getId())
            ->first();

        if ($current !== null
            && ! $current->is_deleted
            && (bool) $current->is_published === $isPublished
            && round((float) $current->price, 2) === round($price, 2)) {
            return;
        }

        new AddVariantToChannelAction(
            $warehouseRow,
            $channel,
            new VariantChannel(price: $price, is_published: $isPublished),
        )->execute();

        $this->pricedProducts[$product->getId()] = $product;
        $variant->clearLightHouseCache(withKanvasConfiguration: false);
    }

    protected function channel(): ?Channels
    {
        if (! $this->channelResolved) {
            $this->region();
            $channel = Channels::getDefault($this->context->company, $this->app);
            $this->channel = $channel instanceof Channels ? $channel : null;
            $this->channelResolved = true;
        }

        return $this->channel;
    }

    /**
     * @return array<string, Variants>
     */
    protected function variants(): array
    {
        if ($this->variants !== null) {
            return $this->variants;
        }

        $ids = $this->idMaps->load(
            $this->context->companyId(),
            Variants::class,
            CustomFieldEnum::BRUSHCRAZY_LOOKUP_KEY,
        );

        $models = Variants::withTrashed()
            ->whereIn('id', array_values($ids))
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->context->companyId())
            ->get()
            ->keyBy('id');

        $this->variants = [];

        foreach ($ids as $key => $id) {
            if ($models->has($id)) {
                $this->variants[$key] = $models->get($id);
            }
        }

        return $this->variants;
    }

    /**
     * Events off, so UuidTrait and SlugTrait never fire: uuid is set here and every slug comes from
     * the mapper. Diffed before writing, so an unchanged re-run touches nothing.
     *
     * With no mapping, the row is first looked for by slug: the mapping is written after the insert,
     * so a run that died in between left a row the unique slug index would refuse a second time —
     * and an orphan Variant would fail every sale of that product. The `bc-` slugs are generated
     * from BrushCrazy ids by the mapper, so a slug match is this same source row; the caller then
     * writes the missing mapping. The match on `is_deleted` comes first because Products is unique
     * on (company, slug, is_deleted).
     *
     * @param  class-string<Products|Variants>  $modelClass
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $insertOnly
     */
    protected function writeQuietly(
        string $modelClass,
        ?int $existingId,
        array $attributes,
        array $insertOnly = []
    ): Model {
        return $modelClass::withoutEvents(function () use ($modelClass, $existingId, $attributes, $insertOnly) {
            $existing = $existingId !== null
                ? $modelClass::withTrashed()
                    ->where('id', $existingId)
                    ->where('apps_id', $this->app->getId())
                    ->where('companies_id', $this->context->companyId())
                    ->first()
                : null;

            $existing ??= $modelClass::withTrashed()
                ->where('apps_id', $this->app->getId())
                ->where('companies_id', $this->context->companyId())
                ->where('slug', $attributes['slug'])
                ->orderByRaw('is_deleted <> ?', [(int) ($attributes['is_deleted'] ?? false)])
                ->first();

            if ($existing !== null) {
                $existing->forceFill($attributes);

                if ($existing->isDirty()) {
                    $existing->saveQuietly();
                }

                return $existing;
            }

            $model = new $modelClass();
            $model->forceFill($attributes + array_filter($insertOnly, fn ($value) => $value !== null));
            $model->uuid = Str::uuid()->toString();
            $model->apps_id = $this->app->getId();
            $model->companies_id = $this->context->companyId();
            $model->users_id = $this->user->getId();
            $model->saveQuietly();

            return $model;
        });
    }

    protected function client(): Client
    {
        return $this->client ??= new Client($this->app);
    }

    /**
     * Soft-deleted rows included: a product the studio stopped stocking is still its product.
     */
    protected function studioProductIds(string $table): Collection
    {
        return $this->client()->table($table)
            ->where('studio_id', $this->context->bcStudioId)
            ->whereNotNull('product_id')
            ->distinct()
            ->pluck('product_id');
    }

    protected function taxonomy(): SyncCatalogTaxonomyFromBrushCrazyAction
    {
        return $this->taxonomy ??= new SyncCatalogTaxonomyFromBrushCrazyAction(
            $this->app,
            $this->user,
            $this->context,
            $this->client,
        );
    }
}
