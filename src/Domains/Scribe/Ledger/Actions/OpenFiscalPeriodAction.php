<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Ledger\Enums\FiscalPeriodStatusEnum;
use Kanvas\Scribe\Ledger\Models\FiscalPeriod;
use RuntimeException;

/**
 * Creates a new fiscal period in OPEN state.
 *
 * Idempotent: if a period already exists with the exact (period_start, period_end) bounds, it's returned
 * untouched (rather than re-created). This protects against duplicate-create on retry.
 *
 * Rejects overlap with any existing period for the same (apps_id, companies_id) — fiscal periods cover
 * the timeline without gaps or overlaps.
 */
class OpenFiscalPeriodAction
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly Carbon $periodStart,
        public readonly Carbon $periodEnd,
        public readonly ?UserInterface $user = null,
        public readonly ?array $metadata = null,
    ) {
    }

    public function execute(): FiscalPeriod
    {
        if ($this->periodStart->greaterThan($this->periodEnd)) {
            throw new RuntimeException(
                "period_start ({$this->periodStart->toDateString()}) must be on or before period_end "
                . "({$this->periodEnd->toDateString()})."
            );
        }

        return DB::connection('accounting')->transaction(function (): FiscalPeriod {
            $existing = FiscalPeriod::query()
                ->where('apps_id', $this->app->getId())
                ->where('companies_id', $this->company->getId())
                ->whereDate('period_start', $this->periodStart->toDateString())
                ->whereDate('period_end', $this->periodEnd->toDateString())
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $this->assertNoOverlap();

            $period = new FiscalPeriod();
            $period->apps_id = $this->app->getId();
            $period->companies_id = $this->company->getId();
            $period->period_start = $this->periodStart->toDateString();
            $period->period_end = $this->periodEnd->toDateString();
            $period->status = FiscalPeriodStatusEnum::OPEN;
            $period->metadata = $this->metadata;
            $period->save();

            return $period->refresh();
        });
    }

    private function assertNoOverlap(): void
    {
        $overlap = FiscalPeriod::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where(function ($q): void {
                $q->whereDate('period_start', '<=', $this->periodEnd->toDateString())
                    ->whereDate('period_end', '>=', $this->periodStart->toDateString());
            })
            ->first();

        if ($overlap !== null) {
            throw new RuntimeException(
                "Fiscal period overlap: proposed {$this->periodStart->toDateString()}—"
                . "{$this->periodEnd->toDateString()} overlaps with existing period {$overlap->id} "
                . "({$overlap->period_start->toDateString()}—{$overlap->period_end->toDateString()})."
            );
        }
    }
}
