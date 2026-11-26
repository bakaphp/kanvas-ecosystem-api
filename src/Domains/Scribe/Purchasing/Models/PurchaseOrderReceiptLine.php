<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Purchasing\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The quantity of one order line that arrived on a receipt.
 *
 * @property int $id
 * @property int $purchase_order_receipt_id
 * @property int $purchase_order_line_id
 * @property string $quantity
 */
class PurchaseOrderReceiptLine extends EloquentModel
{
    public $timestamps = false;

    protected $connection = 'accounting';
    protected $table = 'purchase_order_receipt_lines';
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:6',
    ];

    public function line(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id', 'id');
    }
}
