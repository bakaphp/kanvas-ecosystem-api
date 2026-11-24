<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;
use Kanvas\Scribe\Banking\Enums\BankStatementRejectionEnum;
use Kanvas\Scribe\Banking\Enums\BankStatementSourceEnum;
use Kanvas\Scribe\Banking\Enums\BankStatementStatusEnum;
use Kanvas\Scribe\Models\BaseModel;

/**
 * @property int $id
 * @property string $uuid
 * @property int $apps_id
 * @property int $companies_id
 * @property int|null $users_id
 * @property int|null $bank_account_id
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property float|null $opening_balance
 * @property float|null $closing_balance
 * @property string|null $currency
 * @property string|null $account_number_last4
 * @property int|null $filesystem_id
 * @property int $row_count
 * @property int $new_row_count
 * @property BankStatementStatusEnum $status
 * @property BankStatementSourceEnum $source
 * @property BankStatementRejectionEnum|null $rejected_reason_code
 * @property string|null $rejected_reason
 * @property string|null $fingerprint
 * @property array|null $metadata
 * @property bool $is_deleted
 */
class BankStatement extends BaseModel
{
    use EmitsLedgerEventsForEntity;
    use UuidTrait;

    /**
     * Every statement entry point lands its rows under this one bank_transactions.source, so the
     * (apps_id, source, external_id) key dedupes a CSV against a PDF of the same period.
     */
    public const string TRANSACTION_SOURCE = 'bank_statement';

    protected $table = 'bank_statements';
    protected $guarded = [];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'opening_balance' => 'float',
        'closing_balance' => 'float',
        'row_count' => 'integer',
        'new_row_count' => 'integer',
        'status' => BankStatementStatusEnum::class,
        'source' => BankStatementSourceEnum::class,
        'rejected_reason_code' => BankStatementRejectionEnum::class,
        'metadata' => Json::class,
        'is_deleted' => 'boolean',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id', 'id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class, 'bank_statement_id', 'id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(Filesystem::class, 'filesystem_id', 'id');
    }

    protected function sourceDomainForLedger(): string
    {
        return 'Scribe';
    }
}
