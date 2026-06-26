<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Services;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Shared recompute-and-transition workflow for "mark payable as paid" Actions on every Scribe
 * sub-ledger that has a PAID terminal-ish state and an `*_payment_allocations` table.
 *
 * Used by `MarkInvoicePaidAction` (AR) and `MarkBillPaidAction` (AP). Any future sub-ledger that
 * tracks allocations the same way (SalesReceipt-with-deposits, vendor-credits, etc.) reuses this
 * service instead of duplicating the workflow.
 *
 * Workflow per call (one accounting-DB transaction):
 *   1. Sum active allocations via PaymentAllocationSumService.
 *   2. Update entity.paid_native / paid_base / balance_due_native / balance_due_base.
 *   3. If balance hits zero AND current status is non-terminal AND state machine permits →
 *      transition to PAID and clear collection_state.
 *   4. Save. If we crossed into PAID this call, emit the ledger event with the merged payload.
 *
 * Pure state recompute — does NOT post a JE. The Cash JE (DR Cash / CR AR for invoices,
 * DR AP / CR Cash for bills) is posted by the Action that creates the allocation row.
 */
class PayableMarkAsPaidExecutorService
{
    public function __construct(
        protected readonly PaymentAllocationSumService $allocationSummer = new PaymentAllocationSumService(),
    ) {
    }

    /**
     * @param  Model                $entity                Invoice or Bill instance — needs
     *                                                     id, paid_native, paid_base, total_native,
     *                                                     total_base, balance_due_native,
     *                                                     balance_due_base, document_status,
     *                                                     collection_state, currency, save(),
     *                                                     refresh(), emitLedgerEvent().
     * @param  BackedEnum           $paidStatusEnum        The PAID case of the entity's
     *                                                     document_status enum.
     * @param  Closure              $canTransitionToPaid   `fn(BackedEnum $current): bool` —
     *                                                     wraps the entity's state machine.
     * @param  class-string         $allocationModelClass  Allocation Eloquent class to sum.
     * @param  string               $allocationFkColumn    FK column on the allocation table
     *                                                     ('invoice_id' or 'bill_id').
     * @param  string               $ledgerEventName       `scribe.invoice.paid` or `scribe.bill.paid`.
     * @param  array<string, mixed> $extraEventPayload     Caller-supplied identity fields (e.g.
     *                                                     `['invoice_number' => '...']`). Merged with
     *                                                     paid_native / paid_base / currency.
     */
    public function execute(
        Model $entity,
        BackedEnum $paidStatusEnum,
        Closure $canTransitionToPaid,
        string $allocationModelClass,
        string $allocationFkColumn,
        string $ledgerEventName,
        array $extraEventPayload = [],
    ): Model {
        return DB::connection('accounting')->transaction(function () use (
            $entity,
            $paidStatusEnum,
            $canTransitionToPaid,
            $allocationModelClass,
            $allocationFkColumn,
            $ledgerEventName,
            $extraEventPayload,
        ): Model {
            $statusBefore = $entity->document_status;

            [$paidNative, $paidBase] = $this->allocationSummer->sumActive(
                allocationModelClass: $allocationModelClass,
                fkColumn: $allocationFkColumn,
                entityId: (int) $entity->id,
            );

            $entity->paid_native = $paidNative;
            $entity->paid_base = $paidBase;
            $entity->balance_due_native = max(0.0, (float) $entity->total_native - $paidNative);
            $entity->balance_due_base = max(0.0, (float) $entity->total_base - $paidBase);

            if ($entity->balance_due_native <= 0 && ! $entity->document_status->isTerminal()) {
                if ($canTransitionToPaid($entity->document_status)) {
                    $entity->document_status = $paidStatusEnum;
                    $entity->collection_state = null;
                    $entity->paid_at = $allocationModelClass::query()
                        ->where($allocationFkColumn, $entity->id)
                        ->where('status', 'active')
                        ->max('allocated_at');
                }
            }

            $entity->save();

            if ($statusBefore !== $paidStatusEnum && $entity->document_status === $paidStatusEnum) {
                $entity->emitLedgerEvent(
                    eventType: $ledgerEventName,
                    payload: array_merge($extraEventPayload, [
                        'paid_native' => $paidNative,
                        'paid_base' => $paidBase,
                        'currency' => $entity->currency,
                    ]),
                );
            }

            return $entity->refresh();
        });
    }
}
