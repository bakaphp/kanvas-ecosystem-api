<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of a sales order, replaced as a whole when the order is edited.
 *
 * @property int $id
 * @property int $sales_order_id
 * @property int $line_number
 * @property string|null $sku
 * @property string $description
 * @property string $quantity
 * @property string $unit_price
 * @property string $line_total
 */
class SalesOrderLine extends EloquentModel
{
    public $timestamps = false;

    protected $connection = 'accounting';
    protected $table = 'sales_order_lines';
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:6',
        'unit_price' => 'decimal:6',
        'line_total' => 'decimal:4',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id', 'id');
    }
}
