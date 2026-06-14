<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Models\BaseModel;

/**
 * Minimal bank account row — points at the GL Cash account that backs it.
 * Full Banking subdomain (transactions, reconciliations, transfers) lands in Phase 3.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
 * @property string $account_name
 * @property string|null $account_number_last4
 * @property string|null $routing_number_masked
 * @property string|null $institution_name
 * @property string $currency
 * @property int $gl_account_id
 * @property float|null $current_balance_native
 * @property float|null $available_balance_native
 * @property \Illuminate\Support\Carbon|null $last_balance_sync_at
 * @property bool $is_active
 * @property string $source
 * @property string|null $external_id
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 * @property array|null $metadata
 * @property bool $is_deleted
 * @property int|null $users_id
 */
class BankAccount extends BaseModel
{
    use UuidTrait;

    protected $table = 'bank_accounts';
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'is_deleted' => 'boolean',
        'current_balance_native' => 'float',
        'available_balance_native' => 'float',
        'last_balance_sync_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'metadata' => Json::class,
    ];

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'gl_account_id', 'id');
    }
}
