<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Actions;

use Baka\Contracts\BillableInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\DocumentSequences\Services\DocumentNumberAllocatorService;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfIssuanceService;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Exceptions\InvalidInvoiceTransitionException;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Services\InvoiceJournalEntryComposerService;

/**
 * Issues a credit note, either against a parent Invoice or standalone (e.g. a period/campaign-based
 * back-end rebate that never referenced a specific invoice).
 *
 * A credit note is a new row in `accounting.invoices` with:
 *   - `document_type = 'credit_note'`
 *   - `parent_invoice_id = <parent>` when there is one, else null
 *   - amounts stored POSITIVE (the document_type discriminator + JE direction carry the credit sign)
 *
 * execute() creates the row and issues it in one accounting-DB transaction (IssueDraftCreditNoteAction does
 * the numbering, the JE and the allocation on the parent). draft() stops before that, for the one caller
 * that must: an agent on an e-CF company, whose credit note waits for a person's approval before an E34
 * number is spent. execute() refuses that caller rather than letting it skip the approval.
 *
 * @see plan §7.3 — credit notes as document_type discriminator
 */
class IssueCreditNoteAction
{
    public function __construct(
        public readonly ?Invoice $parentInvoice,
        public readonly InvoiceData $data,
        public readonly ?BillableInterface $billable = null,
        public readonly ?UserInterface $user = null,
        protected readonly InvoiceJournalEntryComposerService $composer = new InvoiceJournalEntryComposerService(),
        protected readonly ?DocumentNumberAllocatorService $allocator = null,
        protected readonly EcfIssuanceService $ecf = new EcfIssuanceService(),
    ) {
    }

    public function execute(): Invoice
    {
        $this->assertCreatable();

        $this->ecf->assertAgentMayIssueNoteDirectly($this->data->company, 'credit note');

        return DB::connection('accounting')->transaction(function (): Invoice {
            return new IssueDraftCreditNoteAction(
                creditNote: $this->createDraftRow(),
                user: $this->user,
                composer: $this->composer,
                allocator: $this->allocator,
                ecf: $this->ecf,
            )->execute();
        });
    }

    /**
     * The credit note as a DRAFT with no number and no journal entry — what an agent creates on an e-CF
     * company so a person can approve it before an E34 number is spent. IssueDraftCreditNoteAction issues it.
     */
    public function draft(): Invoice
    {
        $this->assertCreatable();

        return DB::connection('accounting')->transaction(fn (): Invoice => $this->createDraftRow());
    }

    private function assertCreatable(): void
    {
        IssueDraftCreditNoteAction::assertParentCreditable($this->parentInvoice);

        if ($this->parentInvoice === null && $this->billable === null) {
            throw new InvalidInvoiceTransitionException(
                'A standalone credit note (no parent invoice) requires a billable customer.'
            );
        }
    }

    private function createDraftRow(): Invoice
    {
        return new CreateInvoiceNoteDraftAction(
            noteType: DocumentTypeEnum::CREDIT_NOTE,
            parentInvoice: $this->parentInvoice,
            data: $this->data,
            billable: $this->billable,
            user: $this->user,
        )->execute();
    }
}
