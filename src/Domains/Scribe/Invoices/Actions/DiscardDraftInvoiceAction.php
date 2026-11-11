<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Approvals\Actions\CancelApprovalAction;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * A draft never posted anything, so it is not voided (nothing to reverse) but withdrawn: its open approval is
 * cancelled and the row soft-deleted. An issued invoice goes through VoidInvoiceAction, and a draft that already
 * holds a fiscal number is never discarded, because the authority counts that number as used.
 */
class DiscardDraftInvoiceAction
{
    public function __construct(
        public readonly Invoice $invoice,
        public readonly ?UserInterface $user = null,
        public readonly ?string $reason = null,
    ) {
    }

    public function execute(): Invoice
    {
        if ($this->invoice->document_status !== InvoiceDocumentStatusEnum::DRAFT) {
            throw new ValidationException(
                "Invoice {$this->invoice->getId()} is {$this->invoice->document_status->value}, not a draft: void it instead."
            );
        }

        if (EcfCompliance::hasNumber($this->invoice)) {
            throw new ValidationException(
                "Invoice {$this->invoice->getId()} already holds fiscal number " . EcfCompliance::number($this->invoice)
                . ', which the tax authority counts as used, so it cannot be discarded.'
            );
        }

        return DB::connection('accounting')->transaction(function (): Invoice {
            $this->invoice->pendingApprovals()->each(
                fn (ApprovalRequest $request) => new CancelApprovalAction($request, $this->reason ?? 'draft discarded')->execute()
            );

            $this->invoice->emitLedgerEvent('scribe.invoice.discarded', payload: [
                'document_type' => $this->invoice->document_type->value,
                'quote_id' => $this->invoice->quote_id,
                'total_native' => (float) $this->invoice->total_native,
                'reason' => $this->reason,
                'users_id' => $this->user?->getId(),
            ]);

            $this->invoice->softDelete();

            return $this->invoice;
        });
    }
}
