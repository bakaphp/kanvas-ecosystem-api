<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Purchasing\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Models\BaseModel;

/**
 * What arrived against a purchase order on one occasion.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
 * @property int $purchase_order_id
 * @property string $receipt_number
 * @property Carbon $received_on
 * @property string|null $reference
 * @property string|null $notes
 * @property int|null $users_id
 * @property bool $is_deleted
 */
class PurchaseOrderReceipt extends BaseModel
{
    use UuidTrait;

    protected $table = 'purchase_order_receipts';
    protected $guarded = [];

    protected $casts = [
        'received_on' => 'date',
        'is_deleted' => 'boolean',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderReceiptLine::class, 'purchase_order_receipt_id', 'id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id', 'id');
    }
}
