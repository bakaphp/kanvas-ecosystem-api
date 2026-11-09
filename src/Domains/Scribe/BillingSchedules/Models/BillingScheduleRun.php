<?php

declare(strict_types=1);

namespace Kanvas\Scribe\BillingSchedules\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\BillingSchedules\Enums\BillingScheduleRunStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Models\BaseModel;

/**
 * One billed period of a schedule. The unique (billing_schedule_id, period_start) index is the
 * guarantee that a period is never invoiced twice, even by two workers racing on the same schedule.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $billing_schedule_id
 * @property Carbon $period_start
 * @property int|null $invoice_id
 * @property BillingScheduleRunStatusEnum $status
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $next_retry_at
 * @property Carbon|null $updated_at
 */
class BillingScheduleRun extends BaseModel
{
    public const int STUCK_AFTER_MINUTES = 30;
    public const int MAX_ATTEMPTS = 8;

    protected $table = 'billing_schedule_runs';
    protected $guarded = [];

    protected $casts = [
        'period_start' => 'date',
        'status' => BillingScheduleRunStatusEnum::class,
        'attempts' => 'integer',
        'next_retry_at' => 'datetime',
        'is_deleted' => 'boolean',
    ];

    /**
     * A run whose draft exists but never reached approval: the submission failed and its retry time came,
     * or the worker died between the commit and the submission.
     */
    public function scopeAwaitingSubmission(Builder $query, Carbon $now): Builder
    {
        return $query->notDeleted()
            ->whereNotNull('invoice_id')
            ->where(fn (Builder $query): Builder => $query
                ->where(fn (Builder $failed): Builder => $failed
                    ->where('status', BillingScheduleRunStatusEnum::FAILED->value)
                    ->whereNotNull('next_retry_at')
                    ->where('next_retry_at', '<=', $now))
                ->orWhere(fn (Builder $stuck): Builder => $stuck
                    ->where('status', BillingScheduleRunStatusEnum::INVOICED->value)
                    ->where('updated_at', '<=', $now->copy()->subMinutes(self::STUCK_AFTER_MINUTES))));
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(BillingSchedule::class, 'billing_schedule_id', 'id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id', 'id');
    }
}
