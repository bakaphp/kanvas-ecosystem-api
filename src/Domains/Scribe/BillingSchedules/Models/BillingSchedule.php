<?php

declare(strict_types=1);

namespace Kanvas\Scribe\BillingSchedules\Models;

use Baka\Support\Money;
use Baka\Traits\UuidTrait;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;
use Kanvas\Scribe\BillingSchedules\DataTransferObject\BillingScheduleLine as BillingScheduleLineData;
use Kanvas\Scribe\BillingSchedules\Enums\BillingCadenceEnum;
use Kanvas\Scribe\BillingSchedules\Enums\BillingScheduleStatusEnum;
use Kanvas\Scribe\Models\BaseModel;
use Kanvas\Scribe\Quotes\Models\Quote;

/**
 * A recurring retainer, or one payment line of a signed SOW. Each due period becomes one DRAFT
 * invoice through RunBillingScheduleAction; billing_schedule_runs records which periods are done.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int|null $users_id
 * @property string $uuid
 * @property int $customer_organization_id
 * @property string|null $name
 * @property string $currency
 * @property Collection<int, BillingScheduleLine> $lines
 * @property BillingCadenceEnum $cadence
 * @property int|null $anchor_day
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property Carbon|null $next_run_at
 * @property int|null $net_terms_days
 * @property string|null $notes
 * @property int|null $source_quote_id
 * @property string|null $source_key
 * @property BillingScheduleStatusEnum $status
 * @property Carbon|null $last_run_at
 * @property bool $is_deleted
 */
class BillingSchedule extends BaseModel
{
    use CascadeSoftDeletes;
    use EmitsLedgerEventsForEntity;
    use UuidTrait;

    protected $table = 'billing_schedules';
    protected $guarded = [];

    protected $cascadeDeletes = ['runs', 'lines'];

    protected $casts = [
        'cadence' => BillingCadenceEnum::class,
        'status' => BillingScheduleStatusEnum::class,
        'start_date' => 'date',
        'end_date' => 'date',
        'next_run_at' => 'date',
        'last_run_at' => 'datetime',
        'anchor_day' => 'integer',
        'net_terms_days' => 'integer',
        'is_deleted' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'customer_organization_id', 'id');
    }

    public function sourceQuote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'source_quote_id', 'id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillingScheduleLine::class, 'billing_schedule_id', 'id')
            ->where('is_deleted', 0)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * @param list<BillingScheduleLineData> $lines
     */
    public function replaceLines(array $lines): void
    {
        BillingScheduleLine::query()
            ->where('billing_schedule_id', $this->getId())
            ->where('is_deleted', 0)
            ->update(['is_deleted' => 1]);

        foreach ($lines as $position => $line) {
            BillingScheduleLine::create([
                'apps_id' => $this->apps_id,
                'companies_id' => $this->companies_id,
                'billing_schedule_id' => $this->getId(),
                'sort_order' => $position,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'discount_amount' => $line->discount_amount,
                'tax_rate' => $line->tax_rate,
                'tax_amount' => $line->tax_amount,
                'item_id' => $line->item_id,
            ]);
        }

        $this->unsetRelation('lines');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(BillingScheduleRun::class, 'billing_schedule_id', 'id');
    }

    public function scopeDue(Builder $query, Carbon $today): Builder
    {
        return $query->where('status', BillingScheduleStatusEnum::ACTIVE->value)
            ->whereNotNull('next_run_at')
            ->whereDate('next_run_at', '<=', $today->toDateString());
    }

    public function isDue(Carbon $today): bool
    {
        return $this->status === BillingScheduleStatusEnum::ACTIVE
            && $this->next_run_at !== null
            && $this->next_run_at->lte($today->copy()->endOfDay());
    }

    public function linesTotal(): float
    {
        return Money::stored(Money::sum(
            ...$this->lines->map(fn (BillingScheduleLine $line) => $line->total())->all()
        ));
    }

    protected function sourceDomainForLedger(): string
    {
        return 'Scribe';
    }
}
