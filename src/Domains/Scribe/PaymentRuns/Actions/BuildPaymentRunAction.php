<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PaymentRuns\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\PaymentRuns\DataTransferObject\PaymentRun as PaymentRunData;
use Kanvas\Scribe\PaymentRuns\Enums\PaymentRunLineStatusEnum;
use Kanvas\Scribe\PaymentRuns\Enums\PaymentRunStatusEnum;
use Kanvas\Scribe\PaymentRuns\Models\PaymentRun;
use Kanvas\Scribe\PaymentRuns\Models\PaymentRunLine;
use Kanvas\Scribe\PaymentRuns\Services\PaymentRunCashCheckService;

/**
 * Proposes which bills to pay and from which account. Only approved (RECEIVED), unpaid bills qualify, and a
 * bill already waiting on another open run is left out so the person who pays is never asked to pay it twice.
 * The run is a draft until SendPaymentRunAction hands it to that person; nothing here moves money.
 */
class BuildPaymentRunAction
{
    public function __construct(
        public readonly PaymentRunData $data,
        private readonly PaymentRunCashCheckService $cashCheck = new PaymentRunCashCheckService(),
    ) {
    }

    public function execute(): PaymentRun
    {
        $candidates = $this->candidates();
        $onOpenRuns = $this->billsOnOpenRuns($candidates->modelKeys());
        $skipped = $this->skippedSelection($candidates);

        $bills = $candidates->reject(function (Bill $bill) use ($onOpenRuns, &$skipped): bool {
            if (isset($onOpenRuns[$bill->getId()])) {
                $skipped[] = [
                    'bill_id' => $bill->getId(),
                    'reason' => 'already_on_run',
                    'payment_run_id' => $onOpenRuns[$bill->getId()],
                ];

                return true;
            }

            return false;
        })->values();

        if ($bills->isEmpty()) {
            throw new ValidationException(
                $this->data->billIds !== null
                    ? 'None of the selected bills can go on a payment run (they must be approved, unpaid and not already on an open run).'
                    : "No approved, unpaid bills are due by {$this->data->dueBy->toDateString()} that are not already on an open run."
            );
        }

        $groups = $bills->groupBy(fn (Bill $bill): string => strtoupper($bill->currency))
            ->map(fn (Collection $currencyBills, string $currency): array => $this->cashCheck->group(
                $this->data->app,
                $this->data->company,
                $currency,
                (float) $currencyBills->sum('balance_due_native'),
                $currencyBills->count()
            ))
            ->values()
            ->all();

        $accountByCurrency = array_column($groups, 'bank_account_id', 'currency');

        return DB::connection('accounting')->transaction(function () use ($bills, $groups, $accountByCurrency, $skipped): PaymentRun {
            $run = new PaymentRun();
            $run->apps_id = (int) $this->data->app->getId();
            $run->companies_id = (int) $this->data->company->getId();
            $run->users_id = $this->data->user->getId();
            $run->agent_id = $this->data->agentId;
            $run->status = PaymentRunStatusEnum::DRAFT;
            $run->due_by = $this->data->dueBy;
            $run->line_count = $bills->count();
            $run->groups = $groups;
            $run->notes = $this->data->notes;
            $run->metadata = ['skipped' => $skipped, 'selected' => $this->data->billIds !== null];
            $run->save();

            foreach ($bills as $bill) {
                $line = new PaymentRunLine();
                $line->payment_run_id = $run->getId();
                $line->apps_id = $run->apps_id;
                $line->companies_id = $run->companies_id;
                $line->bill_id = $bill->getId();
                $line->bank_account_id = $accountByCurrency[strtoupper($bill->currency)] ?? null;
                $line->vendor_name = $bill->vendor_display_name ?? $bill->vendor_legal_name;
                $line->bill_number = $bill->bill_number;
                $line->due_date = $bill->due_date;
                $line->currency = strtoupper($bill->currency);
                $line->amount_native = (float) $bill->balance_due_native;
                $line->status = PaymentRunLineStatusEnum::PENDING;
                $line->save();
            }

            $run->emitLedgerEvent('scribe.payment_run.built', payload: [
                'payment_run_id' => $run->getId(),
                'line_count' => $run->line_count,
                'due_by' => $run->due_by?->toDateString(),
                'groups' => $groups,
                'skipped' => count($skipped),
            ]);

            return $run;
        });
    }

    /**
     * @return Collection<int, Bill>
     */
    private function candidates(): Collection
    {
        $query = Bill::query()
            ->where('apps_id', $this->data->app->getId())
            ->where('companies_id', $this->data->company->getId())
            ->where('is_deleted', false)
            ->where('document_status', BillDocumentStatusEnum::RECEIVED->value)
            ->where('balance_due_native', '>', 0)
            ->orderBy('due_date')
            ->orderBy('id');

        if ($this->data->billIds !== null) {
            $query->whereIn('id', $this->data->billIds);
        } else {
            $query->whereDate('due_date', '<=', $this->data->dueBy);
        }

        return $query->get();
    }

    /**
     * A hand-picked bill that did not qualify is reported, not silently dropped.
     *
     * @param Collection<int, Bill> $candidates
     * @return list<array<string, mixed>>
     */
    private function skippedSelection(Collection $candidates): array
    {
        if ($this->data->billIds === null) {
            return [];
        }

        $missing = array_diff($this->data->billIds, $candidates->modelKeys());

        return array_values(array_map(
            fn (int $billId): array => ['bill_id' => $billId, 'reason' => 'not_approved_unpaid_or_not_found'],
            $missing
        ));
    }

    /**
     * @param list<int> $billIds
     * @return array<int, int> bill id => open run id
     */
    private function billsOnOpenRuns(array $billIds): array
    {
        if ($billIds === []) {
            return [];
        }

        return PaymentRunLine::query()
            ->join(
                'payment_runs',
                'payment_runs.id',
                '=',
                'payment_run_lines.payment_run_id'
            )
            ->where('payment_run_lines.apps_id', $this->data->app->getId())
            ->where('payment_run_lines.companies_id', $this->data->company->getId())
            ->whereIn('payment_run_lines.bill_id', $billIds)
            ->whereIn('payment_run_lines.status', PaymentRunLineStatusEnum::openValues())
            ->where('payment_run_lines.is_deleted', false)
            ->whereIn('payment_runs.status', PaymentRunStatusEnum::openValues())
            ->where('payment_runs.is_deleted', false)
            ->pluck('payment_run_lines.payment_run_id', 'payment_run_lines.bill_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
