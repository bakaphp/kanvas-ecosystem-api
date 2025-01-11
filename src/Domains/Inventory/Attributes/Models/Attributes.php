<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Attributes\Models;

use Baka\Support\Str;
use Baka\Traits\DatabaseSearchableTrait;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Attributes\Actions\AddAttributeValue;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Products\Models\ProductsAttributes;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypesAttributes;
use Kanvas\Inventory\Variants\Models\VariantsAttributes;

/**
 * Class Attributes.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string uuid
 * @property string $name
 * @property int $is_filterable
 * @property int $is_searchable
 * @property int $is_visible
 * @property int $weight
 */
class Attributes extends BaseModel
{
    use UuidTrait;
    use SlugTrait;
    use CascadeSoftDeletes;
    use DatabaseSearchableTrait;

    public $table = 'attributes';
    public $guarded = [];
    protected $cascadeDeletes = ['variantAttributes','defaultValues'];

    public function apps(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }

    public function attributeType(): BelongsTo
    {
        return $this->belongsTo(AttributesTypes::class, 'attributes_type_id');
    }

    public function variantAttributes(): HasMany
    {
        return $this->hasMany(VariantsAttributes::class, 'attributes_id');
    }

    public function productsAttributes(): HasMany
    {
        return $this->hasMany(ProductsAttributes::class, 'attributes_id');
    }

    public function productsTypesAttributes(): HasMany
    {
        return $this->hasMany(ProductsTypesAttributes::class, 'attributes_id');
    }

    /**
     * attributes values from pivot
     */
    public function value(): Attribute
    {
        return Attribute::make(
            get: fn () => Str::isJson($this->pivot->value) ? json_decode($this->pivot->value, true) : $this->pivot->value,
        );
    }

    /**
     * Attributes can have a default list of values , so we can generate dropdown list
     */
    public function defaultValues(): HasMany
    {
        return $this->hasMany(AttributesValues::class, 'attributes_id');
    }

    public function hasDependencies(): bool
    {
        return $this->productsAttributes()->exists()
        || $this->variantAttributes()->exists()
        || $this->productsTypesAttributes()->exists();
    }

    public function addValues(array $values): void
    {
        $valueObjects = array_map(
            fn ($value) => ['value' => $value],
            $values
        );

        (new AddAttributeValue($this, $valueObjects))->execute();
    }

    public function addValue(mixed $value): void
    {
        $this->addValues([$value]);
    }
}
