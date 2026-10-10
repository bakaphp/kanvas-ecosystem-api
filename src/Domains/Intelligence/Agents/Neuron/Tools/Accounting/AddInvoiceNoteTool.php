<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesInvoiceForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/** Appends an internal note to an AR invoice or credit memo in the Kanvas ledger. */
#[AgentTool(name: 'Add Invoice Note', category: 'accounting')]
class AddInvoiceNoteTool extends Tool
{
    use HasKanvasContext;
    use ResolvesInvoiceForTool;

    protected string $name = 'add_invoice_note';

    protected ?string $description = 'Appends a timestamped internal note to an AR invoice or credit memo in the '
        . 'Kanvas ledger.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'invoice_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas invoice/credit memo id to add the note to (returned as invoice_id by '
                    . 'create_ar_invoice, or credit_memo_id by create_ar_credit_memo).',
                required: true,
            ),
            new ToolProperty(
                name: 'note',
                type: PropertyType::STRING,
                description: 'The note text to append.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $invoice_id, string $note): array
    {
        $invoice = $this->resolveInvoice($invoice_id);

        if (is_array($invoice)) {
            return ['note_added' => false, ...$invoice];
        }

        $stamped = '[' . Carbon::now()->toDateTimeString() . '] ' . $note;
        $invoice->internal_notes = $invoice->internal_notes !== null && $invoice->internal_notes !== ''
            ? $invoice->internal_notes . "\n" . $stamped
            : $stamped;
        $invoice->saveOrFail();

        return [
            'note_added' => true,
            'invoice_id' => $invoice->getId(),
            'note' => $stamped,
        ];
    }
}
