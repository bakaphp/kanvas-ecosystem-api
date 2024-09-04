<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Models;

use Awobaz\Compoships\Compoships;
use Baka\Search\DeleteInAlgoliaSearchJob;
use Baka\Support\Str;
use Baka\Traits\HasLightHouseCache;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Shopify\Traits\HasShopifyCustomField;
use Kanvas\Inventory\Attributes\Actions\CreateAttribute;
use Kanvas\Inventory\Attributes\DataTransferObject\Attributes as AttributesDto;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Products\Actions\AddAttributeAction;
use Kanvas\Inventory\Products\Factories\ProductFactory;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\Status\Models\Status;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Services\VariantService;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Social\Interactions\Traits\LikableTrait;
use Kanvas\Social\Tags\Traits\HasTagsTrait;
use Laravel\Scout\Searchable;

/**
 * Class Products.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $products_types_id
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
class Products extends BaseModel
{
    use UuidTrait;
    use SlugTrait;
    use LikableTrait;
    use HasShopifyCustomField;
    use HasTagsTrait;
    use HasLightHouseCache;
    use Searchable {
        search as public traitSearch;
    }

    use CascadeSoftDeletes;
    use Compoships;

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

    /**
     * attributes.
     */
    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(
            Attributes::class,
            'products_attributes',
            'products_id',
            'attributes_id'
        )->withPivot('value');
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
     */
    public function productsTypes(): BelongsTo
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
        $this->load([
            'company',              // Load the company relationship
            'company.user',         // Load the user through the company
            'categories',           // Load categories
            'variants',             // Load variants
            'status',               // Load status
            'files',                // Load files (if it's a relationship)
            'attributes',           // Load attributes
        ]);

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
                'firstname' => $this?->company?->user?->firstname,
                'lastname' => $this?->company?->user?->lastname,
            ],
            'categories' => $this->categories->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                  ];
            }),
            'variants' => $this->variants->map(function ($variant) {
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
        $attributes = $this->attributes()->get();
        foreach ($attributes as $attribute) {
            $product['attributes'][$attribute->name] = $attribute->value;
        }

        return $product;
    }

    public function searchableAs(): string
    {
        $product = ! $this->searchableDeleteRecord() ? $this : $this->find($this->id);
        $customIndex = isset($product->app) ? $product->app->get('app_custom_product_index') : null;

        logger('searchableAs', ['customIndex' => $customIndex, 'searchble' => (int) $this->shouldBeSearchable(), 'rpooduct' => $this->toArray()]);

        return config('scout.prefix') . ($customIndex ?? 'product_index');
    }

    public function searchableSoftDelete(): void
    {
        DeleteInAlgoliaSearchJob::dispatch($this);
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

    /**
     * Add/create new attributes from a product.
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedArrayAccess
     * @psalm-suppress MixedPropertyFetch
     */
    public function addAttributes(UserInterface $user, array $attributes): void
    {
        foreach ($attributes as $attribute) {
            if (empty($attribute['value'])) {
                continue;
            }

            if (isset($attribute['id'])) {
                $attributeModel = Attributes::getById((int) $attribute['id'], $this->app);
            } else {
                $attributesDto = AttributesDto::from([
                    'app' => $this->app,
                    'user' => $user,
                    'company' => $this->product->company,
                    'name' => $attribute['name'],
                    'value' => $attribute['value'],
                    'isVisible' => false,
                    'isSearchable' => false,
                    'isFiltrable' => false,
                    'slug' => Str::slug($attribute['name']),
                ]);
                $attributeModel = (new CreateAttribute($attributesDto, $user))->execute();
            }

            (new AddAttributeAction($this, $attributeModel, $attribute['value']))->execute();
        }
    }
}
