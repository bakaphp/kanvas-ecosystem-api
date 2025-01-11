<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Models;

use Baka\Casts\Json;
use Baka\Traits\HasCompositePrimaryKeyTrait;
use Kanvas\Inventory\Models\BaseModel;

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

    protected $table = 'products_variants_attributes';
    protected $guarded = [
        'products_variants_id',
        'attributes_id'
    ];

    protected $primaryKey = ['products_variants_id', 'attributes_id'];

    protected $casts = [
        'value' => Json::class
    ];
}
