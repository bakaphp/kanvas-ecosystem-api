<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Attributes\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Inventory\Models\BaseModel;

/**
 * Class Attributes.
 *
 * @property int $id
 * @property int $attributes_id
 * @property mixed $value
 */
class AttributesValues extends BaseModel
{
    use NoAppRelationshipTrait;
    use NoCompanyRelationshipTrait;

    public $table = 'attributes_values';
    public $guarded = [];

    /**
     * attribute.
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attributes::class, 'attributes_id');
    }
}
