<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfIssuanceService;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * A debit note against an issued invoice: the customer owes more than the invoice said (DGII E33 on an
 * e-CF company). Same shape as IssueCreditNoteAction — execute() creates and issues, draft() stops at
 * the DRAFT row for an agent on an e-CF company, whose debit note waits for a person's approval.
 */
class IssueDebitNoteAction
{
    public function __construct(
        public readonly Invoice $parentInvoice,
        public readonly InvoiceData $data,
        public readonly ?UserInterface $user = null,
        protected readonly EcfIssuanceService $ecf = new EcfIssuanceService(),
    ) {
    }

    public function execute(): Invoice
    {
        IssueDraftDebitNoteAction::assertParentDebitable($this->parentInvoice);

        $this->ecf->assertAgentMayIssueNoteDirectly($this->data->company, 'debit note');

        return DB::connection('accounting')->transaction(fn (): Invoice => new IssueDraftDebitNoteAction(
            debitNote: $this->createDraftRow(),
            user: $this->user,
            ecf: $this->ecf,
        )->execute());
    }

    public function draft(): Invoice
    {
        IssueDraftDebitNoteAction::assertParentDebitable($this->parentInvoice);

        return DB::connection('accounting')->transaction(fn (): Invoice => $this->createDraftRow());
    }

    private function createDraftRow(): Invoice
    {
        return new CreateInvoiceNoteDraftAction(
            noteType: DocumentTypeEnum::DEBIT_NOTE,
            parentInvoice: $this->parentInvoice,
            data: $this->data,
            user: $this->user,
        )->execute();
    }
}
