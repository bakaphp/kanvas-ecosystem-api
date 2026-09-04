<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Exceptions\InvalidBillTransitionException;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Bills\Models\BillReceipt;
use RuntimeException;

/**
 * Links an uploaded `Filesystem` row to a `Bill` by writing a row into `accounting.bill_receipts`.
 * Cross-DB pointer; no DDL FK (Filesystem lives on `mysql`, receipt row lives on `accounting`).
 * Mirrors Expenses\Actions\AttachExpenseReceiptAction.
 */
class AttachBillReceiptAction
{
    public function __construct(
        public readonly Bill $bill,
        public readonly Filesystem $filesystem,
        public readonly ?UserInterface $user = null,
        public readonly ?array $metadata = null,
    ) {
    }

    public function execute(): BillReceipt
    {
        $this->guardStatus();
        $this->guardScope();

        return DB::connection('accounting')->transaction(function (): BillReceipt {
            $receipt = new BillReceipt();
            $receipt->bill_id = $this->bill->id;
            $receipt->filesystem_id = (int) $this->filesystem->getKey();
            $receipt->uploaded_at = Carbon::now();
            $receipt->uploaded_by_users_id = $this->user?->getId();
            $receipt->metadata = $this->metadata;
            $receipt->save();

            return $receipt->refresh();
        });
    }

    private function guardStatus(): void
    {
        if ($this->bill->document_status === BillDocumentStatusEnum::VOIDED) {
            throw new InvalidBillTransitionException(
                "Cannot attach receipt to bill {$this->bill->id} — status is 'voided' (terminal)."
            );
        }
    }

    private function guardScope(): void
    {
        // Filesystem rows are tenant-scoped via apps_id + companies_id. Refuse cross-tenant attaches.
        if ((int) $this->filesystem->apps_id !== (int) $this->bill->apps_id) {
            throw new RuntimeException(
                "Filesystem row {$this->filesystem->getKey()} belongs to app {$this->filesystem->apps_id}, "
                . "bill belongs to app {$this->bill->apps_id}. Cross-app attach rejected."
            );
        }

        if ((int) $this->filesystem->companies_id !== (int) $this->bill->companies_id) {
            throw new RuntimeException(
                "Filesystem row {$this->filesystem->getKey()} belongs to company {$this->filesystem->companies_id}, "
                . "bill belongs to company {$this->bill->companies_id}. Cross-company attach rejected."
            );
        }
    }
}
