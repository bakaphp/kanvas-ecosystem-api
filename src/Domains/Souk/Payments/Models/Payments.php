<?php

namespace Kanvas\Souk\Payments\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Souk\Models\BaseModel;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Traits\CanUseWorkflow;

/**
 * Class Payments
 * 
 * @property float $amount
 * @property string $payment_date
 * @property string $concept
 * @property string $payment_method_id
 * @property string $users_id
 * @property string $companies_id
 * @property string $currency_code
 * @property float $currency_rate
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Payments extends BaseModel
{
    use CanUseWorkflow;
    protected $table = 'payments';
    protected $guarded = [];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethods::class, 'payment_methods_id', 'id');
    }

    public function order(): BelongsTo
    {
        return $this->morphTo('payable');
    }

    public function addMetadata(array $metadata): void
    {
        $this->metadata = [
            ...($this->metadata ?? []),
            'data' => [
                ...($this->metadata['data'] ?? []),
                ...($metadata['data'] ?? []),
            ],
        ];
    }
}


