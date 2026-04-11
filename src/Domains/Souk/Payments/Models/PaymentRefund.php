<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Souk\Models\BaseModel;
use Kanvas\Souk\Payments\Enums\RefundStatusEnum;
use Kanvas\Users\Models\Users;

/**
 * @property int $apps_id
 * @property int $companies_id
 * @property int $payments_id
 * @property int $users_id
 * @property float $amount
 * @property string|null $currency
 * @property string|null $reason
 * @property string|null $processor_refund_id
 * @property string $status
 * @property array|null $metadata
 */
class PaymentRefund extends BaseModel
{
    use UuidTrait;

    protected $table = 'payment_refunds';
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

    public function isCompleted(): bool
    {
        return $this->status === RefundStatusEnum::COMPLETED->value;
    }

    public function markAsCompleted(array $metadata = []): void
    {
        $this->status = RefundStatusEnum::COMPLETED->value;

        if (! empty($metadata)) {
            $this->metadata = array_merge($this->metadata ?? [], $metadata);
        }

        $this->save();
    }

    public function markAsFailed(array $metadata = []): void
    {
        $this->status = RefundStatusEnum::FAILED->value;

        if (! empty($metadata)) {
            $this->metadata = array_merge($this->metadata ?? [], $metadata);
        }

        $this->save();
    }
}
