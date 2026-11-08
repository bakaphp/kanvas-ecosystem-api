<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Scribe\BillingSchedules\Models\BillingSchedule;
use Kanvas\Scribe\BillingSchedules\Models\BillingScheduleLine;

/**
 * Resolves a billing schedule by id, scoped to the agent's tenant. Requires HasKanvasContext. Returns
 * the schedule or an LLM-facing error array.
 */
trait ResolvesBillingScheduleForTool
{
    use HasKanvasContext;

    /**
     * @return BillingSchedule|array{reason: string, message: string}
     */
    protected function resolveBillingSchedule(int $scheduleId): BillingSchedule|array
    {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('billing schedule');
        }

        $schedule = BillingSchedule::query()
            ->where('id', $scheduleId)
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->first();

        if ($schedule === null) {
            return [
                'reason' => 'billing_schedule_not_found',
                'message' => "No billing schedule with id {$scheduleId} for this company. list_billing_schedules "
                    . 'shows the ones that exist; do not retry with the same id.',
            ];
        }

        return $schedule;
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentBillingSchedule(BillingSchedule $schedule): array
    {
        return [
            'billing_schedule_id' => $schedule->getId(),
            'name' => $schedule->name,
            'customer' => $schedule->customer?->name,
            'customer_organization_id' => $schedule->customer_organization_id,
            'status' => $schedule->status->value,
            'cadence' => $schedule->cadence->value,
            'anchor_day' => $schedule->anchor_day,
            'currency' => $schedule->currency,
            'amount_per_period' => round($schedule->linesTotal(), 2),
            'lines' => $schedule->lines->map(fn (BillingScheduleLine $line): array => $line->toPresentation())->all(),
            'start_date' => $schedule->start_date->toDateString(),
            'end_date' => $schedule->end_date?->toDateString(),
            'next_run_at' => $schedule->next_run_at?->toDateString(),
            'last_run_at' => $schedule->last_run_at?->toDateTimeString(),
            'net_terms_days' => $schedule->net_terms_days,
            'source_quote_id' => $schedule->source_quote_id,
        ];
    }
}
