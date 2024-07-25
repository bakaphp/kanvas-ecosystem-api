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
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Variants\Models\VariantsAttributes;

/**
 * Class Attributes.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string uuid
 * @property string $name
 */
class Attributes extends BaseModel
{
    use UuidTrait;
    use SlugTrait;
    use CascadeSoftDeletes;
    use DatabaseSearchableTrait;

    public $table = 'attributes';
    public $guarded = [];
    protected $cascadeDeletes = ['variantAttributes', 'defaultValues'];

    /**
     * apps.
     */
    public function apps(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }

    /**
     * apps.
     */
    public function attributeType(): BelongsTo
    {
        return $this->belongsTo(AttributesTypes::class, 'attributes_type_id');
    }

    public function variantAttributes(): HasMany
    {
        return $this->hasMany(VariantsAttributes::class, 'attributes_id');
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
}
