<?php

declare(strict_types=1);

namespace Kanvas\Souk\Cart\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Models\BaseModel;
use Kanvas\Souk\Orders\Models\Order;
use Override;

/**
 * Class Cart
 *
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int|null $users_id
 * @property string|null $cart_session_id
 * @property string|null $email
 * @property float|null $amount
 * @property string $currency
 * @property string $status
 * @property array|null $metadata
 * @property array|null $items
 * @property array|null $conditions
 * @property int|null $recovery_discount_id
 * @property \Carbon\Carbon|null $recovery_email_sent_at
 * @property \Carbon\Carbon|null $recovered_at
 * @property int|null $recovered_order_id
 * @property \Carbon\Carbon|null $abandoned_at
 * @property bool $is_deleted
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class Cart extends BaseModel
{
    use UuidTrait;

    protected $table = 'carts';

    protected $fillable = [
        'uuid',
        'apps_id',
        'companies_id',
        'users_id',
        'session_id',
        'email',
        'amount',
        'currency',
        'status',
        'notification_count',
        'metadata',
        'items',
        'conditions',
        'recovery_discount_id',
        'recovery_email_sent_at',
        'recovered_at',
        'recovered_order_id',
        'abandoned_at',
    ];

    protected $attributes = [
        'currency' => 'usd',
        'status' => 'pending',
        'is_deleted' => 0,
    ];

    #[Override]
    public function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'metadata' => Json::class,
            'is_deleted' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'items' => Json::class,
            'conditions' => Json::class,
            'recovery_email_sent_at' => 'datetime',
            'recovered_at' => 'datetime',
            'abandoned_at' => 'datetime',
        ];
    }

    public function recoveryDiscount(): BelongsTo
    {
        return $this->belongsTo(Discount::class, 'recovery_discount_id');
    }

    public function recoveredOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'recovered_order_id');
    }

    /**
     * Mark cart as recovered.
     */
    public function markAsRecovered(Order $order): self
    {
        $this->update([
            'status' => 'recovered',
            'recovered_at' => now(),
            'recovered_order_id' => $order->getId(),
        ]);

        return $this;
    }

    /**
     * Mark cart as abandoned.
     */
    public function markAsAbandoned(): self
    {
        $this->update([
            'status' => 'abandoned',
            'abandoned_at' => now(),
        ]);

        return $this;
    }

    /**
     * Mark recovery email as sent.
     */
    public function markRecoveryEmailSent(): self
    {
        $this->update([
            'recovery_email_sent_at' => now(),
        ]);

        return $this;
    }

    /**
     * Check if cart is eligible for recovery.
     */
    public function isEligibleForRecovery(): bool
    {
        return $this->status === 'abandoned' && 
               $this->recovery_email_sent_at === null &&
               $this->abandoned_at && 
               $this->abandoned_at->diffInHours(now()) >= 1;
    }
}
