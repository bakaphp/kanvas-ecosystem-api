<?php

declare(strict_types=1);

namespace Kanvas\Scribe\BillingSchedules\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Services\ApprovalOriginService;
use Kanvas\Scribe\BillingSchedules\Enums\BillingScheduleRunStatusEnum;
use Kanvas\Scribe\BillingSchedules\Enums\BillingScheduleStatusEnum;
use Kanvas\Scribe\BillingSchedules\Models\BillingSchedule;
use Kanvas\Scribe\BillingSchedules\Models\BillingScheduleLine;
use Kanvas\Scribe\BillingSchedules\Models\BillingScheduleRun;
use Kanvas\Scribe\Invoices\Actions\CreateInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\SubmitInvoiceForApprovalAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Spatie\LaravelData\DataCollection;
use Throwable;

/**
 * Bills ONE period of a schedule: a DRAFT invoice, submitted for approval as agent-originated work so
 * the approval policy (not this job) decides whether a person signs off. Returns null when nothing was
 * due and nothing was waiting to be submitted.
 *
 * Idempotent per period. The run row claims the period (unique schedule + period), and the invoice,
 * the run and the schedule's advance commit together. The submission runs after the commit: when it
 * fails the run is left `failed` with a next_retry_at, and the next execution resumes that run's draft
 * before billing anything new, so a draft is never stranded behind a schedule that already moved on.
 */
class RunBillingScheduleAction
{
    public function __construct(
        public readonly BillingSchedule $schedule,
        public readonly Carbon $today,
    ) {
    }

    public function execute(): ?Invoice
    {
        $run = $this->runAwaitingSubmission();

        if ($run === null) {
            try {
                $run = DB::connection('accounting')->transaction(fn (): ?BillingScheduleRun => $this->billDuePeriod());
            } catch (UniqueConstraintViolationException $e) {
                if (! $this->isDuplicatePeriod($e)) {
                    throw $e;
                }

                $run = null;
            }
        }

        if ($run === null) {
            return null;
        }

        /** @var Invoice $invoice */
        $invoice = $run->invoice;
        $this->submit($run, $invoice);

        return $invoice->refresh();
    }

    private function runAwaitingSubmission(): ?BillingScheduleRun
    {
        /** @var BillingScheduleRun|null $run */
        $run = BillingScheduleRun::query()
            ->where('billing_schedule_id', $this->schedule->getId())
            ->awaitingSubmission(Carbon::now())
            ->orderBy('period_start')
            ->first();

        return $run;
    }

    private function billDuePeriod(): ?BillingScheduleRun
    {
        $schedule = BillingSchedule::query()->whereKey($this->schedule->getId())->lockForUpdate()->firstOrFail();

        if (! $schedule->isDue($this->today)) {
            return null;
        }

        /** @var Carbon $period */
        $period = $schedule->next_run_at->copy();

        if ($schedule->end_date !== null && $period->gt($schedule->end_date)) {
            $this->finish($schedule);

            return null;
        }

        /** @var BillingScheduleRun $run */
        $run = BillingScheduleRun::query()
            ->where('billing_schedule_id', $schedule->getId())
            ->whereDate('period_start', $period->toDateString())
            ->first()
            ?? BillingScheduleRun::create([
                'apps_id' => $schedule->apps_id,
                'companies_id' => $schedule->companies_id,
                'billing_schedule_id' => $schedule->getId(),
                'period_start' => $period->toDateString(),
                'status' => BillingScheduleRunStatusEnum::PROCESSING,
            ]);

        $invoice = $run->invoice ?? $this->createInvoice($schedule, $period);

        if ($run->invoice_id === null || $run->status === BillingScheduleRunStatusEnum::PROCESSING) {
            $run->invoice_id = $invoice->getId();
            $run->status = BillingScheduleRunStatusEnum::INVOICED;
            $run->saveOrFail();
        }

        $next = $schedule->cadence->nextPeriod($period, $schedule->anchor_day);
        $schedule->last_run_at = Carbon::now();
        $schedule->next_run_at = $next;

        if ($next === null || ($schedule->end_date !== null && $next->gt($schedule->end_date))) {
            $schedule->status = BillingScheduleStatusEnum::FINISHED;
            $schedule->next_run_at = null;
        }

        $schedule->saveOrFail();

        $schedule->emitLedgerEvent(
            eventType: 'scribe.billing_schedule.invoiced',
            payload: [
                'period' => $period->toDateString(),
                'invoice_id' => $invoice->getId(),
                'billing_schedule_run_id' => $run->getId(),
                'total_native' => (float) $invoice->total_native,
                'currency' => $invoice->currency,
                'next_run_at' => $schedule->next_run_at?->toDateString(),
                'status' => $schedule->status->value,
            ],
        );

        return $run->setRelation('invoice', $invoice);
    }

    private function createInvoice(BillingSchedule $schedule, Carbon $period): Invoice
    {
        $lines = $schedule->lines->map(
            static fn (BillingScheduleLine $line): InvoiceLineData => new InvoiceLineData(
                description: $line->description,
                quantity: (float) $line->quantity,
                unit_price_native: (float) $line->unit_price,
                item_id: $line->item_id,
                tax_amount_native: (float) $line->tax_amount,
            ),
        )->all();

        $netTermsDays = $schedule->net_terms_days ?? 0;

        return new CreateInvoiceAction(
            data: new InvoiceData(
                app: $schedule->app,
                company: $schedule->company,
                billable: $schedule->customer,
                lines: new DataCollection(InvoiceLineData::class, $lines),
                currency: $schedule->currency,
                fx_rate_to_base: 1.0,
                net_terms_days: $netTermsDays,
                issued_date: $period->copy(),
                due_date: $period->copy()->addDays($netTermsDays),
                notes: $schedule->notes,
                quote_id: $schedule->source_quote_id,
                metadata: [
                    'billing_schedule_id' => $schedule->getId(),
                    'billing_period' => $period->toDateString(),
                ],
            ),
            user: $schedule->user,
        )->execute();
    }

    /**
     * A failed submission is recorded and reported, not thrown: the draft and the schedule's advance are
     * already committed, and the run row is what brings this draft back on the next run.
     */
    private function submit(BillingScheduleRun $run, Invoice $invoice): void
    {
        $run->attempts++;

        try {
            if ($invoice->document_status === InvoiceDocumentStatusEnum::DRAFT) {
                ApprovalOriginService::during(
                    ApprovalOriginEnum::AGENT,
                    fn (): Invoice => new SubmitInvoiceForApprovalAction(
                        invoice: $invoice,
                        user: $this->schedule->user,
                    )->execute(),
                );
            }

            $run->status = BillingScheduleRunStatusEnum::SUBMITTED;
            $run->last_error = null;
            $run->next_retry_at = null;
        } catch (Throwable $e) {
            report($e);

            $run->status = BillingScheduleRunStatusEnum::FAILED;
            $run->last_error = mb_substr($e->getMessage(), 0, 2000);
            $run->next_retry_at = $run->attempts < BillingScheduleRun::MAX_ATTEMPTS
                ? Carbon::now()->addHours(min(2 ** ($run->attempts - 1), 24))
                : null;
        }

        $run->saveOrFail();
    }

    private function finish(BillingSchedule $schedule): void
    {
        $schedule->status = BillingScheduleStatusEnum::FINISHED;
        $schedule->next_run_at = null;
        $schedule->saveOrFail();
    }

    private function isDuplicatePeriod(UniqueConstraintViolationException $e): bool
    {
        return str_contains($e->getMessage(), 'billing_schedule_runs_period_unique');
    }
}
