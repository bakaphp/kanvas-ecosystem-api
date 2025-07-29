<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Souk\Models\BaseModel;

class OrderTransitionHistory extends BaseModel
{
    protected $table = 'order_transitions_history';

    protected $fillable = [
        'apps_id',
        'companies_id',
        'transition_id',
        'order_id',
        'from_status_id',
        'to_status_id',
        'description',
        'metadata',
        'is_current',
        'is_deleted',
        'changed_at',
        'changed_by',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
        'status_ended_at' => 'datetime',
        'metadata' => Json::class,
        'is_current' => 'boolean',
    ];

    public $timestamps = true;

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    public function fromStatus(): BelongsTo
    {
        return $this->belongsTo(OrderStatus::class, 'from_status_id', 'id');
    }

    public function toStatus(): BelongsTo
    {
        return $this->belongsTo(OrderStatus::class, 'to_status_id', 'id');
    }
}
