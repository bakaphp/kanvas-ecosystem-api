<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Users\Models\Users;
use Override;

/**
 * Class Variants Price History.
 *
 * @property int $product_variants_warehouse_id
 * @property float $price
 * @property int $users_id
 * @property string $from_date
 * @property string $created_at
 * @property bool $is_deleted
 */
class VariantsWarehousesPriceHistory extends BaseModel
{
    use NoAppRelationshipTrait;
    use NoCompanyRelationshipTrait;

    protected $table = 'products_variants_warehouses_price_history';
    protected $primaryKey = 'product_variants_warehouse_id';
    public $timestamps = false;
    protected $guarded = [];
    protected $forceDeleting = true;

    #[Override]
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    public function getPriceAttribute(string|float $value): float
    {
        return (float) $value;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }
}
