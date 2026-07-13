<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;
use Kanvas\Scribe\Banking\Enums\BankTransactionCategoryEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionDirectionEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionMatchedByEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionMatchedToTypeEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionMatchStatusEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\Models\BaseModel;

/**
 * One movement as the bank reported it. The bank is the source of cash truth, so this row exists whether
 * or not we can explain it — `match_status` carries that uncertainty rather than blocking the insert.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
 * @property int $bank_account_id
 * @property Carbon $posted_at
 * @property Carbon $transaction_date
 * @property BankTransactionDirectionEnum $direction
 * @property float $amount_native
 * @property string $currency
 * @property float $amount_base
 * @property float $fx_rate_to_base
 * @property string|null $counterparty_name
 * @property string|null $counterparty_account_last4
 * @property string|null $memo
 * @property BankTransactionCategoryEnum $category
 * @property array|null $raw_payload
 * @property BankTransactionMatchStatusEnum $match_status
 * @property BankTransactionMatchedToTypeEnum|null $matched_to_type
 * @property int|null $matched_to_id
 * @property Carbon|null $matched_at
 * @property BankTransactionMatchedByEnum|null $matched_by
 * @property float|null $match_confidence
 * @property int|null $journal_entry_id
 * @property string $source
 * @property string|null $external_id
 * @property array|null $metadata
 * @property bool $is_deleted
 * @property int|null $users_id
 */
class BankTransaction extends BaseModel
{
    use EmitsLedgerEventsForEntity;
    use UuidTrait;

    protected $table = 'bank_transactions';
    protected $guarded = [];

    /**
     * Mirrors the DB defaults so a freshly-constructed row is coherent in memory too. Without this,
     * match_status is null until the model is reloaded, and isAccountedFor() fatals on the null.
     * (BaseModel declares is_deleted here; redeclaring the array replaces it, so it must be repeated.)
     */
    protected $attributes = [
        'is_deleted' => 0,
        'match_status' => 'unmatched',
        'category' => 'unknown',
    ];

    protected $casts = [
        'posted_at' => 'datetime',
        'transaction_date' => 'date',
        'direction' => BankTransactionDirectionEnum::class,
        'amount_native' => 'float',
        'amount_base' => 'float',
        'fx_rate_to_base' => 'float',
        'category' => BankTransactionCategoryEnum::class,
        'raw_payload' => Json::class,
        'match_status' => BankTransactionMatchStatusEnum::class,
        'matched_to_type' => BankTransactionMatchedToTypeEnum::class,
        'matched_at' => 'datetime',
        'matched_by' => BankTransactionMatchedByEnum::class,
        'match_confidence' => 'float',
        'metadata' => Json::class,
        'is_deleted' => 'boolean',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id', 'id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id', 'id');
    }

    /**
     * True once this row has been accounted for — either it settles a document (and reuses that
     * document's JE) or it posted its own. Both the composer and the matcher early-return on this.
     */
    public function isAccountedFor(): bool
    {
        return $this->journal_entry_id !== null || $this->match_status->isSettled();
    }

    protected function sourceDomainForLedger(): string
    {
        return 'Scribe';
    }
}
