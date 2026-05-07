<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Models\BaseModel;
use Kanvas\Users\Models\Users;

class PaymentLogs extends BaseModel
{
    protected $table = 'payment_logs';
    protected $guarded = [];

    protected $casts = [
        'metadata' => Json::class,
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payments::class, 'payments_id', 'id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id', 'id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id', 'id');
    }
}
