<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Models;

use Baka\Casts\Json;
use Baka\Traits\HasCompositePrimaryKeyTrait;
use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Languages\Traits\HasTranslationsDefaultFallback;
use Override;
use Spatie\Translatable\HasTranslations;

/**
 * Class Products.
 *
 * @property int $products_id
 * @property int $attributes_id
 * @property ?string $value = null
 * @property string $created_at
 * @property string $updated_at
 * @property bool $is_deleted
 */
class ProductsAttributes extends BaseModel
{
    use HasCompositePrimaryKeyTrait;
    use HasTranslationsDefaultFallback;
    use NoAppRelationshipTrait;
    use NoCompanyRelationshipTrait;

    protected $table = 'products_attributes';
    protected $forceDeleting = true;
    protected $guarded = [
        'products_id',
        'attributes_id',
        'value',
    ];

    protected $primaryKey = ['products_id', 'attributes_id'];

    public $translatable = ['value'];

    /**
     * Get the product.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Products::class, 'products_id');
    }

    /**
     * Get the attribute.
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attributes::class, 'attributes_id');
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'value' => Json::class,
        ];
    }
}
