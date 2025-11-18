<?php

namespace Kanvas\Souk\Payments\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Souk\Models\BaseModel;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
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
    use UuidTrait;

    protected $table = 'payments';
    protected $guarded = [];

    protected $casts = [
        'metadata' => Json::class,
    ];

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethods::class, 'payment_methods_id', 'id');
    }

    public function paymentLogs(): HasMany
    {
        return $this->hasMany(PaymentLogs::class, 'payments_id', 'id');
    }

    public function order(): BelongsTo
    {
        return $this->morphTo('payable');
    }

    public function addMetadata(array $metadata): void
    {
        $this->metadata = [
            ...($this->metadata ?? []),
            ...($metadata ?? []),
            'data' => [
                ...($this->metadata['data'] ?? []),
                ...($metadata['data'] ?? []),
            ],
        ];
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', [PaymentStatusEnum::PENDING->value, PaymentStatusEnum::PENDING_AUTHORIZATION->value]);
    }

    /**
     * Get the latest payment for an entity with specific statuses.
     */
    public static function getLatestForEntity(Model $entity, ?array $statuses = null, ?int $appId = null): ?self
    {
        $statuses = $statuses ?? [
            PaymentStatusEnum::WAITING_DEVICE_DATA->value,
            PaymentStatusEnum::PENDING_AUTHORIZATION->value,
            PaymentStatusEnum::PENDING->value,
        ];

        return self::where([
            'apps_id' => $entity->apps_id ?? $appId,
            'payable_id' => $entity->id,
            'payable_type' => get_class($entity),
        ])
        ->whereIn('status', $statuses)
        ->with('paymentMethod')
        ->latest()
        ->first();
    }
}
