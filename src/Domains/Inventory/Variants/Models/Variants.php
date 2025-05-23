<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Models;

use Awobaz\Compoships\Compoships;
use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Enums\StateEnums;
use Baka\Support\Str;
use Baka\Traits\DynamicSearchableTrait;
use Baka\Traits\HasLightHouseCache;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Shopify\Traits\HasShopifyCustomField;
use Kanvas\Inventory\Attributes\Actions\CreateAttribute;
use Kanvas\Inventory\Attributes\DataTransferObject\Attributes as AttributesDto;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Enums\AppEnums;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Services\ProductTypeService;
use Kanvas\Inventory\Status\Models\Status;
use Kanvas\Inventory\Variants\Actions\AddAttributeAction;
use Kanvas\Inventory\Variants\Observers\VariantObserver;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Languages\Traits\HasTranslationsDefaultFallback;
use Kanvas\Social\Interactions\Traits\SocialInteractionsTrait;
use Kanvas\Social\UsersRatings\Traits\HasRating;
use Kanvas\Workflow\Contracts\EntityIntegrationInterface;
use Kanvas\Workflow\Traits\CanUseWorkflow;
use Kanvas\Workflow\Traits\IntegrationEntityTrait;
use Laravel\Scout\Searchable;
use Override;

/**
 * Class Attributes.
 *
 * @property int apps_id
 * @property int companies_id
 * @property int products_id
 * @property string uuid
 * @property string name
 * @property string slug
 * @property string description
 * @property string short_description
 * @property string html_description
 * @property string sku
 * @property int status_id
 * @property int is_published
 * @property string ean
 * @property string barcode
 * @property string serial_number
 * @property int is_deleted
 */
#[ObservedBy(VariantObserver::class)]
class Variants extends BaseModel implements EntityIntegrationInterface
{
    use SlugTrait;
    use UuidTrait;
    use SocialInteractionsTrait;
    use HasShopifyCustomField;
    use HasLightHouseCache;
    use IntegrationEntityTrait;
    use DynamicSearchableTrait {
        search as public traitSearch;
    }

    use CascadeSoftDeletes;
    use Compoships;
    use CanUseWorkflow;
    use HasRating;
    use HasTranslationsDefaultFallback;

    protected $is_deleted;
    protected $cascadeDeletes = ['variantChannels', 'variantWarehouses', 'variantAttributes'];
    public $translatable = ['name','description','short_description','html_description'];

    protected $table = 'products_variants';
    protected $touches = ['attributes'];
    protected $fillable = [
        'users_id',
        'products_id',
        'companies_id',
        'name',
        'uuid',
        'description',
        'short_description',
        'status_id',
        'barcode',
        'serial_number',
        'slug',
        'html_description',
        'sku',
        'ean',
        'weight',
        'is_published',
        'apps_id',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'is_deleted' => 'boolean',
    ];

    protected $guarded = [];
    protected static ?string $overWriteSearchIndex = null;

    #[Override]
    public function getGraphTypeName(): string
    {
        return 'Variant';
    }

    public static function searchableIndex(): string
    {
        return AppEnums::PRODUCT_VARIANTS_SEARCH_INDEX->getValue();
    }

    #[Override]
    public function shouldBeSearchable(): bool
    {
        return $this->isPublished() && $this->product;
    }

    public function isPublished(): bool
    {
        return (int) $this->is_deleted === 0 && $this->is_published;
    }

    /**
     * Get the user that owns the Variants.
     */
    public function products(): BelongsTo
    {
        return $this->belongsTo(Products::class, 'products_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Products::class, 'products_id');
    }

    public function variantWarehouses(): HasMany
    {
        return $this->hasMany(VariantsWarehouses::class, 'products_variants_id');
    }

    public function variantChannels(): HasMany
    {
        return $this->hasMany(VariantsChannels::class, 'products_variants_id');
    }

    public function variantAttributes(): HasMany
    {
        return $this->hasMany(VariantsAttributes::class, 'products_variants_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    public function scopeFilterByPublished(Builder $query, bool $includeUnpublished = false): Builder
    {
        if (! $includeUnpublished) {
            return $query->where('is_published', true);
        }

        return $query;
    }

    /**
     * warehouses.
     */
    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(
            Warehouses::class,
            VariantsWarehouses::class,
            'products_variants_id',
            'warehouses_id'
        );
    }

    /**
     * attributes.
     */
    public function attributes(): HasMany
    {
        return $this->buildAttributesQuery();
    }

    /**
     * @todo add integration and graph test
     */
    public function visibleAttributes(): array
    {
        return $this->mapAttributes(
            $this->buildAttributesQuery(['is_visible' => true])->get()
        );
    }

    /**
     * @psalm-suppress InvalidArrayOffset
     * @psalm-suppress LessSpecificReturnStatement
     * @psalm-suppress InvalidArrayOffset
     */
    public function getAttributeByName(string $name, ?string $locale = null): ?VariantsAttributes
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

    public function getAttributeBySlug(string $slug): ?VariantsAttributes
    {
        return $this->attributes()
            ->where('attributes.slug', $slug)
            ->first();
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
        $query = $this->hasMany(VariantsAttributes::class, 'products_variants_id')
            ->join('attributes', 'products_variants_attributes.attributes_id', '=', 'attributes.id')
            ->select('products_variants_attributes.*', 'attributes.*');

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
            VariantsAttributes::class,
            'products_variants_id',
        );
    }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(
            Channels::class,
            VariantsChannels::class,
            'products_variants_id',
            'channels_id'
        )
        ->withPivot(
            'price',
            'discounted_price',
            'is_published',
            'warehouses_id',
            'channels_id',
            'config'
        );
    }

    /**
     * @psalm-suppress MixedInferredReturnType
     */
    public function getPriceInfoFromDefaultChannel(): Channels
    {
        //@todo add is_default to channels
        $channel = Channels::where('is_default', true)
            ->where('apps_id', $this->apps_id)
            ->notDeleted()
            ->where('is_published', StateEnums::ON->getValue())
            ->where('companies_id', $this->companies_id)
            ->firstOrFail();

        return $this->channels()->where('channels_id', $channel->getId())->firstOrFail();
    }

    /**
     * Add/create new attributes from a variant.
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedArrayAccess
     * @psalm-suppress MixedPropertyFetch
     */
    public function addAttributes(UserInterface $user, array $attributes): void
    {
        foreach ($attributes as $attribute) {
            if (! isset($attribute['value']) || $attribute['name'] === null) {
                continue;
            }

            if (isset($attribute['id'])) {
                $attributeModel = Attributes::getById((int) $attribute['id'], $this->app);
            } elseif (! empty($attribute['name'])) {
                $attributesDto = AttributesDto::from([
                    'app' => app(Apps::class),
                    'user' => $user,
                    'company' => $this->product->company,
                    'name' => $attribute['name'],
                    'value' => $attribute['value'],
                    'isVisible' => true,
                    'isSearchable' => true,
                    'isFiltrable' => true,
                    'slug' => Str::slug($attribute['name']),
                ]);
                $attributeModel = (new CreateAttribute($attributesDto, $user))->execute();
            }

            if ($attributeModel) {
                (new AddAttributeAction($this, $attributeModel, $attribute['value']))->execute();

                if ($this->product?->productsType) {
                    ProductTypeService::addAttributes(
                        $this->product->productsType,
                        $this->user,
                        [
                            [
                                'id' => $attributeModel->getId(),
                                'value' => $attribute['value'],
                            ],
                        ],
                        toVariant: true
                    );
                }
            }
        }
    }

    public function addAttribute(string $name, mixed $value): void
    {
        $this->addAttributes($this->user, [[
            'name' => $name,
            'value' => $value,
        ]]);
    }

    /**
     * Set status for the current variant.
     */
    public function setStatus(Status $status): void
    {
        $this->status_id = $status->getId();
        $this->saveOrFail();
    }

    public function toSearchableArray(): array
    {
        $variant = [
            'objectID' => $this->uuid,
            'id' => (string)$this->id,
            'products_id' => $this->products_id,
            'name' => $this->name,
            'files' => $this->getFiles()->take(5)->map(function ($files) {
                return [
                    'uuid' => $files->uuid,
                    'name' => $files->name,
                    'url' => $files->url,
                    'size' => $files->size,
                    'field_name' => $files->field_name,
                    'attributes' => $files->attributes,
                ];
            }),
            'company' => [
                'id' => $this?->product?->companies_id,
                'name' => $this?->product?->company?->name,
            ],
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'status' => [
                'id' => $this->status->id ?? null,
                'name' => $this->status->name ?? null,
            ],
            'warehouses' => $this->variantWarehouses->map(function ($variantWarehouses) {
                return [
                    'id' => $variantWarehouses->warehouse->getId(),
                    'name' => $variantWarehouses->warehouse->name,
                    'price' => $variantWarehouses->price,
                    'quantity' => $variantWarehouses->quantity,
                    'status' => [
                        'id' => $variantWarehouses?->status ? $variantWarehouses->status->getId() : null,
                        'name' => $variantWarehouses?->status ? $variantWarehouses->status->name : null,
                    ],
                ];
            }),
            'channels' => $this->channels->map(function ($channels) {
                return [
                    'id' => $channels->getId(),
                    'name' => $channels->name,
                    'price' => (float) $channels->price,
                    'is_published' => $channels->is_published,
                ];
            }),
            'description' => null, //$this->description,
            'short_description' => null, //$this->short_description,
            'attributes' => [],
            'apps_id' => $this->apps_id,
        ];
        $attributes = $this->attributes()->get();
        foreach ($attributes as $attribute) {
            //if its over 100 characters we dont want to index it
            if (! is_array($attribute->value) && strlen((string) $attribute->value) > 100) {
                continue;
            }
            $variant['attributes'][$attribute->name] = $attribute->value;
        }

        return $variant;
    }

    public function toSearchableArraySummary(): array
    {
        $variant = [
            'objectID' => $this->uuid,
            'id' => $this->id,
            'name' => $this->name,
            'company' => [
                'id' => $this?->product?->companies_id,
                'name' => $this?->product?->company?->name,
            ],
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'sku' => $this->sku,

            'channels' => $this->channels->map(function ($channels) {
                return [
                    'id' => $channels->getId(),
                    'name' => $channels->name,
                    'price' => (float) $channels->price,
                    'is_published' => $channels->is_published,
                ];
            }),
            'attributes' => [],
        ];
        $attributes = $this->attributes()->get();
        foreach ($attributes as $attribute) {
            //if its over 100 characters we dont want to index it
            if (! is_array($attribute->value) && strlen((string) $attribute->value) > 100) {
                continue;
            }
            $variant['attributes'][$attribute->name] = $attribute->value;
        }

        return $variant;
    }

    public function searchableAs(): string
    {
        $variant = ! $this->searchableDeleteRecord() ? $this : $this->withTrashed()->find($this->id);
        $app = $variant->app ?? app(Apps::class);

        $customIndex = $app->get('app_custom_product_variant_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'product_variant_index');
    }

    public static function search($query = '', $callback = null)
    {
        $query = self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
        $user = auth()->user();
        if ($user instanceof UserInterface && ! auth()->user()->isAppOwner()) {
            $query->where('company.id', auth()->user()->getCurrentCompany()->getId());
        }

        return $query;
    }

    /**
     * Modify the query used to retrieve models when making all of the models searchable.
     */
    protected function makeAllSearchableUsing(Builder $query): Builder
    {
        return $query->whereRelation('warehouses', 'warehouses.is_deleted', 0);
    }

    /**
     * Get the total amount of variants in all the warehouses.
     */
    public function getTotalQuantity(): int
    {
        if (! $totalVariantQuantity = $this->get('total_variant_quantity')) {
            return (int) $this->setTotalQuantity();
        }

        return (int) $totalVariantQuantity;
    }

    public function getQuantity(Warehouses $warehouse): float
    {
        $warehouseInfo = $this->variantWarehouses()->where('warehouses_id', $warehouse->getId())->first();

        return $warehouseInfo?->quantity ?? 0;
    }

    public function getPrice(Warehouses $warehouse, ?Channels $channel = null): float
    {
        $channelPrice = $channel
            ? $this->variantChannels()
                ->where('channels_id', $channel->getId())
                ->value('price')
            : null;

        if ($channelPrice !== null) {
            return (float) $channelPrice;
        }

        return (float) $this->variantWarehouses()
            ->where('warehouses_id', $warehouse->getId())
            ->value('price') ?? 0.0;
    }

    public function updateQuantityInWarehouse(Warehouses $warehouse, float $quantity): void
    {
        $warehouseInfo = $this->variantWarehouses()->where('warehouses_id', $warehouse->getId())->first();

        if ($warehouseInfo) {
            $warehouseInfo->quantity = $quantity;
            $warehouseInfo->saveOrFail();
        }
    }

    public function reduceQuantityInWarehouse(Warehouses $warehouse, float $quantity): void
    {
        $warehouseInfo = $this->variantWarehouses()->where('warehouses_id', $warehouse->getId())->first();

        if ($warehouseInfo) {
            $warehouseInfo->quantity -= $quantity;
            $warehouseInfo->saveOrFail();
        }
    }

    public function updatePriceInWarehouse(Warehouses $warehouse, float $price): void
    {
        $warehouseInfo = $this->variantWarehouses()->where('warehouses_id', $warehouse->getId())->first();

        if ($warehouseInfo) {
            $warehouseInfo->price = $price;
            $warehouseInfo->saveOrFail();
        }
    }

    public function updatePriceInChannel(Channels $channel, float $price): void
    {
        $channelInfo = $this->variantChannels()->where('channels_id', $channel->getId())->first();

        if ($channelInfo) {
            $channelInfo->price = $price;
            $channelInfo->saveOrFail();
        }
    }

    /**
     * Set the total amount of variants in all the warehouses.
     */
    public function setTotalQuantity(): int
    {
        $total = $this->variantWarehouses()
                ->where('is_deleted', 0)
                ->sum('quantity');

        $this->set(
            'total_variant_quantity',
            $total
        );

        return (int) $total;
    }

    public static function getBySku(string $sku, CompanyInterface $company, AppInterface $app): self
    {
        return self::fromApp($app)
            ->fromCompany($company)
            ->where('sku', $sku)
            ->firstOrFail();
    }

    /**
     * The Typesense schema to be created for the Variants model.
     */
    public function typesenseCollectionSchema(): array
    {
        return [
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
                    'name' => 'products_id',
                    'type' => 'int64',
                ],
                [
                    'name' => 'name',
                    'type' => 'string',
                    'sort' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'files',
                    'type' => 'object[]',
                ],
                [
                    'name' => 'company',
                    'type' => 'object',
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
                    'name' => 'sku',
                    'type' => 'string',
                    'facet' => true,
                ],
                [
                    'name' => 'status',
                    'type' => 'object',
                    'optional' => true,
                ],
                [
                    'name' => 'warehouses',
                    'type' => 'object[]',
                    'optional' => true,
                ],
                [
                    'name' => 'channels',
                    'type' => 'object[]',
                    'optional' => true,
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
                ],
                [
                    'name' => 'apps_id',
                    'type' => 'int64',
                ],
                [
                    'name' => 'weight',
                    'type' => 'float',
                    'optional' => true,
                    'sort' => true,
                ],
                [
                    'name' => 'created_at',
                    'type' => 'int64',
                ],
            ],
            'default_sorting_field' => 'created_at',
            'enable_nested_fields' => true,  // Enable nested fields support for complex objects
        ];
    }
}
