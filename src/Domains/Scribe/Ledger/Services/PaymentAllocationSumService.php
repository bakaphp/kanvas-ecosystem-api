<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Services;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Scribe\Invoices\Enums\AllocationStatusEnum;

/**
 * Sums ACTIVE payment allocations for a parent entity (Invoice or Bill). Centralizes the SQL that
 * MarkInvoicePaidAction + MarkBillPaidAction both need.
 *
 * Returns `[native, base]` — same tuple shape the callers were already destructuring into local
 * `$paidNative` + `$paidBase` vars, so the refactor is a 5-line replacement at each site.
 *
 * Status enum lives in `Kanvas\Scribe\Invoices\Enums\AllocationStatusEnum` historically; both
 * Invoice and Bill allocations share the same string values (`active`, `reversed`) so a single
 * filter constant works for both.
 */
class PaymentAllocationSumService
{
    /**
     * @param  class-string<Model>  $allocationModelClass  e.g. `InvoicePaymentAllocation::class`
     * @param  string  $fkColumn  the column on the allocation table that points at the parent entity
     *                            (`invoice_id` or `bill_id`)
     * @return array{0: float, 1: float}  `[paidNative, paidBase]`
     */
    public function sumActive(
        string $allocationModelClass,
        string $fkColumn,
        int $entityId,
    ): array {
        $rows = $allocationModelClass::query()
            ->where($fkColumn, $entityId)
            ->where('status', AllocationStatusEnum::ACTIVE->value)
            ->get(['amount_native', 'amount_base']);

        return [
            (float) $rows->sum('amount_native'),
            (float) $rows->sum('amount_base'),
        ];
    }
}
