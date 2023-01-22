<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Models;

use Baka\Traits\HasCompositePrimaryKeyTrait;
use Kanvas\Inventory\Models\BaseModel;

/**
 * Class Variants Warehouse.
 *
 * @property int $products_variants_id
 * @property int $warehouses_id
 * @property int $quantity
 * @property float $price
 * @property string $sku
 * @property int $position
 * @property string $serial_number
 * @property int $is_default
 * @property int $is_oversellable
 * @property int $is_default
 * @property int $is_best_seller
 * @property int $is_on_sale
 * @property int $is_on_promo
 * @property int $can_pre_order
 * @property int $is_coming_soon
 * @property int $is_new
 * @property int $is_published
 * @property string $created_at
 * @property string $updated_at
 * @property bool $is_deleted
 */
class VariantsWarehouses extends BaseModel
{
    use HasCompositePrimaryKeyTrait;

    protected $table = 'products_variants_warehouses';
    protected $guarded = [
        'products_variants_id',
        'warehouses_id',
        'quantity',
        'price',
        'sku',
        'position',
        'serial_number',
        'is_default',
        'is_oversellable',
        'is_default',
        'is_best_seller',
        'is_on_sale',
        'is_on_promo',
        'can_pre_order',
        'is_coming_soon',
        'is_new',
        'is_published'
    ];


    protected $primaryKey = ['products_variants_id', 'warehouses_id'];
}
