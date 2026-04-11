<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Kanvas\Payments\Models\PaymentMethods;
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id', 'id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethods::class, 'payment_methods_id', 'id');
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }
}
