<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The bill of lading of a sales order, and what follows from it: the invoice due date, the documents package
 * and the delivery.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $sales_order_id
 * @property string $bl_number
 * @property Carbon $bl_date
 * @property string|null $vessel
 * @property array|null $containers
 * @property int $payment_terms_days
 * @property Carbon $due_date
 * @property Carbon|null $documents_package_due_on
 * @property Carbon|null $documents_package_sent_at
 * @property Carbon|null $delivered_on
 * @property string|null $pod_reference
 * @property string|null $delivery_discrepancies
 * @property bool $damage_reported
 * @property Carbon|null $delivery_recorded_at
 * @property Carbon $recorded_at
 * @property int|null $users_id
 */
class SalesOrderShipment extends EloquentModel
{
    protected $connection = 'accounting';
    protected $table = 'sales_order_shipments';
    protected $guarded = [];

    protected $casts = [
        'bl_date' => 'date',
        'containers' => Json::class,
        'due_date' => 'date',
        'documents_package_due_on' => 'date',
        'documents_package_sent_at' => 'datetime',
        'delivered_on' => 'date',
        'damage_reported' => 'boolean',
        'delivery_recorded_at' => 'datetime',
        'recorded_at' => 'datetime',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id', 'id');
    }
}
