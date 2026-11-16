<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Enums\SaleLineKindEnum;
use Kanvas\Connectors\BrushCrazy\Mappers\ProductMapper;
use Kanvas\Connectors\BrushCrazy\Support\IdMapRepository;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use RuntimeException;

/**
 * The Inventory side of the sales import: every Souk OrderItem needs a variant, so this gives each
 * studio company one Product + Variant per BrushCrazy product it actually sold, plus one generic
 * line per SaleLineKindEnum for the sale items that have no product (class seats, gift cards,
 * deposits, tips, ...).
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
    /** BrushCrazy morph aliases this action mirrors. */
    public const array MORPHS = [self::PRODUCT_MORPH, self::PRODUCT_SETTING_MORPH];

    public const string PRODUCT_MORPH = 'product';

    public const string PRODUCT_SETTING_MORPH = 'product-setting';

    protected IdMapRepository $idMaps;

    /** @var array<string, Variants>|null lookup key => variant */
    protected ?array $variants = null;

    protected ?Regions $region = null;

    protected ?Warehouses $warehouse = null;

    protected bool $warehouseResolved = false;

    /** @var array<int, bool> products already looked up at the source and not found there */
    protected array $missingProducts = [];

    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected StudioContext $context,
        ?IdMapRepository $idMaps = null,
        protected ?Client $client = null,
    ) {
        $this->idMaps = $idMaps ?? new IdMapRepository();
    }

    /**
     * @return array{products: int, lines: int}
     */
    public function execute(): array
    {
        $this->region();

        $productIds = $this->client()->table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.studio_id', $this->context->bcStudioId)
            ->whereNotNull('sale_items.product_id')
            ->distinct()
            ->pluck('sale_items.product_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $synced = 0;

        foreach (array_chunk($productIds, 500) as $chunk) {
            $synced += $this->syncProducts($chunk);
        }

        foreach (SaleLineKindEnum::cases() as $kind) {
            $this->genericVariant($kind);
        }

        return ['products' => $synced, 'lines' => count(SaleLineKindEnum::cases())];
    }

    /**
     * The webhook entry point for `product` and `product-setting` pointers. Only products this
     * studio already carries are refreshed; one it has never sold arrives with its first sale,
     * through variantFor(). Returns the webhook result counter the pointer belongs to.
     */
    public function fromPointer(string $morph, int $entityId): string
    {
        if ($morph === self::PRODUCT_SETTING_MORPH) {
            $setting = $this->client()
                ->table('product_settings')
                ->where('id', $entityId)
                ->first(['product_id', 'studio_id']);

            if ($setting === null || $setting->product_id === null) {
                return SyncSaleFromBrushCrazyAction::MISSING;
            }

            if ((int) $setting->studio_id !== $this->context->bcStudioId) {
                return SyncSaleFromBrushCrazyAction::MISROUTED;
            }

            $entityId = (int) $setting->product_id;
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

        return $this->syncProductRows($products, $settings);
    }

    /**
     * The write path for source rows already in hand — shared by syncProducts() and the tests.
     *
     * @param  iterable<object>  $products
     * @param  array<int|string, object>  $settingsByProductId  this studio's product_settings
     */
    public function syncProductRows(iterable $products, array $settingsByProductId = []): int
    {
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

        foreach ($rows as $bcProductId => $row) {
            $mapped = ProductMapper::map($row, $settingsByProductId[$bcProductId] ?? null);
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

            $this->upsertVariant(
                SaleLineKindEnum::productLookupKey($bcProductId),
                $product,
                $mapped['variant'],
                $mapped['price']
            );
        }

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
     * @param  array<string, mixed>  $attributes
     */
    protected function upsertVariant(
        string $lookupKey,
        Products $product,
        array $attributes,
        float $price
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
            $stock = VariantsWarehouses::withTrashed()->firstOrNew([
                'products_variants_id' => $variant->getId(),
                'warehouses_id' => $warehouse->getId(),
            ]);

            // quantity lost its column default in a later migration; a re-sync must not reset stock.
            if (! $stock->exists) {
                $stock->quantity = 0;
            }

            $stock->fill([
                'price' => $price,
                'sku' => $variant->sku,
                'position' => 0,
            ]);
            $stock->saveQuietly();
        }

        return $this->variants[$lookupKey] = $variant;
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
}
