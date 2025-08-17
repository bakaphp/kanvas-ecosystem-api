<?php

declare(strict_types=1);

namespace Kanvas\Souk\Discounts\Models;

use Baka\Traits\NoCompanyRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Souk\Models\BaseModel;
use Kanvas\Souk\Orders\Models\Order;

/**
 * Class OrderDiscount
 *
 * @property int $id
 * @property int $apps_id
 * @property int $order_id
 * @property int $discount_id
 * @property float $amount
 * @property bool $is_deleted
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class OrderDiscount extends BaseModel
{
    use NoCompanyRelationshipTrait;
    
    protected $table = 'order_discounts';
    protected $guarded = [];

    protected $casts = [
        'amount' => 'float',
        'is_deleted' => 'boolean',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class, 'discount_id');
    }
}
