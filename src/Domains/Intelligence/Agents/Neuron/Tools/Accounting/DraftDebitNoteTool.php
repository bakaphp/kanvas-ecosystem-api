<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Scribe\Invoices\Actions\IssueDebitNoteAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Draft Debit Note', category: 'accounting')]
class DraftDebitNoteTool extends AbstractDraftInvoiceNoteTool
{
    use TrackByInputs;

    protected string $name = 'draft_debit_note';

    protected ?string $description = 'Drafts a debit note against one issued invoice (raises what the customer owes: an '
        . 'item or a charge left off, a price that was too low, late interest) and submits it for a person\'s approval. '
        . 'On an e-CF company it becomes an E33 referencing the invoice once approved. You never issue it yourself. '
        . 'Use only when the user asks to charge more on a specific invoice.';

    #[Override]
    protected function noteType(): DocumentTypeEnum
    {
        return DocumentTypeEnum::DEBIT_NOTE;
    }

    #[Override]
    protected function noteLabel(): string
    {
        return 'debit note';
    }

    #[Override]
    protected function draftNote(Invoice $parent, InvoiceData $data): Invoice
    {
        return new IssueDebitNoteAction(parentInvoice: $parent, data: $data, user: $this->user)->draft();
    }
}
