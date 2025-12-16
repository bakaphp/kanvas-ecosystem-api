<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kanvas\Souk\Models\BaseModel;
use Kanvas\Souk\Payments\Enums\PaymentMethodTypesEnum;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Users\Models\Users;

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
        'ended_at',
        'duration_in_seconds',
        'changed_at',
        'changed_by',
        'ended_by'
    ];

    protected $casts = [
        'changed_at' => 'datetime',
        'ended_at' => 'datetime',
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
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'changed_by', 'id');
    }
    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'ended_by', 'id');
    }

    /**
     * Get the paid payment for this order transition.
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payments::class, 'payable_id', 'order_id')
            ->where('payable_type', Order::class)
            ->where('status', PaymentStatusEnum::PAID->value);
    }

    /**
     * Scope to filter transitions to "paid" status with physical deposit (no paid payment record).
     */
    public function scopeManualDeposit(Builder $query): Builder
    {
        return $query->whereHas('toStatus', function ($q) {
            $q->where('slug', 'paid');
        })
        ->whereHas('order', function ($q) {
            $q->whereDoesntHave('payments', function ($pq) {
                $pq->where('status', PaymentStatusEnum::PAID->value);
            });
        });
    }

    /**
     * Scope to filter transitions to "paid" status with digital payment (has paid payment record).
     */
    public function scopePayment(Builder $query): Builder
    {
        return $query->whereHas('toStatus', function ($q) {
            $q->where('slug', 'paid');
        })
        ->whereHas('order', function ($q) {
            $q->whereHas('payments', function ($pq) {
                $pq->where('status', PaymentStatusEnum::PAID->value);
            });
        });
    }
    /**
     * Scope to filter transitions to "paid" status with digital payment (has paid payment record).
     */
    public function scopePaymentMethod(Builder $query, string $paymentMethodType): Builder
    {
        return $query->whereHas('toStatus', function ($q) {
            $q->where('slug', 'paid');
        })
        ->whereHas('order', function ($q) use ($paymentMethodType) {
            $q->whereHas('payments', function ($pq) use ($paymentMethodType) {
                $pq->where('status', PaymentStatusEnum::PAID->value)
                   ->where('payment_method', $paymentMethodType);
            });
        });
    }

    /**
     * Scope to filter only paid transitions.
     */
    public function scopePaidTransitions(Builder $query): Builder
    {
        return $query->whereHas('toStatus', function ($q) {
            $q->where('slug', 'paid');
        });
    }

    /**
     * Scope to filter by payment method type.
     *
     * @param string $type Payment method type from PaymentMethodTypesEnum
     */
    public function scopePaymentMethodType(Builder $query, string $type): Builder
    {
        $methodType = strtolower($type);

        switch ($methodType) {
            case PaymentMethodTypesEnum::MANUAL->value:
                return $this->scopeManualDeposit($query);
                break;
            case PaymentMethodTypesEnum::PAYMENT->value:
                return $this->scopePayment($query);
                break;
            case PaymentMethodTypesEnum::CARD->value:
            case PaymentMethodTypesEnum::CASH->value:
            case PaymentMethodTypesEnum::BANK_TRANSFER->value:
            case PaymentMethodTypesEnum::WALLET->value:
                return $this->scopePaymentMethod($query, $type);
                break;
            default:
                return $query;
        }
    }

    /**
     * Check if this transition has a paid payment record.
     */
    public function isPaid(): bool
    {
        return $this->order->payments()
            ->where('status', PaymentStatusEnum::PAID->value)
            ->exists();
    }

    /**
     * Get payment type (physical or digital).
     * Returns null if order payment_status is not 'paid'.
     */
    public function getPaymentType(): ?string
    {
        // Only return payment type if order payment_status is 'paid'
        if ($this->order->payment_status !== 'paid') {
            return null;
        }

        // If paid, check if it has a paid payment record
        return $this->isPaid() ? 'digital' : 'physical';
    }
}
