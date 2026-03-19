<?php

namespace Kanvas\Souk\Payments\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Souk\Models\BaseModel;
use Kanvas\Souk\Payments\Actions\LogPaymentEventAction;
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
 * @property string|null $processor
 * @property string|null $payment_intent_id
 * @property string|null $authorization_code
 * @property string|null $number
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

    public function getMetadata(string $key): mixed
    {
        return $this->metadata['data'][$key] ?? null;
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

    public function addLog(string $event, array $context = []): void
    {
        app(LogPaymentEventAction::class)->execute($this, $event, $context);
    }

    public function isPaid(): bool
    {
        return $this->status === PaymentStatusEnum::PAID->value;
    }

    public function markAsPaid(array $metadata = []): void
    {
        $this->status = PaymentStatusEnum::PAID->value;

        if ($this->payable) {
            $this->payable->updateQuietly([
                'payment_status' => PaymentStatusEnum::PAID->value,
            ]);
        }

        if (! empty($metadata)) {
            $this->addMetadata($metadata);
        }

        $this->save();

        if ($this->payable && method_exists($this->payable, 'checkPayments')) {
            $this->payable->checkPayments();
        }
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
