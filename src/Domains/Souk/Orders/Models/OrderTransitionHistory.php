<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Models;

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
        'is_deleted',
        'changed_at',
        'changed_by',
    ];

    public $timestamps = true;
}
