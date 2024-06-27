<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Models;

use Awobaz\Compoships\Compoships;
use Baka\Enums\StateEnums;
use Baka\Support\Str;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Dyrynda\Database\Support\CascadeSoftDeletes;
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
use Kanvas\Inventory\Status\Models\Status;
use Kanvas\Inventory\Variants\Actions\AddAttributeAction;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Social\Interactions\Traits\SocialInteractionsTrait;
use Laravel\Scout\Searchable;

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
 * @property string ean
 * @property string barcode
 * @property string serial_number
 */
class Variants extends BaseModel
{
    use SlugTrait;
    use UuidTrait;
    use SocialInteractionsTrait;
    use HasShopifyCustomField;
    use Searchable {
        search as public traitSearch;
    }

    use CascadeSoftDeletes;
    use Compoships;

    protected $is_deleted;
    protected $cascadeDeletes = ['variantChannels', 'variantWarehouses', 'variantAttributes'];

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
        'apps_id',
    ];
    protected $guarded = [];
    protected static ?string $overWriteSearchIndex = null;

    public static function searchableIndex(): string
    {
        return AppEnums::PRODUCT_VARIANTS_SEARCH_INDEX->getValue();
    }

    public function shouldBeSearchable(): bool
    {
        return $this->isPublished() && $this->product;
    }

    public function isPublished(): bool
    {
        return (int) $this->is_deleted === 0;
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
        return $this->hasMany(VariantsChannels::class, 'products_variants_id')->where('is_published', 1);
    }

    public function variantAttributes(): HasMany
    {
        return $this->hasMany(VariantsAttributes::class, 'products_variants_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status_id');
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
    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(
            Attributes::class,
            VariantsAttributes::class,
            'products_variants_id',
            'attributes_id'
        )
            ->withPivot('value');
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
            ->withPivot('price', 'discounted_price', 'is_published', 'warehouses_id', 'channels_id');
    }

    /**
     * @psalm-suppress MixedInferredReturnType
     */
    public function getPriceInfoFromDefaultChannel(): Channels
    {
        //@todo add is_default to channels
        $channel = Channels::where('slug', 'default')
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
            if (empty($attribute['value'])) {
                continue;
            }

            if (isset($attribute['id'])) {
                $attributeModel = Attributes::getById((int) $attribute['id'], $this->app);
            } else {
                $attributesDto = AttributesDto::from([
                    'app' => app(Apps::class),
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
                        'id' => $variantWarehouses->status->getId(),
                        'name' => $variantWarehouses->status->name,
                    ],
                ];
            }),
            'channels' => $this->channels->map(function ($channels) {
                return [
                    'id' => $channels->getId(),
                    'name' => $channels->name,
                    'price' => $channels->price,
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

    public function searchableAs(): string
    {
        return config('scout.prefix') . ($this->app->get('app_custom_product_variant_index') ?? 'product_variant_index');
    }

    public static function search($query = '', $callback = null)
    {
        $query = self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
        if (! auth()->user()->isAppOwner()) {
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
}
