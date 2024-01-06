<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Channels\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Kanvas\Inventory\Models\BaseModel;

/**
 * Class Variants Price Channel History.
 *
 * @property int $channel_id
 * @property int $products_variants_id
 * @property int $product_variants_warehouse_id
 * @property int $price
 * @property string $from_date
 * @property string $created_at
 * @property bool $is_deleted
 */
class VariantChannelPriceHistory extends BaseModel
{
    use NoAppRelationshipTrait;
    use NoCompanyRelationshipTrait;

    protected $table = 'products_variants_warehouse_channel_price_history';
    public $timestamps = false;
    protected $guarded = [];
}
