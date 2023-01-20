<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Models;

use Baka\Traits\HasCompositePrimaryKeyTrait;
use Kanvas\Inventory\Models\BaseModel;

/**
 * Class Variants Price History.
 *
 * @property int $products_variants_id
 * @property int $warehouses_id
 * @property int $channels_id
 * @property int $price
 * @property string $from_date
 * @property string $created_at
 * @property bool $is_deleted
 */
class VariantsWarehousePriceHistory extends BaseModel
{
    use HasCompositePrimaryKeyTrait;

    protected $table = 'products_variants_warehouses_price_history';
    protected $guarded = [
        'products_variants_id',
        'warehouses_id',
        'channels_id',
        'price',
        'from_date'
    ];

    protected $primaryKey = ['products_variants_id', 'warehouses_id'];
}
