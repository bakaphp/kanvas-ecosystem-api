<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Models;

use Awobaz\Compoships\Compoships;
use Baka\Support\Arr;
use Baka\Traits\DynamicSearchableTrait;
use Baka\Traits\HasLightHouseCache;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Carbon\Carbon;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Exception;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Kanvas\Activities\Contracts\ActivityLogInterface;
use Kanvas\Activities\Models\Activity;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\Shopify\Traits\HasShopifyCustomField;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Filesystem\Contracts\EntityImportFilesystemInterface;
use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Products\Actions\AddAttributeAction;
use Kanvas\Inventory\Products\Actions\ImportProductFromFilesystemAction;
use Kanvas\Inventory\Products\Builders\ProductSortAttributeBuilder;
use Kanvas\Inventory\Products\Factories\ProductFactory;
use Kanvas\Inventory\Products\Observers\ProductsObserver;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\ProductsTypes\Services\ProductTypeService;
use Kanvas\Inventory\Recommendations\Enums\AudienceEnum;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum as RecommendationConfigurationEnum;
use Kanvas\Inventory\Recommendations\Enums\SearchFieldEnum;
use Kanvas\Inventory\Status\Models\Status;
use Kanvas\Inventory\Traits\ResolvesAttributesTrait;
use Kanvas\Inventory\Variants\Enums\ConfigurationEnum;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Services\VariantService;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Languages\Traits\HasTranslationsDefaultFallback;
use Kanvas\Social\Interactions\Traits\LikableTrait;
use Kanvas\Social\Messages\Traits\HasMessagesTrait;
use Kanvas\Social\Tags\Traits\HasTagsTrait;
use Kanvas\Social\UsersRatings\Traits\HasRating;
use Kanvas\Souk\Enums\ConfigurationEnum as EnumsConfigurationEnum;
use Kanvas\Workflow\Contracts\EntityIntegrationInterface;
use Kanvas\Workflow\Traits\CanUseWorkflow;
use Kanvas\Workflow\Traits\IntegrationEntityTrait;
use Override;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Class Products.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $products_types_id
 * @property int $users_id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property string $description
 * @property ?string $short_description
 * @property ?string $html_description
 * @property ?string $warranty_terms
 * @property ?string $upc
 * @property ?float $weight
 * @property bool $is_published
 * @property string $published_at
 * @property bool $is_deleted
 */
#[ObservedBy(ProductsObserver::class)]
class Products extends BaseModel implements EntityIntegrationInterface, EntityImportFilesystemInterface, ActivityLogInterface
{
    use UuidTrait;
    use SlugTrait;
    use LikableTrait;
    use HasShopifyCustomField;
    use HasTagsTrait;
    use IntegrationEntityTrait;
    use HasMessagesTrait;
    use HasLightHouseCache;
    use DynamicSearchableTrait {
        search as public traitSearch;
    }

    use CascadeSoftDeletes;
    use Compoships;
    use CanUseWorkflow;
    use HasRating;
    use HasTranslationsDefaultFallback;
    use LogsActivity;
    use ResolvesAttributesTrait;

    protected $table = 'products';
    protected $guarded = [];
    protected $cascadeDeletes = ['variants'];

    protected $casts = [
        'is_published' => 'boolean',
        'is_deleted' => 'boolean',
        'rating' => 'float',
    ];

    public $translatable = ['name', 'description', 'short_description', 'html_description', 'warranty_terms'];

    #[Override]
    public function getGraphTypeName(): string
    {
        return 'Product';
    }

    #[Override]
    public function getActivityLogName(): string
    {
        return 'product-' . $this->companies_id . '-' . $this->apps_id;
    }

    public function searchableOptions(): array
    {
        return [
            'hitsPerPage' => 100,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
        ->useLogName($this->getActivityLogName())
        ->setDescriptionForEvent(fn (string $eventName) => "This product has been {$eventName}")
        ->logOnly(['*'])
        ->dontLogIfAttributesChangedOnly(['created_at','updated_at','published_at'])
        ->logOnlyDirty();
    }

    #[Override]
    public function getActivities(): Collection
    {
        return Activity::forSubject($this)
                ->where('log_name', $this->getActivityLogName())
                ->get();
    }

    /**
     * categories.
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            Categories::class,
            ProductsCategories::class,
            'products_id',
            'categories_id'
        );
    }

    /**
     * warehouses.
     */
    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(
            Warehouses::class,
            'products_warehouses',
            'products_id',
            'warehouses_id'
        )->where('products_warehouses.is_deleted', 0);
    }

    /**
     * @psalm-suppress InvalidArrayOffset
     * @psalm-suppress LessSpecificReturnStatement
     * @psalm-suppress InvalidArrayOffset
     */
    public function getAttributeByName(string $name, ?string $locale = null): ?ProductsAttributes
    {
        $locale = $locale ?? app()->getLocale(); // Use app locale if not passed.

        return $this->buildAttributesQuery()
            ->whereRaw("
                IF(
                    JSON_VALID(attributes.name), 
                    json_unquote(json_extract(attributes.name, '$.\"{$locale}\"')), 
                    attributes.name
                ) = ?
            ", [$name])
            ->first();
    }

    public function getAttributeBySlug(string $slug): ?ProductsAttributes
    {
        return $this->attributes()
            ->where('attributes.slug', $slug)
            ->first();
    }

    /**
     * attributes.
     */
    public function attributes(): HasMany
    {
        return $this->buildAttributesQuery();
    }

    /**
     * Eager-loadable version of the visible-attributes query.
     * for n+1 query prevention when resolving visibleAttributesRelation
     */
    public function visibleAttributesRelation(): HasMany
    {
        return $this->buildAttributesQuery(['is_visible' => true]);
    }

    public function visibleAttributes(): array
    {
        /** @var Collection $attributes */
        $attributes = $this->relationLoaded('visibleAttributesRelation')
            ? $this->getRelation('visibleAttributesRelation')
            : $this->buildAttributesQuery(['is_visible' => true])->get();

        return $this->mapAttributes($attributes);
    }

    /**
     * Cheapest priced variant on the default channel, or null when nothing is
     * priced. A shopper reads a product's price as "from $X", so a "under $50"
     * filter has to match a product that HAS a variant under $50 — taking the
     * lowest is what makes that true.
     */
    public function lowestChannelPrice(): ?float
    {
        $prices = [];

        foreach ($this->variants as $variant) {
            try {
                $channelInfo = $variant->getPriceInfoFromDefaultChannel();
            } catch (Exception) {
                continue;
            }

            if ($channelInfo && $channelInfo->price > 0) {
                $prices[] = (float) $channelInfo->price;
            }
        }

        return $prices === [] ? null : min($prices);
    }

    public function hasStockInAnyVariant(): bool
    {
        foreach ($this->variants as $variant) {
            if ($variant->getTotalQuantity() > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * A product with no enrichment yet indexes as `unknown` rather than empty, so
     * the discovery filter can admit it by name — Typesense has no dependable
     * "this array is empty" test.
     *
     * @return list<string>
     */
    public function searchableAudience(): array
    {
        $value = $this->getAttributeByName(SearchFieldEnum::AUDIENCE->value)?->value;

        if (is_string($value) && str_starts_with(trim($value), '[')) {
            $value = json_decode($value, true);
        }

        $audiences = [];

        foreach (is_array($value) ? $value : [$value] as $name) {
            if (is_string($name) && ($name = mb_strtolower(trim($name))) !== '') {
                $audiences[] = $name;
            }
        }

        return $audiences === [] ? [AudienceEnum::UNKNOWN->value] : $audiences;
    }

    public function searchableAttributes(): array
    {
        return $this->mapAttributes(
            $this->buildAttributesQuery(['is_searchable' => true])->get()
        );
    }

    private function buildAttributesQuery(array $conditions = []): HasMany
    {
        //We need to manually query product attribute by this relation so the translate can work for both.
        $query = $this->hasMany(ProductsAttributes::class, 'products_id')
            ->join('attributes', 'products_attributes.attributes_id', '=', 'attributes.id')
            ->select('products_attributes.*', 'attributes.*')
            ->with('attribute'); // Add this line to eager load the attribute relationship

        foreach ($conditions as $column => $value) {
            $query->where("attributes.$column", $value);
        }

        $query->orderBy('attributes.weight', 'asc');

        return $query;
    }

    /**
     * attributes values.
     */
    public function attributeValues(): HasMany
    {
        return $this->hasMany(
            ProductsAttributes::class,
            'products_id',
        );
    }

    /**
     * Attribute values live in two encodings in the same column: a bare scalar written by
     * AddAttributeAction, and a spatie translation map ({"en":0}) written by the
     * updateProductAttributeTranslation mutation. Normalize both to the plain value before comparing.
     *
     * The CASE/COALESCE shape matters: JSON_VALID('0') is TRUE for bare numeric scalars, so a plain
     * IF(JSON_VALID(...)) would extract NULL and silently drop every raw numeric row.
     */
    private static function normalizedAttributeValue(string $column): string
    {
        return "COALESCE(CASE WHEN JSON_VALID({$column}) THEN JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.en')) END, {$column})";
    }

    /**
     * Normalizes a `ProductAttributeFilterInput`-shaped array into named args for
     * `scopeFilterByAttributeValue` — shared by every GraphQL entry point that accepts that input
     * (`products.attributeValues`, `exportProducts.hasAttributeValues`) so they can't drift apart.
     *
     * @param array{value?: mixed, attribute_id?: mixed, slug?: string, operator?: string} $filter
     * @return array{value: string|array<int, string>|null, attributesId: int|null, slug: string|null, operator: string}
     */
    public static function attributeFilterArgsFromInput(array $filter): array
    {
        return [
            'value' => isset($filter['value'])
                ? (is_array($filter['value']) ? $filter['value'] : (string) $filter['value'])
                : null,
            'attributesId' => isset($filter['attribute_id']) ? (int) $filter['attribute_id'] : null,
            'slug' => $filter['slug'] ?? null,
            'operator' => $filter['operator'] ?? 'EQ',
        ];
    }

    /**
     * @param string|array<int, string>|null $value
     */
    public function scopeFilterByAttributeValue(
        Builder $query,
        string|array|null $value = null,
        ?int $attributesId = null,
        ?string $slug = null,
        string $operator = 'EQ',
    ): Builder {
        return $query->whereHas(
            'attributeValues',
            function (Builder $attributeValue) use ($value, $attributesId, $slug, $operator): void {
                $attributeValue->where('products_attributes.is_deleted', 0);

                if ($value !== null) {
                    $normalized = self::normalizedAttributeValue('products_attributes.value');

                    match ($operator) {
                        'NOT_EQ' => $attributeValue->whereRaw("{$normalized} != ?", [$value]),
                        'IN' => $attributeValue->whereIn(DB::raw($normalized), (array) $value),
                        'NOT_IN' => $attributeValue->whereNotIn(DB::raw($normalized), (array) $value),
                        default => $attributeValue->whereRaw("{$normalized} = ?", [$value]),
                    };
                }

                if ($attributesId !== null) {
                    $attributeValue->where('products_attributes.attributes_id', $attributesId);
                }

                if ($slug !== null) {
                    $attributeValue->whereHas(
                        'attribute',
                        fn (Builder $attribute) => $attribute->where('attributes.slug', $slug)
                    );
                }
            }
        );
    }

    public function scopeFilterByVariantAttributeValue(Builder $query, string $value): Builder
    {
        return $query->where('products.is_deleted', 0)
            ->whereHas('variants', function (Builder $query) use ($value) {
                $query->where('products_variants.is_deleted', 0)
                    ->whereHas('attributes', function (Builder $query) use ($value) {
                        $query->where(function ($subQuery) use ($value) {
                            $subQuery
                                ->whereRaw(
                                    self::normalizedAttributeValue('products_variants_attributes.value') . ' LIKE ?',
                                    ['%' . $value . '%']
                                )
                                ->where('products_variants_attributes.is_deleted', 0);
                        });
                    });
            });
    }

    public function scopeOrderByVariantAttribute(
        Builder $query,
        string $name,
        string $format = 'STRING',
        string $sort = 'asc'
    ): Builder {
        $allowedSorts = ['ASC', 'DESC'];
        $sort = strtoupper($sort);

        if (! in_array($sort, $allowedSorts)) {
            throw new InvalidArgumentException('Invalid sort value');
        }

        $query = ProductSortAttributeBuilder::sortProductByVariantAttribute(
            $query,
            $name,
            $format,
            $sort
        );

        return $query;
    }

    public function scopeOrderByAttribute(
        Builder $query,
        string $name,
        string $format = 'STRING',
        string $sort = 'asc'
    ): Builder {
        $allowedSorts = ['ASC', 'DESC'];
        $sort = strtoupper($sort);

        if (! in_array($sort, $allowedSorts)) {
            throw new InvalidArgumentException('Invalid sort value');
        }

        $query = ProductSortAttributeBuilder::sortProductByAttribute(
            $query,
            $name,
            $format,
            $sort
        );

        return $query;
    }

    public function scopeFilterByNearLocation(Builder $query, array $location): Builder
    {
        $earthRadius = 6371; // km
        $lat = (float) $location['lat'];
        $long = (float) $location['long'];
        $radius = (float) $location['radius'];

        $latDelta = $radius / 111.0;
        $longDelta = $radius / (111.0 * cos(deg2rad($lat)));
        $minLat = $lat - $latDelta;
        $maxLat = $lat + $latDelta;
        $minLong = $long - $longDelta;
        $maxLong = $long + $longDelta;

        // Data is double-encoded JSON: {"en": "{\"lat\": \"18.560100\",\"long\": \"-68.372500\"}"}
        $innerJson = "JSON_UNQUOTE(JSON_EXTRACT(pa.value, '$.en'))";
        $latExtract = "CAST(JSON_UNQUOTE(JSON_EXTRACT({$innerJson}, '$.lat')) AS DECIMAL(10,6))";
        $longExtract = "CAST(JSON_UNQUOTE(JSON_EXTRACT({$innerJson}, '$.long')) AS DECIMAL(10,6))";

        $distanceSubquery = DB::connection('inventory')->table('products_attributes as pa')
            ->join('attributes as a', 'a.id', '=', 'pa.attributes_id')
            ->selectRaw("
                pa.products_id,
                ({$earthRadius} * acos(
                    least(1, cos(radians(?)) *
                    cos(radians({$latExtract})) *
                    cos(radians({$longExtract}) - radians(?)) +
                    sin(radians(?)) *
                    sin(radians({$latExtract}))
                    )
                )) AS distance
            ", [$lat, $long, $lat])
            ->where('a.slug', 'coordinates')
            ->whereRaw('JSON_VALID(pa.value)')
            ->whereRaw("JSON_EXTRACT(pa.value, '$.en') IS NOT NULL")
            ->whereRaw("JSON_VALID({$innerJson})")
            ->whereRaw("{$latExtract} BETWEEN ? AND ?", [$minLat, $maxLat])
            ->whereRaw("{$longExtract} BETWEEN ? AND ?", [$minLong, $maxLong])
            ->whereRaw("{$latExtract} != 0")
            ->whereRaw("{$longExtract} != 0")
            ->havingRaw('distance <= ?', [$radius]);

        return $query
            ->where('products.is_deleted', 0)
            ->joinSub($distanceSubquery, 'location_distance', function ($join) {
                $join->on('products.id', '=', 'location_distance.products_id');
            })
            ->select('products.*', 'location_distance.distance')
            ->reorder()
            ->orderByRaw('location_distance.distance ASC');
    }

    public function scopeFilterByNearWarehouseLocation(Builder $query, array $location): Builder
    {
        $earthRadius = 6371; // km
        $lat = (float) $location['lat'];
        $long = (float) $location['long'];
        $radius = (float) $location['radius'];

        // Bounding box pre-filter
        $latDelta = $radius / 111.0;
        $longDelta = $radius / (111.0 * cos(deg2rad($lat)));
        $minLat = $lat - $latDelta;
        $maxLat = $lat + $latDelta;
        $minLong = $long - $longDelta;
        $maxLong = $long + $longDelta;

        $distanceSubquery = DB::connection('inventory')->table('products_variants_warehouses as pvw')
            ->join('products_variants as pv', 'pv.id', '=', 'pvw.products_variants_id')
            ->selectRaw("
                pv.products_id,
                MIN({$earthRadius} * acos(
                    least(1, cos(radians(?)) *
                    cos(radians(pvw.latitude)) *
                    cos(radians(pvw.longitude) - radians(?)) +
                    sin(radians(?)) *
                    sin(radians(pvw.latitude))
                    )
                )) AS distance
            ", [$lat, $long, $lat])
            ->whereNotNull('pvw.latitude')
            ->whereNotNull('pvw.longitude')
            ->where('pvw.is_deleted', 0)
            ->where('pv.is_deleted', 0)
            ->whereBetween('pvw.latitude', [$minLat, $maxLat])
            ->whereBetween('pvw.longitude', [$minLong, $maxLong])
            ->groupBy('pv.products_id')
            ->havingRaw('distance <= ?', [$radius]);

        return $query
            ->where('products.is_deleted', 0)
            ->joinSub($distanceSubquery, 'warehouse_location', function ($join) {
                $join->on('products.id', '=', 'warehouse_location.products_id');
            })
            ->select('products.*', 'warehouse_location.distance')
            ->reorder()
            ->orderByRaw('warehouse_location.distance ASC');
    }

    /**
     * variants.
     */
    public function variants(): HasMany
    {
        $app = $this->app ?? app(Apps::class);
        if ($app->get('product_variants_sort_by_weight')) {
            return $this->hasMany(Variants::class, 'products_id')->orderBy('weight', 'asc');
        }

        return $this->hasMany(Variants::class, 'products_id');
    }

    public function productsCategories(): HasMany
    {
        return $this->hasMany(ProductsCategories::class, 'products_id');
    }

    /**
     * productsTypes.
     * @deprecated
     */
    public function productsTypes(): BelongsTo
    {
        return $this->belongsTo(ProductsTypes::class, 'products_types_id');
    }

    /**
     * productTypes.
     * @deprecated
     */
    public function productsType(): BelongsTo
    {
        return $this->belongsTo(ProductsTypes::class, 'products_types_id');
    }

    /**
     * productTypes.
     * @deprecated
     */
    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductsTypes::class, 'products_types_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ProductsTypes::class, 'products_types_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    #[Override]
    public function shouldBeSearchable(): bool
    {
        if ($this->company?->get('index_product_must_have_price')) {
            foreach ($this->variants as $variant) {
                try {
                    if ($channelInfo = $variant->getPriceInfoFromDefaultChannel()) {
                        return $this->isPublished() && $channelInfo->price > 0;
                    }
                } catch (Exception $e) {
                    return false;
                }
            }
        }

        return $this->isPublished();
    }

    /**
     * @todo refactor this method is to long
     */
    public function toSearchableArray(): array
    {
        $product = [
            'objectID' => $this->uuid,
            'id' => (string) $this->id,
            'name' => $this->name,
            'files' => $this->getFiles()->take(5)->map(function ($files) { //for now limit
                return [
                    'uuid' => $files->uuid,
                    'name' => $files->name,
                    'url' => $files->url,
                    'size' => $files->size,
                    'field_name' => $files->field_name,
                ];
            }),
            'company' => [
                'id' => $this->companies_id,
                'name' => $this->company?->name,
            ],
            'user' => [
                'id' => $this->user?->getId(),
                'firstname' => $this->user?->firstname,
                'lastname' => $this->user?->lastname,
            ],
            'categories' => $this->categories->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'position' => $category->position,
                ];
            }),
            // Keys become Typesense sub-fields (`categories_flat.<name>`), so a blank category name
            // registers the invalid field `categories_flat.` and the whole import batch is rejected
            // (Sentry KANVAS-ECOSYSTEM-628).
            'categories_flat' => $this->categories
                ->map(fn (Categories $category) => trim((string) $category->name))
                ->filter(fn (string $name) => $name !== '')
                ->mapWithKeys(fn (string $name) => [$name => 1])
                ->all(),
            'variants' => $this->getVariantsData(),
            'status' => [
                'id' => $this->status->id ?? null,
                'name' => $this->status->name ?? null,
            ],
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'is_published' => $this->is_published,
            'description' => $this->description,
            'short_description' => $this->short_description,
            'product_type_slug' => $this->productsType?->slug ?? null,
            'attributes' => [],
            'weight' => (int) ($this->weight ?? 0),
            'rating' => (float) ($this->rating ?? 0),
            'translations' => [
                'name' => $this->getAllTranslationsAsString('name'),
                'description' => $this->getAllTranslationsAsString('description'),
            ],
            'apps_id' => $this->apps_id,
            'published_at' => $this->published_at,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            // Flat, index-friendly discovery fields. search_blurb is the vector
            // embedding source and price/in_stock are what a budget or
            // availability filter can act on — a nested custom_fields path can
            // be neither an `embed.from` source nor a scalar filter.
            'search_blurb' => (string) ($this->get(SearchFieldEnum::BLURB->value) ?? ''),
            // Flat alongside the nested `company` object: a scalar filter_by
            // cannot reach into a nested field without enable_nested_fields.
            'companies_id' => $this->companies_id,
            // 0 rather than null for an unknown price: Typesense types the field
            // as float, and a shopper's "under $50" should still surface an
            // unpriced product, which the caller then flags unavailable.
            'price' => $this->lowestChannelPrice() ?? 0.0,
            'in_stock' => $this->hasStockInAnyVariant(),
            // Flat copy of the enrichment's `audience` attribute. Nested under
            // `attributes` it cannot be a scalar filter, and this is the one axis
            // an embedding gets wrong — "for a man" and "para mujeres" are similar
            // to a vector, not opposite.
            'audience' => $this->searchableAudience(),
        ];

        if ($this->isTypesense()) {
            $product['created_at'] = $this->created_at->timestamp;
            $product['custom_fields'] = [];

            if ($this->app->get(EnumsConfigurationEnum::B2B_GLOBAL_COMPANY->value)) {
                $product['prices'] = $this->buildB2bGlobalPrices();
            }
        }

        $attributes = $this->searchableAttributes();
        foreach ($attributes as $attribute) {
            $product['attributes'][$attribute['name']] = is_array($attribute['value'])
                ? $attribute['value']
                : (string) $attribute['value'];
        }

        $customFields = $this->getAllCustomFields();
        foreach ($customFields as $key => $value) {
            $product['custom_fields'][$key] = is_array($value)
                ? $value
                : (string) $value;
        }

        if ($this->isAlgolia()) {
            $product = $this->fitWithinAlgoliaRecordLimit($product);
        }

        return $product;
    }

    protected function fitWithinAlgoliaRecordLimit(array $product): array
    {
        return $this->trimToAlgoliaLimit($product)
            // Warehouse breakdown is internal stock detail, never shown in search — losing it costs
            // nothing, so it goes before anything a human would notice.
            ->trim(fn (array $p) => [...$p, 'variants' => $this->stripFromVariants($p['variants'], ['warehouses'])])
            // Shorten the long values in the heavy text buckets, cheapest bucket first. Every key
            // survives — `attributes` in particular is only ever truncated, never dropped, because
            // the Algolia facets are built on `attributes.*.quick_spec`.
            ->truncateStrings('custom_fields')
            ->truncateStrings('translations')
            ->truncateStrings('attributes')
            ->limitString('description', 500)
            ->limitString('short_description', 200)
            // Still over after truncating: shed whole entries, heaviest first, only as many as it
            // takes. `attributes` is excluded on purpose (see above) — only these two lose keys.
            ->dropHeaviestEntries('custom_fields')
            ->dropHeaviestEntries('translations')
            ->trim(fn (array $p) => [...$p, 'variants' => $this->stripFromVariants($p['variants'], ['files'])])
            // Reduce variants to the fields a storefront actually renders before dropping any of
            // them: partial variant data beats none.
            ->trim(fn (array $p) => [...$p, 'variants' => $this->stripFromVariants(
                $p['variants'],
                [
                    'objectID',
                    'products_id',
                    'company',
                    'description',
                    'short_description',
                    'ean',
                    'barcode',
                    'apps_id',
                    'rating',
                ]
            )])
            // Extra product images are pure weight once the record is this tight; keep the first.
            ->keepFirst('files', 1)
            ->popUntilFit(
                'variants',
                // Silent variant loss is how an entire tenant ended up indexed with `variants: []`.
                fn (int $dropped, array $p) => Log::warning('Algolia record over budget, dropped variants to fit', [
                    'product_id' => $this->id,
                    'apps_id' => $this->apps_id,
                    'companies_id' => $this->companies_id,
                    'dropped_variants' => $dropped,
                    'remaining_variants' => count($p['variants']),
                    'limit' => $this->algoliaRecordSizeLimit(),
                    'size_without_variants' => Arr::sizeInBytes(Arr::except($p, ['variants'])),
                ])
            )
            ->get();
    }

    protected function stripFromVariants(mixed $variants, array $keys): array
    {
        return collect($variants)
            ->map(function ($variant) use ($keys) {
                $variant = (array) $variant;
                foreach ($keys as $key) {
                    unset($variant[$key]);
                }

                return $variant;
            })
            ->values()
            ->all();
    }

    public function getAllTranslationsAsString(string $key): string
    {
        $translations = $this->getTranslations($key);

        if (empty($translations)) {
            return '';
        }

        // Join translations with a comma
        return implode(', ', array_map(fn ($translation) => (string) $translation, $translations));
    }

    public function searchableAs(): string
    {
        // As for this stage, the code doesn't know in which app need to set the index.
        $product = ! $this->searchableDeleteRecord() ? $this : $this->withTrashed()->find($this->id);
        $app = $product->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_product_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'product_index');
    }

    public static function search($query = '', $callback = null)
    {
        $app = app(Apps::class);
        $model = new static();
        $isTypesense = method_exists($model, 'isTypesense') ? $model->isTypesense() : false;

        $searchQuery = self::traitSearch($query, $callback)->where('apps_id', $app->getId());

        $user = auth()->user();

        if (
            $user instanceof UserInterface &&
            (
                ! auth()->user()->isAppOwner() ||
                (
                    app()->bound(CompaniesBranches::class) &&
                    $app->get('enable_company_bound_search', false)
                )
            )
        ) {
            $searchQuery->where('company.id', auth()->user()->getCurrentCompany()->getId());
        }

        if ($isTypesense) {
            $searchQuery->options([
                'query_by' => 'name,description',
            ]);
        }

        return $searchQuery;
    }

    public function isPublished(): bool
    {
        if (isset($this->app) && $this->app->get('allow_unpublished_products')) {
            return ! $this->is_deleted;
        }

        return ! $this->is_deleted && $this->is_published;
    }

    public function addVariant(array $variant): Variants
    {
        return current(VariantService::createVariantsFromArray($this, [$variant], $this->user));
    }

    public function addVariants(array $variants): array
    {
        return VariantService::createVariantsFromArray($this, $variants, $this->user);
    }

    #[Override]
    public static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    public function hasStock(Warehouses $warehouses): bool
    {
        foreach ($this->variants as $variant) {
            if ($variant->getQuantity($warehouses)) {
                return true;
            }
        }

        return false;
    }

    public function hasPrice(Warehouses $warehouse, ?Channels $channel = null): bool
    {
        foreach ($this->variants as $variant) {
            if ($variant->getPrice($warehouse, $channel)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add/create new attributes from a product.
     */
    public function addAttributes(UserInterface $user, array $attributes): void
    {
        /**
         * Resolve every attribute first and write in ascending attribute id, so concurrent
         * importers take the locks on the shared `attributes` rows in the same order and
         * can't deadlock against each other.
         */
        $resolvedAttributes = [];

        foreach ($attributes as $attribute) {
            if (! isset($attribute['value']) || ($attribute['name'] ?? null) === null) {
                continue; // Skip attributes without a value
            }

            $attributeModel = $this->resolveAttribute($user, $attribute);

            if ($attributeModel === null) {
                continue;
            }

            $resolvedAttributes[$attributeModel->getId()] = [
                'model' => $attributeModel,
                'value' => $attribute['value'],
            ];
        }

        ksort($resolvedAttributes);

        foreach ($resolvedAttributes as $resolvedAttribute) {
            new AddAttributeAction($this, $resolvedAttribute['model'], $resolvedAttribute['value'])->execute();

            if ($this->productsType !== null) {
                ProductTypeService::addAttributes(
                    $this->productsType,
                    $this->user,
                    [
                        [
                            'id' => $resolvedAttribute['model']->getId(),
                            'value' => $resolvedAttribute['value'],
                        ],
                    ]
                );
            }
        }
    }

    public function addAttribute(string $name, mixed $value): void
    {
        $this->addAttributes($this->user, [['name' => $name, 'value' => $value]]);
    }

    public function unPublish(): void
    {
        $this->is_published = 0;
        $this->save();
    }

    public function publish(): void
    {
        $this->is_published = 1;
        $this->published_at = Carbon::now();
        $this->save();
    }

    /**
     * Build the price_b2b_{companyId} map for the B2B_GLOBAL_COMPANY index path.
     *
     * Aggregates MAX(pivot.price) per channel slug in one SQL pass and bulk-resolves
     * the matching companies — replaces a per-variant lazy load + per-channel
     * Companies::getByUuid() loop that OOM'd on products with hundreds of variants.
     */
    protected function buildB2bGlobalPrices(): array
    {
        $rows = DB::connection($this->getConnectionName())
            ->table('products_variants_channels as pvc')
            ->join('channels as c', 'c.id', '=', 'pvc.channels_id')
            ->join('products_variants as v', 'v.id', '=', 'pvc.products_variants_id')
            ->where('v.products_id', $this->getId())
            ->groupBy('c.slug')
            ->selectRaw('c.slug as slug, MAX(pvc.price) as max_price')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        /** @var array<string, int> $companiesBySlug */
        $companiesBySlug = Companies::whereIn('uuid', $rows->pluck('slug')->all())
            ->notDeleted()
            ->pluck('id', 'uuid')
            ->all();

        $prices = [];
        foreach ($rows as $row) {
            $companyId = $companiesBySlug[$row->slug] ?? null;
            if ($companyId === null) {
                continue;
            }
            $prices['price_b2b_' . $companyId] = (float) $row->max_price;
        }

        return $prices;
    }

    protected function getVariantsData(): Collection
    {
        $limit = $this->app->get(ConfigurationEnum::PRODUCT_VARIANTS_SEARCH_LIMIT->value) ?? 200;

        $query = $this->variants()
            ->where('is_deleted', 0)
            ->where('is_published', 1);

        $useSummary = $query->count() > $limit;

        // Eager load the relations each variant->toSearchableArray() touches so we don't fan out
        // into N+1 channel/warehouse/status reads while building the search payload. Summary path
        // only renders channels.
        $eagerLoad = $useSummary
            ? ['channels']
            : ['channels', 'variantWarehouses.warehouse', 'variantWarehouses.status', 'status'];

        // Bound peak memory by streaming the variants in small batches instead of materialising
        // up to PRODUCT_VARIANTS_SEARCH_LIMIT models at once — the Scout indexer was OOMing at
        // 512MB because the outer chunk of products + every variant + every relation lived in
        // memory simultaneously.
        $items = [];
        $query
            ->with($eagerLoad)
            ->orderBy('id')
            ->chunkById(50, function (Collection $variants) use (&$items, $useSummary, $limit): bool {
                foreach ($variants as $variant) {
                    if ($useSummary && count($items) >= $limit) {
                        return false;
                    }

                    $items[] = $useSummary
                        ? $variant->toSearchableArraySummary()
                        : $variant->toSearchableArray();
                }

                return true;
            });

        return collect($items);
    }

    public function getTotalVariants(): int
    {
        return (int) ($this->get('total_variants') ?? 0);
    }

    public function setTotalVariants(): void
    {
        $this->set('total_variants', Variants::where('products_id', $this->getId())->count());
    }

    /**
     * The Typesense schema to be created.
     */
    /**
     * Which model Typesense should auto-embed with, or null to leave the vector
     * half off entirely.
     *
     * An OpenAI key wins when set. Otherwise a tenant can name one of the models
     * Typesense ships with (`ts/…`), which run inside the cluster and need no
     * API key — the only way to get multilingual embeddings without an external
     * provider, and what makes an ES catalog answer an EN query.
     */
    private function resolveEmbeddingModelConfig(): ?array
    {
        $openAiKey = $this->app->get(AppSettingsEnums::OPEN_AI_EMBEDDING_KEY->getValue());

        if ($openAiKey) {
            return [
                'model_name' => 'openai/text-embedding-3-small',
                'api_key' => $openAiKey,
            ];
        }

        $builtInModel = $this->app->get(RecommendationConfigurationEnum::EMBEDDING_MODEL->value);

        return is_string($builtInModel) && $builtInModel !== ''
            ? ['model_name' => $builtInModel]
            : null;
    }

    public function typesenseCollectionSchema(): array
    {
        $schema = [
            'name' => $this->searchableAs(),
            'fields' => [
                [
                    'name' => 'objectID',
                    'type' => 'string',
                ],
                [
                    'name' => 'id',
                    'type' => 'string',
                ],
                [
                    'name' => 'name',
                    'type' => 'string',
                    'sort' => true,
                    // 'facet' => true,
                ],
                [
                    'name' => 'files',
                    'type' => 'object[]',
                    'optional' => true,
                ],
                [
                    'name' => 'product_type_slug',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'company',
                    'type' => 'object',
                ],
                [
                    'name' => 'user',
                    'type' => 'object',
                ],
                [
                    // Optional because a product with no categories is normal, and
                    // Typesense rejects the whole document when a required object[]
                    // arrives empty — it breaks indexing into any fresh collection.
                    'name' => 'categories',
                    'type' => 'object[]',
                    'facet' => true,
                    'optional' => true,
                ],
                [
                    'name' => 'variants',
                    'type' => 'object[]',
                    'optional' => true,
                ],
                [
                    'name' => 'variants.warehouses.price',
                    'type' => 'float[]',
                    'optional' => true,
                ],
                [
                    'name' => 'variants.channels.price',
                    'type' => 'float[]',
                    'optional' => true,
                ],
                [
                    'name' => 'variants.rating',
                    'type' => 'float[]',
                    'optional' => true,
                ],
                [
                    'name' => 'status',
                    'type' => 'object',
                    'optional' => true,
                ],
                [
                    'name' => 'categories_flat',
                    'type' => 'auto',
                    'optional' => true,
                ],
                [
                    'name' => 'uuid',
                    'type' => 'string',
                ],
                [
                    'name' => 'slug',
                    'type' => 'string',
                ],
                [
                    'name' => 'is_published',
                    'type' => 'bool',
                ],
                [
                    'name' => 'description',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'short_description',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'attributes',
                    'type' => 'object',
                    'optional' => true,
                ],
                [
                    'name' => 'custom_fields',
                    'type' => 'object',
                    'optional' => true,
                ],
                [
                    'name' => 'weight',
                    'type' => 'float',
                    'optional' => true,
                    'sort' => true,
                ],
                [
                    'name' => 'rating',
                    'type' => 'float',
                    'optional' => true,
                    'sort' => true,
                ],
                [
                    'name' => 'prices',
                    'type' => 'object',
                    'optional' => true,
                    'facet' => true,
                ],
                // buildB2bGlobalPrices() names its keys after the company id, so the children
                // can't be listed one by one — a regex field types them all as float up front and
                // keeps a whole 100.00 (encoded `100`) from locking them to int64.
                [
                    'name' => 'prices\\..*',
                    'type' => 'float',
                    'optional' => true,
                ],
                [
                    'name' => 'translations',
                    'type' => 'object',
                    'optional' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'translations.name',
                    'type' => 'string',
                    'optional' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'translations.description',
                    'type' => 'string',
                    'optional' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'prices.*',
                    'type' => 'float',
                    'optional' => true,
                    'sort' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'prices.regular',
                    'type' => 'float',
                    'optional' => true,
                    'sort' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'prices.sale',
                    'type' => 'float',
                    'optional' => true,
                    'sort' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'prices.msrp',
                    'type' => 'float',
                    'optional' => true,
                    'sort' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'apps_id',
                    'type' => 'int64',
                ],
                [
                    'name' => 'companies_id',
                    'type' => 'int64',
                    'facet' => true,
                ],
                // Discovery fields: the enrichment blurb is what semantic search
                // matches on, and price/in_stock are the only scalars a budget or
                // availability filter can act on.
                [
                    'name' => 'search_blurb',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'price',
                    'type' => 'float',
                    'optional' => true,
                    'sort' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'in_stock',
                    'type' => 'bool',
                    'optional' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'audience',
                    'type' => 'string[]',
                    'optional' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'published_at',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'created_at',
                    'type' => 'int64',
                    'sort' => true,
                ],
            ],
            'default_sorting_field' => 'created_at',
            'enable_nested_fields' => true,  // Enable nested fields support for complex objects
        ];
        $embeddingModelConfig = $this->resolveEmbeddingModelConfig();

        if ($embeddingModelConfig !== null) {
            $schema['fields'][] = [
                'name' => 'embedding',
                'type' => 'float[]',
                'embed' => [
                    // The enrichment blurb first: it describes who a product is
                    // for, in the same register a shopper writes their request,
                    // which is what the vector half is there to match.
                    'from' => [
                        'search_blurb',
                        'name',
                        'description',
                    ],
                    'model_config' => $embeddingModelConfig,
                ],
            ];
        }

        foreach ($this->filterableAttributeFacetFields() as $facetField) {
            $schema['fields'][] = $facetField;
        }

        return $schema;
    }

    /**
     * Typesense facets each nested key of the `attributes` object only if it's declared explicitly.
     * We expose as facets ONLY the attributes flagged `is_filtrable` (the "Use as Filter" toggle),
     * scoped to the app that owns this collection. `type: auto` tolerates both scalar and array values.
     *
     * @return array<int, array<string, mixed>>
     */
    private function filterableAttributeFacetFields(): array
    {
        return Attributes::query()
            ->fromApp($this->app)
            ->where('is_filtrable', 1)
            ->where('is_deleted', 0)
            ->get()
            ->map(fn (Attributes $attribute) => $attribute->name)
            ->unique()
            ->filter()
            ->map(fn (string $name) => [
                'name' => 'attributes.' . $name,
                'type' => 'auto',
                'optional' => true,
                'facet' => true,
            ])
            ->values()
            ->all();
    }

    #[Override]
    public static function getImportHandler(FilesystemImports $filesystemImport): mixed
    {
        return new ImportProductFromFilesystemAction($filesystemImport);
    }

    public function recalculateWeightByImageCount(): void
    {
        if (! $this->company->get('product_increase_weight_by_image_count')) {
            return;
        }

        $totalImages = $this->variants()
            ->with('files')
            ->get()
            ->sum(fn ($variant) => $variant->files->count());

        // Boost products with 2+ images
        $imageBoost = $totalImages >= 2 ? 1.0 : 0;

        // Or gradual boost: $imageBoost = min($totalImages * 0.5, 2.0);
        $this->weight = $imageBoost;
        $this->saveQuietly();
    }
}
