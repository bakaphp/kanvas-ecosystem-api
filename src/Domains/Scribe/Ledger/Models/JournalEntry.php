<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Models;

use Baka\Casts\Json;
use Baka\Traits\KanvasAppScopesTrait;
use Baka\Traits\KanvasCompanyScopesTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Kanvas\Scribe\Ledger\Enums\JournalEntryStatusEnum;

/**
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
 * @property string|null $je_number
 * @property \Illuminate\Support\Carbon $posted_at
 * @property int|null $fiscal_period_id
 * @property string $source_type
 * @property int|null $source_id
 * @property string|null $source_external_id
 * @property string|null $memo
 * @property bool $is_adjustment
 * @property int|null $is_reversal_of
 * @property int|null $posted_by_users_id
 * @property string $status
 * @property string $source
 * @property string|null $external_id
 * @property string $origin
 * @property array|null $metadata
 */
/**
 * Journal entries are immutable post-posting — reversals are mirror JE rows, never row-edits.
 * Extends EloquentModel directly (not Scribe\Models\BaseModel) — no soft delete / custom fields / files needed.
 */
class JournalEntry extends EloquentModel
{
    use KanvasAppScopesTrait;
    use KanvasCompanyScopesTrait;
    use UuidTrait;

    protected $connection = 'accounting';
    protected $table = 'journal_entries';
    protected $guarded = [];

    protected $casts = [
        'posted_at' => 'datetime',
        'is_adjustment' => 'boolean',
        'metadata' => Json::class,
        'status' => JournalEntryStatusEnum::class,
        'origin' => JournalEntryOriginEnum::class,
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'journal_entry_id', 'id');
    }

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id', 'id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'is_reversal_of', 'id');
    }

    public function isPosted(): bool
    {
        return $this->status === JournalEntryStatusEnum::POSTED;
    }
}
