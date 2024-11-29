<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Models;

use Awobaz\Compoships\Compoships;
use Baka\Support\Str;
use Baka\Traits\HasLightHouseCache;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Shopify\Traits\HasShopifyCustomField;
use Kanvas\Inventory\Attributes\Actions\CreateAttribute;
use Kanvas\Inventory\Attributes\DataTransferObject\Attributes as AttributesDto;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Products\Actions\AddAttributeAction;
use Kanvas\Inventory\Products\Factories\ProductFactory;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\ProductsTypes\Services\ProductTypeService;
use Kanvas\Inventory\Status\Models\Status;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Services\VariantService;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Social\Interactions\Traits\LikableTrait;
use Kanvas\Social\Tags\Traits\HasTagsTrait;
use Kanvas\Workflow\Contracts\EntityIntegrationInterface;
use Kanvas\Workflow\Traits\CanUseWorkflow;
use Kanvas\Workflow\Traits\IntegrationEntityTrait;
use Laravel\Scout\Searchable;

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
    use Searchable {
        search as public traitSearch;
    }

    use CascadeSoftDeletes;
    use Compoships;
    use CanUseWorkflow;

    protected $table = 'products';
    protected $guarded = [];
    protected $cascadeDeletes = ['variants'];

    protected $casts = [
        'is_published' => 'boolean',
    ];

    protected $is_deleted;

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

    public function getAttributeByName(string $name): ?Attributes
    {
        return $this->attributes()
            ->where('attributes.name', $name)
            ->first();
    }

    /**
     * attributes.
     */
    public function attributes(): BelongsToMany
    {
        return $this->buildAttributesQuery();
    }

    /**
     * @todo add integration and graph test
     */
    public function visibleAttributes(): BelongsToMany
    {
        return $this->buildAttributesQuery(['is_visible' => true]);
    }

    public function searchableAttributes(): BelongsToMany
    {
        return $this->buildAttributesQuery(['is_searchable' => true]);
    }

    private function buildAttributesQuery(array $conditions = []): BelongsToMany
    {
        $query = $this->belongsToMany(
            Attributes::class,
            'products_attributes',
            'products_id',
            'attributes_id'
        )->withPivot('value');

        foreach ($conditions as $column => $value) {
            $query->where($column, $value);
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

    public function scopeOrderByVariantAttribute(Builder $query, string $name, string $sort = 'asc'): Builder
    {
        $allowedSorts = ['ASC', 'DESC'];
        $sort = strtoupper($sort);

        if (! in_array($sort, $allowedSorts)) {
            throw new InvalidArgumentException('Valor de orden inválido.');
        }

        return $query->join('products_variants', 'products_variants.products_id', '=', 'products.id')
            ->join('products_variants_attributes as pva', 'pva.products_variants_id', '=', 'products_variants.id')
            ->leftJoin('attributes as a', function ($join) use ($name) {
                $join->on('a.id', '=', 'pva.attributes_id')
                    ->where('a.name', '=', $name);
            })
            ->orderByRaw(
                "CASE WHEN a.name = ? THEN CAST(pva.value AS DECIMAL(10,2)) ELSE 0 END {$sort}, products.id ASC",
                [$name]
            )
            ->select('products.*');
    }

    /**
     * variants.
     */
    public function variants(): HasMany
    {
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
        return $this->productsType();
    }

    public function productsType(): BelongsTo
    {
        return $this->belongsTo(ProductsTypes::class, 'products_types_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status_id');
    }

    public function shouldBeSearchable(): bool
    {
        return $this->isPublished();
    }

    public function toSearchableArray(): array
    {
        $product = [
            'objectID' => $this->uuid,
            'id' => $this->id,
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
                  ];
            }),
            'variants' => $this->variants->take(15)->map(function ($variant) {
                return $variant->toSearchableArray();
            }),
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
        $attributes = $this->searchableAttributes()->get();
        foreach ($attributes as $attribute) {
            $product['attributes'][$attribute->name] = $attribute->value;
        }

        $customFields = $this->getAllCustomFields();
        foreach ($customFields as $key => $value) {
            $product['custom_fields'][$key] = $value;
        }

        return $product;
    }

    public function searchableAs(): string
    {
        $product = ! $this->searchableDeleteRecord() ? $this : $this->withTrashed()->find($this->id);
        $customIndex = isset($product->app) ? $product->app->get('app_custom_product_index') : null;

        return config('scout.prefix') . ($customIndex ?? 'product_index');
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
            if (! isset($attribute['value'])) {
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

                if ($this?->productsType) {
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
}
