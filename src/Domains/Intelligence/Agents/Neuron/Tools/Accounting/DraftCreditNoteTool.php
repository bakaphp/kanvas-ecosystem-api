<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Scribe\Invoices\Actions\IssueCreditNoteAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Draft Credit Note', category: 'accounting')]
class DraftCreditNoteTool extends AbstractDraftInvoiceNoteTool
{
    use TrackByInputs;

    protected string $name = 'draft_credit_note';

    protected ?string $description = 'Drafts a credit note against one issued invoice (lowers what the customer owes: a '
        . 'discount after the fact, a returned item, a price error, or cancelling the invoice in full) and submits it '
        . 'for a person\'s approval. On an e-CF company it becomes an E34 referencing the invoice once approved. You '
        . 'never issue it yourself. Use only when the user asks for a credit on a specific invoice.';

    #[Override]
    protected function noteType(): DocumentTypeEnum
    {
        return DocumentTypeEnum::CREDIT_NOTE;
    }

    #[Override]
    protected function noteLabel(): string
    {
        return 'credit note';
    }

    #[Override]
    protected function draftNote(Invoice $parent, InvoiceData $data): Invoice
    {
        return new IssueCreditNoteAction(parentInvoice: $parent, data: $data, user: $this->user)->draft();
    }
}
