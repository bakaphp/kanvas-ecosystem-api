<?php

declare(strict_types=1);

namespace Kanvas\Scribe\BillingSchedules\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\BillingSchedules\DataTransferObject\BillingSchedule as BillingScheduleData;
use Kanvas\Scribe\BillingSchedules\Models\BillingSchedule;

/**
 * Idempotent on (source quote, source key): a schedule created from a signed SOW line comes back
 * unchanged when the same line is scheduled again, so re-accepting a quote never doubles its billing.
 */
class CreateBillingScheduleAction
{
    public function __construct(
        public readonly BillingScheduleData $data,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): BillingSchedule
    {
        $this->data->assertValid();

        if ($this->data->sourceQuote !== null && $this->data->source_key !== null) {
            $existing = BillingSchedule::query()
                ->where('source_quote_id', $this->data->sourceQuote->getId())
                ->where('source_key', $this->data->source_key)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $schedule = new BillingSchedule();
        $schedule->apps_id = $this->data->app->getId();
        $schedule->companies_id = $this->data->company->getId();
        $schedule->users_id = $this->user?->getId();
        $schedule->customer_organization_id = $this->data->customer->getId();
        $schedule->name = $this->data->name;
        $schedule->currency = $this->data->currency;
        $schedule->cadence = $this->data->cadence;
        $schedule->anchor_day = $this->data->anchor_day;
        $schedule->start_date = $this->data->start_date;
        $schedule->end_date = $this->data->end_date;
        $schedule->next_run_at = $this->data->firstRunDate();
        $schedule->net_terms_days = $this->data->net_terms_days;
        $schedule->notes = $this->data->notes;
        $schedule->source_quote_id = $this->data->sourceQuote?->getId();
        $schedule->source_key = $this->data->source_key;
        $schedule->status = $this->data->status;
        DB::connection('accounting')->transaction(function () use ($schedule): void {
            $schedule->saveOrFail();
            $schedule->replaceLines($this->data->lines);
        });

        $schedule->emitLedgerEvent(
            eventType: 'scribe.billing_schedule.created',
            payload: [
                'customer_organization_id' => $schedule->customer_organization_id,
                'cadence' => $schedule->cadence->value,
                'currency' => $schedule->currency,
                'amount_per_period' => $schedule->linesTotal(),
                'next_run_at' => $schedule->next_run_at?->toDateString(),
                'source_quote_id' => $schedule->source_quote_id,
            ],
        );

        return $schedule;
    }
}
