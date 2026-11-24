<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Communication\Models\BaseModel;

class DeliveryEvent extends BaseModel
{
    protected $table = 'communication_delivery_events';

    protected function casts(): array
    {
        return [
            'payload' => Json::class,
            'occurred_at' => 'datetime',
        ];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class, 'delivery_id');
    }
}
