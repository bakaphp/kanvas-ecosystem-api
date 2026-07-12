<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Purchasing\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Models\BaseModel;

/**
 * A read-mirror of a source-ERP purchase order, for AP-bill matching. Not a posted accounting
 * document — reference data the agent queries to match an invoice to its PO and inherit line coding.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
 * @property string $order_type
 * @property string $order_number
 * @property int|null $vendor_organization_id
 * @property string|null $vendor_code
 * @property string|null $status
 * @property Carbon|null $order_date
 * @property string|null $currency
 * @property string $order_total
 * @property string $source
 * @property string|null $external_id
 * @property Carbon|null $last_synced_at
 * @property array|null $metadata
 * @property bool $is_deleted
 */
class PurchaseOrder extends BaseModel
{
    use UuidTrait;

    protected $table = 'purchase_orders';
    protected $guarded = [];

    protected $casts = [
        'order_date' => 'date',
        'order_total' => 'decimal:4',
        'is_deleted' => 'boolean',
        'last_synced_at' => 'datetime',
        'metadata' => Json::class,
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class, 'purchase_order_id', 'id');
    }
}
