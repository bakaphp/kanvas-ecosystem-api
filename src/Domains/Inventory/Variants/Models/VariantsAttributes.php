<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Models;

use Baka\Casts\Json;
use Baka\Traits\HasCompositePrimaryKeyTrait;
use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Models\BaseModel;
use Spatie\Translatable\HasTranslations;

/**
 * Class Variants Attributes.
 *
 * @property int $products_variants_id
 * @property int $attributes_id
 * @property string|null $value
 * @property string $created_at
 * @property string $updated_at
 * @property bool $is_deleted
 */
class VariantsAttributes extends BaseModel
{
    use HasCompositePrimaryKeyTrait;
    use HasTranslations;
    use NoAppRelationshipTrait;
    use NoCompanyRelationshipTrait;

    protected $table = 'products_variants_attributes';
    protected $guarded = [
        'products_variants_id',
        'attributes_id'
    ];

    protected $primaryKey = ['products_variants_id', 'attributes_id'];

    protected $casts = [
        'value' => Json::class
    ];

    public $translatable = ['value'];

        /**
     * Get the product.
     *
     * @return BelongsTo
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variants::class, 'products_variants_id');
    }

    /**
     * Get the attribute.
     *
     * @return BelongsTo
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attributes::class, 'attributes_id');
    }
}
