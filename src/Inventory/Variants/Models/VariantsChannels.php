<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Models;

use Baka\Traits\HasCompositePrimaryKeyTrait;
use Kanvas\Inventory\Models\BaseModel;

/**
 * Class Variants Channels.
 *
 * @property int $products_variants_id
 * @property int $warehouses_id
 * @property int $channels_id
 * @property float $price
 * @property float $discount_price
 * @property string $created_at
 * @property string $updated_at
 * @property bool $is_deleted
 */
class VariantsChannels extends BaseModel
{
    use HasCompositePrimaryKeyTrait;

    protected $table = 'products_variants_channels';
    protected $guarded = [
        'products_variants_id',
        'channels_id',
        'warehouses_id',
        'price',
        'discount_price'
    ];


    protected $primaryKey = ['products_variants_id', 'channels_id', 'warehouses_id'];
}
