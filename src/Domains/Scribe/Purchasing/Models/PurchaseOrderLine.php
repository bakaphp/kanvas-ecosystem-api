<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Purchasing\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A purchase-order line. Replaced wholesale when the parent PO is re-synced, so it stays a lean
 * Eloquent model (no soft-delete / custom-fields ceremony). `expense_account_id` + `subaccount_id`
 * carry the PO's GL coding when it has one (expense/service POs; null on inventory POs).
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property int $line_number
 * @property string|null $sku
 * @property string|null $description
 * @property int|null $inventory_variant_id
 * @property int|null $expense_account_id
 * @property int|null $subaccount_id
 * @property string $order_qty
 * @property string $open_qty
 * @property string $received_qty
 * @property string $unit_cost
 * @property string $ext_cost
 * @property array|null $metadata
 */
class PurchaseOrderLine extends EloquentModel
{
    protected $connection = 'accounting';
    protected $table = 'purchase_order_lines';
    protected $guarded = [];

    protected $casts = [
        'order_qty' => 'decimal:6',
        'open_qty' => 'decimal:6',
        'received_qty' => 'decimal:6',
        'unit_cost' => 'decimal:6',
        'ext_cost' => 'decimal:4',
        'metadata' => Json::class,
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id', 'id');
    }
}
