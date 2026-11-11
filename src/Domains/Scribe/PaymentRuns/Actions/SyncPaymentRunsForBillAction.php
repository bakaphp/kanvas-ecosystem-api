<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PaymentRuns\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\PaymentRuns\Enums\PaymentRunLineStatusEnum;
use Kanvas\Scribe\PaymentRuns\Enums\PaymentRunStatusEnum;
use Kanvas\Scribe\PaymentRuns\Models\PaymentRun;
use Kanvas\Scribe\PaymentRuns\Models\PaymentRunLine;

/**
 * A run follows the payments the person reports; it never records one. Called after every bill allocation
 * (AllocateBillPaymentAction), so a payment told to the agent, entered in the UI, posted by a provider webhook
 * or settled from a statement all move the run the same way.
 */
class SyncPaymentRunsForBillAction
{
    private const float EPSILON = 0.0001;

    public function __construct(
        public readonly Bill $bill,
    ) {
    }

    /**
     * @return list<int> ids of the runs whose status changed
     */
    public function execute(): array
    {
        $lines = PaymentRunLine::query()
            ->where('apps_id', $this->bill->apps_id)
            ->where('companies_id', $this->bill->companies_id)
            ->where('bill_id', $this->bill->getId())
            ->whereIn('status', PaymentRunLineStatusEnum::openValues())
            ->get();

        $changed = [];

        foreach ($lines as $line) {
            $status = $this->lineStatus($line);

            if ($status === $line->status) {
                continue;
            }

            $line->status = $status;
            $line->paid_at = $status === PaymentRunLineStatusEnum::PAID ? Carbon::now() : null;
            $line->save();

            if ($line->run !== null && $this->refreshRun($line->run)) {
                $changed[] = $line->run->getId();
            }
        }

        return $changed;
    }

    private function lineStatus(PaymentRunLine $line): PaymentRunLineStatusEnum
    {
        $remaining = (float) $this->bill->balance_due_native;

        return match (true) {
            $remaining <= self::EPSILON => PaymentRunLineStatusEnum::PAID,
            $remaining + self::EPSILON < $line->amount_native => PaymentRunLineStatusEnum::PARTIALLY_PAID,
            default => PaymentRunLineStatusEnum::PENDING,
        };
    }

    private function refreshRun(PaymentRun $run): bool
    {
        $statuses = $run->lines()->pluck('status')->map(
            fn ($status): string => $status instanceof PaymentRunLineStatusEnum ? $status->value : (string) $status
        );

        $paid = $statuses->filter(fn (string $status): bool => $status === PaymentRunLineStatusEnum::PAID->value)->count();
        $touched = $statuses->filter(fn (string $status): bool => $status !== PaymentRunLineStatusEnum::PENDING->value)->count();

        $next = match (true) {
            $paid === $statuses->count() => PaymentRunStatusEnum::PAID,
            $touched > 0 => PaymentRunStatusEnum::PARTIALLY_PAID,
            default => $run->status,
        };

        if ($next === $run->status) {
            return false;
        }

        $previous = $run->status;
        $run->status = $next;
        $run->paid_at = $next === PaymentRunStatusEnum::PAID ? Carbon::now() : null;
        $run->save();

        $run->emitLedgerEvent('scribe.payment_run.status_changed', payload: [
            'payment_run_id' => $run->getId(),
            'from' => $previous->value,
            'to' => $next->value,
            'paid_lines' => $paid,
            'line_count' => $statuses->count(),
        ]);

        return true;
    }
}
