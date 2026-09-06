<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Models;

use Baka\Casts\Json;
use Baka\Observers\ClearsLightHouseCacheObserver;
use Baka\Traits\HasLightHouseCache;
use Baka\Traits\KanvasAppScopesTrait;
use Baka\Traits\KanvasCompanyScopesTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kanvas\Filesystem\Traits\HasFilesystemTrait;
use Kanvas\Scribe\Ledger\Enums\FiscalPeriodStatusEnum;
use Kanvas\Users\Models\Users;
use Override;

/**
 * Fiscal periods are admin-managed time slots, immutable after closing — no soft delete needed.
 * Extends EloquentModel directly (not Scribe\Models\BaseModel) so it doesn't inherit
 * is_deleted / custom fields / lifecycle traits. Files are the one exception: a close packet
 * attaches to the period it closes, so HasFilesystemTrait is taken on its own rather than by
 * moving to the soft-deleting base — this table has no is_deleted column to move to.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $status
 * @property Carbon|null $closed_at
 * @property int|null $closed_by_users_id
 * @property string|null $close_notes
 * @property array|null $metadata
 */
#[ObservedBy([ClearsLightHouseCacheObserver::class])]
class FiscalPeriod extends EloquentModel
{
    use HasFilesystemTrait;
    use HasLightHouseCache;
    use KanvasAppScopesTrait;
    use KanvasCompanyScopesTrait;
    use UuidTrait;

    protected $connection = 'accounting';
    protected $table = 'fiscal_periods';
    protected $guarded = [];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'closed_at' => 'datetime',
        'metadata' => Json::class,
        'status' => FiscalPeriodStatusEnum::class,
    ];

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'fiscal_period_id', 'id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'closed_by_users_id', 'id');
    }

    public function acceptsPostings(): bool
    {
        return $this->status === FiscalPeriodStatusEnum::OPEN;
    }

    #[Override]
    public function getGraphTypeName(): string
    {
        return 'ScribeFiscalPeriod';
    }

    // Hand-rolled rather than taken from KanvasModelTrait, which would drag in the soft-delete
    // helpers this table cannot answer. HasLightHouseCache keys its cache on this.
    public function getId(): mixed
    {
        return $this->getKey();
    }
}
