<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Attributes\Models;

use Baka\Traits\DatabaseSearchableTrait;
use Baka\Traits\UuidTrait;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Attributes\Models\Attributes as ModelsAttributes;
use Kanvas\Inventory\Models\BaseModel;

/**
 * Class Attributes.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string uuid
 * @property string $name
 */
class AttributesTypes extends BaseModel
{
    public $table = 'attributes_types_input';
    public $guarded = [];

    /**
     * apps.
     */
    public function apps(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id');
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(ModelsAttributes::class, 'attributes_id');
    }
}
