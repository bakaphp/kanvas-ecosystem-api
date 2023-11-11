<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Models;

use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Attributes\Actions\CreateAttribute;
use Kanvas\Inventory\Attributes\DataTransferObject\Attributes as AttributesDto;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Enums\AppEnums;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Status\Models\Status;
use Kanvas\Inventory\Variants\Actions\AddAttributeAction;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Social\Interactions\Traits\SocialInteractionsTrait;
use Kanvas\Traits\SearchableDynamicIndexTrait;

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
    use SearchableDynamicIndexTrait;
    use CascadeSoftDeletes;

    protected $is_deleted;
    protected $cascadeDeletes = ['variantWarehouses'];

    protected $table = 'products_variants';
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
    ];
    protected $guarded = [];
    protected static ?string $overWriteSearchIndex = null;

    public static function searchableIndex(): string
    {
        return AppEnums::PRODUCT_VARIANTS_SEARCH_INDEX->getValue();
    }

    public function shouldBeSearchable(): bool
    {
        return $this->isPublished();
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
     * Add/create new attributes from a variant.
     */
    public function addAttributes(UserInterface $user, array $attributes): void
    {
        foreach ($attributes as $attribute) {
            if (empty($attribute['value'])) {
                continue;
            }

            $attributesDto = AttributesDto::from([
                'app' => app(Apps::class),
                'user' => $user,
                'company' => $this->product->companies,
                'name' => $attribute['name'],
                'value' => $attribute['value'],
            ]);

            $attributeModel = (new CreateAttribute($attributesDto, $user))->execute();
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
}
