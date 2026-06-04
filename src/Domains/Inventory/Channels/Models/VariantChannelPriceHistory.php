<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Channels\Models;

use Awobaz\Compoships\Compoships;
use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Users\Models\Users;

/**
 * Class Variants Price Channel History.
 *
 * @property int $channel_id
 * @property int $products_variants_id
 * @property int $product_variants_warehouse_id
 * @property float $price
 * @property int $users_id
 * @property string $from_date
 * @property string $created_at
 * @property bool $is_deleted
 */
class VariantChannelPriceHistory extends BaseModel
{
    // Required because Kanvas\Inventory\Variants\Models\VariantsChannels::pricesHistory()
    // declares a multi-column HasMany on ['product_variants_warehouse_id', 'channels_id'].
    // Awobaz\Compoships rejects multi-column relations whose target model does not
    // also use this trait (Awobaz\Compoships\HasRelationships::validateRelatedModel).
    use Compoships;
    use NoAppRelationshipTrait;
    use NoCompanyRelationshipTrait;

    protected $table = 'products_variants_warehouse_channel_price_history';
    public $timestamps = false;
    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }
}
