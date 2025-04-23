<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Attributes\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Languages\Traits\HasTranslationsDefaultFallback;

/**
 * Class Attributes.
 *
 * @property int   $id
 * @property int   $attributes_id
 * @property mixed $value
 */
class AttributesValues extends BaseModel
{
    use NoAppRelationshipTrait;
    use NoCompanyRelationshipTrait;
    use HasTranslationsDefaultFallback;

    public $table = 'attributes_values';
    public $guarded = [];
    public $translatable = ['value'];

    /**
     * attribute.
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attributes::class, 'attributes_id');
    }
}
