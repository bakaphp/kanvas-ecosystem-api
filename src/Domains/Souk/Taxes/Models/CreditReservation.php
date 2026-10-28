<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Models\BaseModel;

/**
 * @property int $id
 * @property string $uuid
 * @property int $snapshot_id
 * @property int $companies_id
 * @property int $discount_id
 * @property float $amount
 * @property string $status  — held | consumed | released
 * @property int $is_deleted
 */
class CreditReservation extends BaseModel
{
    use UuidTrait;

    protected $table = 'credit_reservations';
    protected $guarded = [];

    public static function bootAppsIdTrait(): void
    {
    }

    protected function casts(): array
    {
        return [
            'amount' => 'float',
        ];
    }

    public static function settleHeldFromPayload(array $payload, string $status): void
    {
        $reservationId = $payload['credit_reservation_id'] ?? null;

        if ($reservationId === null) {
            return;
        }

        self::query()
            ->where('id', $reservationId)
            ->where('status', 'held')
            ->update(['status' => $status]);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(OrderTaxSnapshot::class, 'snapshot_id', 'id');
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class, 'discount_id', 'id');
    }
}
