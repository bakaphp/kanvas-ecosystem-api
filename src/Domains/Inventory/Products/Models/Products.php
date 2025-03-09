<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Models;

use Awobaz\Compoships\Compoships;
use Baka\Support\Str;
use Baka\Traits\DynamicSearchableTrait;
use Baka\Traits\HasLightHouseCache;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Exception;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Shopify\Traits\HasShopifyCustomField;
use Kanvas\Inventory\Attributes\Actions\CreateAttribute;
use Kanvas\Inventory\Attributes\DataTransferObject\Attributes as AttributesDto;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Products\Actions\AddAttributeAction;
use Kanvas\Inventory\Products\Builders\ProductSortAttributeBuilder;
use Kanvas\Inventory\Products\Factories\ProductFactory;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\ProductsTypes\Services\ProductTypeService;
use Kanvas\Inventory\Status\Models\Status;
use Kanvas\Inventory\Variants\Enums\ConfigurationEnum;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Services\VariantService;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Languages\Traits\HasTranslationsDefaultFallback;
use Kanvas\Social\Interactions\Traits\LikableTrait;
use Kanvas\Social\Tags\Traits\HasTagsTrait;
use Kanvas\Social\UsersRatings\Traits\HasRating;
use Kanvas\Souk\Enums\ConfigurationEnum as EnumsConfigurationEnum;
use Kanvas\Workflow\Contracts\EntityIntegrationInterface;
use Kanvas\Workflow\Traits\CanUseWorkflow;
use Kanvas\Workflow\Traits\IntegrationEntityTrait;
use Override;

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
 * @property bool $is_published
 * @property string $published_at
 * @property bool $is_deleted
 */
class Products extends BaseModel implements EntityIntegrationInterface
{
    use UuidTrait;
    use SlugTrait;
    use LikableTrait;
    use HasShopifyCustomField;
    use HasTagsTrait;
    use IntegrationEntityTrait;
    use HasLightHouseCache;
    use DynamicSearchableTrait {
        search as public traitSearch;
    }

    use CascadeSoftDeletes;
    use Compoships;
    use CanUseWorkflow;
    use HasRating;
    use HasTranslationsDefaultFallback;

    protected $table = 'products';
    protected $guarded = [];
    protected $cascadeDeletes = ['variants'];

    protected $casts = [
        'is_published' => 'boolean',
    ];

    protected $is_deleted;

    public $translatable = ['name','description','short_description','html_description','warranty_terms'];

    #[Override]
    public function getGraphTypeName(): string
    {
        return 'Product';
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
        );
    }

    public function getAttributeByName(string $name, ?string $locale = null): ?ProductsAttributes
    {
        $locale = $locale ?? app()->getLocale(); // Use app locale if not passed.

        return $this->buildAttributesQuery(["name->{$locale}" => $name])->first();
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
     * @todo add integration and graph test
     */
    public function visibleAttributes(): array
    {
        return $this->mapAttributes(
            $this->buildAttributesQuery(['is_visible' => true])->get()
        );
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
            ->select('products_attributes.*', 'attributes.*');

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

    public function scopeFilterByVariantAttributeValue(Builder $query, string $value): Builder
    {
        return $query->where('products.is_deleted', 0)
            ->whereHas('variants', function (Builder $query) use ($value) {
                $query->where('products_variants.is_deleted', 0)
                    ->whereHas('attributes', function (Builder $query) use ($value) {
                        $query->where('products_variants_attributes.value', $value)
                              ->where('products_variants_attributes.is_deleted', 0);
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

    public function productsType(): BelongsTo
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
        return $this->isPublished();
    }

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
                    'attributes' => $files->attributes,
                ];
            }),
            'company' => [
                'id' => $this->companies_id,
                'name' => $this->company->name,
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
            'attributes' => [],
            'apps_id' => $this->apps_id,
            'published_at' => $this->published_at,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];

        if ($this->isTypesense()) {
            $product['created_at'] = $this->created_at->timestamp;
            $product['custom_fields'] = [];

            if ($this->app->get(EnumsConfigurationEnum::B2B_GLOBAL_COMPANY->value)) {
                // Initialize prices array
                $product['prices'] = [];

                // Loop through each variant
                $this->variants->each(function ($variant) use (&$product) {
                    // Each variant has its own channels, so get them
                    if ($variant->channels && $variant->channels->count() > 0) {
                        $variant->channels->each(function ($channel) use (&$product) {
                            // Get company by slug
                            try {
                                $company = Companies::getByUuid($channel->slug);

                                if ($company) {
                                    // Add price to the prices array
                                    $product['prices']['price_b2b_' . $company->getId()] = (float) $channel->price;
                                }
                            } catch (Exception $e) {
                                // Do nothing
                            }
                        });
                    }
                });
            }
        }

        $attributes = $this->searchableAttributes();
        foreach ($attributes as $attribute) {
            $product['attributes'][$attribute['name']] = $attribute['value'];
        }

        $customFields = $this->getAllCustomFields();
        foreach ($customFields as $key => $value) {
            $product['custom_fields'][$key] = $value;
        }

        return $product;
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
        $query = self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
        $user = auth()->user();

        if ($user instanceof UserInterface && ! auth()->user()->isAppOwner()) {
            $query->where('company.id', auth()->user()->getCurrentCompany()->getId());
        }

        if ($query->model->isTypesense()) {
            $query->options([
                'query_by' => 'name, description', // Use just 'message' instead of 'message.name'
            ]);
        }

        return $query;
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
    public static function newFactory()
    {
        return new ProductFactory();
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
        foreach ($attributes as $attribute) {
            if (! isset($attribute['value']) || $attribute['name'] === null) {
                continue; // Skip attributes without a value
            }

            $attributeModel = null;

            if (isset($attribute['id'])) {
                $attributeModel = Attributes::getById((int) $attribute['id'], $this->app);
            } else {
                $attributesDto = AttributesDto::from([
                    'app' => $this->app,
                    'user' => $user,
                    'company' => $this->company,
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

                if ($this?->productsType !== null) {
                    ProductTypeService::addAttributes(
                        $this->productsType,
                        $this->user,
                        [
                            [
                                'id' => $attributeModel->getId(),
                                'value' => $attribute['value'],
                            ],
                        ]
                    );
                }
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
        $this->save();
    }

    protected function getVariantsData(): Collection
    {
        $limit = $this->app->get(ConfigurationEnum::PRODUCT_VARIANTS_SEARCH_LIMIT->value) ?? 200;

        return $this->variants->count() > $limit
            ? $this->variants->take($limit)->map(fn ($variant) => $variant->toSearchableArraySummary())
            : $this->variants->map(fn ($variant) => $variant->toSearchableArray());
    }

    /**
    * The Typesense schema to be created.
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
                    'name' => 'user',
                    'type' => 'object',
                ],
                [
                    'name' => 'categories',
                    'type' => 'object[]',
                ],
                [
                    'name' => 'variants',
                    'type' => 'object[]', // Adjust based on what getVariantsData() returns
                ],
                [
                    'name' => 'status',
                    'type' => 'object',
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
                    'name' => 'prices',
                    'type' => 'object',
                    'optional' => true,
                ],
                [
                    'name' => 'prices.*',
                    'type' => 'float',
                    'optional' => true,
                    'sort' => true,
                ],
                [
                    'name' => 'prices.regular',
                    'type' => 'float',
                    'optional' => true,
                    'sort' => true,
                ],
                [
                    'name' => 'prices.sale',
                    'type' => 'float',
                    'optional' => true,
                    'sort' => true,
                ],
                [
                    'name' => 'prices.msrp',
                    'type' => 'float',
                    'optional' => true,
                    'sort' => true,
                ],
                [
                    'name' => 'apps_id',
                    'type' => 'int64',
                ],
                [
                    'name' => 'published_at',
                    'type' => 'string',
                    'optional' => true,
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
