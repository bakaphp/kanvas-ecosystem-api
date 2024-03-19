<?php

declare(strict_types=1);

namespace Kanvas\Inventory\ProductsTypes\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Models\BaseModel;

/**
 * Class ProductsTypesAttributes.
 *
 * @property int $id
 * @property int $products_types_id
 * @property int $attributes_id
 * @property bool $toVariant
 */
class ProductsTypesAttributes extends BaseModel
{
    use NoAppRelationshipTrait;
    use NoCompanyRelationshipTrait;

    protected $table = 'products_types_attributes';

    protected $guarded = [];

    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductsTypes::class, 'products_types_id');
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attributes::class, 'attributes_id');
    }
}
