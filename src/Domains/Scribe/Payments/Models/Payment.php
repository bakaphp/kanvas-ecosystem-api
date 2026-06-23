<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Payments\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Models\BaseModel;
use Kanvas\Scribe\Payments\Enums\PaymentDirectionEnum;
use Kanvas\Scribe\Payments\Enums\PaymentMethodEnum;
use Kanvas\Scribe\Payments\Enums\PaymentStatusEnum;
use Kanvas\Users\Models\Users;
use Override;

/**
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int|null $users_id
 * @property float $amount_native
 * @property float $amount_base
 * @property string $currency
 * @property float $fx_rate_to_base
 * @property Carbon|null $fx_rate_at
 * @property Carbon $payment_date
 * @property Carbon|null $cleared_at
 * @property Carbon|null $reconciled_at
 * @property PaymentDirectionEnum $direction
 * @property PaymentMethodEnum $method
 * @property PaymentStatusEnum $status
 * @property int|null $bank_account_id
 * @property string|null $reference
 * @property string|null $notes
 * @property string $source
 * @property string|null $external_id
 * @property string|null $external_url
 * @property Carbon|null $reversed_at
 * @property int|null $reversed_by_users_id
 * @property string|null $reversal_reason
 * @property array|null $metadata
 * @property bool $is_deleted
 */
class Payment extends BaseModel
{
    use UuidTrait;

    protected $table = 'payments';
    protected $guarded = [];

    protected $casts = [
        'amount_native' => 'float',
        'amount_base' => 'float',
        'fx_rate_to_base' => 'float',
        'fx_rate_at' => 'datetime',
        'payment_date' => 'date',
        'cleared_at' => 'datetime',
        'reconciled_at' => 'datetime',
        'reversed_at' => 'datetime',
        'is_deleted' => 'boolean',
        'direction' => PaymentDirectionEnum::class,
        'method' => PaymentMethodEnum::class,
        'status' => PaymentStatusEnum::class,
        'metadata' => Json::class,
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id', 'id');
    }

    #[Override]
    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id', 'id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'reversed_by_users_id', 'id');
    }
}
