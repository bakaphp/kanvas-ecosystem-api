<?php

declare(strict_types=1);

namespace Kanvas\Scribe\BillingSchedules\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\BillingSchedules\DataTransferObject\BillingSchedule as BillingScheduleData;
use Kanvas\Scribe\BillingSchedules\Enums\BillingScheduleStatusEnum;
use Kanvas\Scribe\BillingSchedules\Models\BillingSchedule;

/**
 * Edits apply to periods not yet billed. Moving the start or the anchor recomputes the next run only
 * while nothing has been billed; after that the schedule keeps its own cadence from the last period.
 */
class UpdateBillingScheduleAction
{
    public function __construct(
        public readonly BillingSchedule $schedule,
        public readonly BillingScheduleData $data,
    ) {
    }

    public function execute(): BillingSchedule
    {
        if ($this->schedule->status === BillingScheduleStatusEnum::FINISHED) {
            throw new ValidationException(
                "Billing schedule {$this->schedule->getId()} is finished; create a new one instead."
            );
        }

        $this->data->assertValid();

        $schedule = $this->schedule;
        $previousStatus = $schedule->status;

        $schedule->name = $this->data->name;
        $schedule->currency = $this->data->currency;
        $schedule->cadence = $this->data->cadence;
        $schedule->anchor_day = $this->data->anchor_day;
        $schedule->start_date = $this->data->start_date;
        $schedule->end_date = $this->data->end_date;
        $schedule->net_terms_days = $this->data->net_terms_days;
        $schedule->notes = $this->data->notes;
        $schedule->status = $this->data->status;

        if (! $schedule->runs()->exists()) {
            $schedule->next_run_at = $this->data->firstRunDate();
        }

        DB::connection('accounting')->transaction(function () use ($schedule): void {
            $schedule->saveOrFail();
            $schedule->replaceLines($this->data->lines);
        });

        $schedule->emitLedgerEvent(
            eventType: $previousStatus !== $schedule->status
                ? 'scribe.billing_schedule.' . $schedule->status->value
                : 'scribe.billing_schedule.updated',
            payload: [
                'previous_status' => $previousStatus->value,
                'status' => $schedule->status->value,
                'amount_per_period' => $schedule->linesTotal(),
                'next_run_at' => $schedule->next_run_at?->toDateString(),
            ],
        );

        return $schedule;
    }
}
