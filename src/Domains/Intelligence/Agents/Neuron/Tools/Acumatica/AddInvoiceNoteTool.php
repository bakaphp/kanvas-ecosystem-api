<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Acumatica\Actions\PushInvoiceNoteToAcumaticaAction;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum as AcumaticaCustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Invoices\Models\Invoice;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/** Appends a note to an already-pushed AR invoice or credit memo, in Kanvas and in Acumatica. */
#[AgentTool(name: 'Add Invoice Note', category: 'accounting')]
class AddInvoiceNoteTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'add_invoice_note',
            description: 'Appends a note to an AR invoice or credit memo that has already been pushed to '
                . 'Acumatica — records it both in Kanvas and on the Acumatica document\'s Notes field.',
        );
    }

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
        $invoice = Invoice::query()
            ->where('id', $invoice_id)
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->first();

        if ($invoice === null) {
            return [
                'note_added' => false,
                'reason' => 'invoice_not_found',
                'message' => "No invoice with id {$invoice_id} for this app/company.",
            ];
        }

        $ref = (string) $invoice->get(AcumaticaCustomFieldEnum::INVOICE_REF->value, '');

        if ($ref === '') {
            return [
                'note_added' => false,
                'reason' => 'invoice_not_pushed',
                'message' => "Invoice {$invoice_id} hasn't been pushed to Acumatica yet — push it before adding a note.",
            ];
        }

        $stamped = '[' . Carbon::now()->toDateTimeString() . '] ' . $note;
        $invoice->internal_notes = $invoice->internal_notes !== null && $invoice->internal_notes !== ''
            ? $invoice->internal_notes . "\n" . $stamped
            : $stamped;
        $invoice->saveOrFail();

        try {
            new PushInvoiceNoteToAcumaticaAction($invoice)->execute($stamped);
        } catch (AcumaticaWriteException|Throwable $e) {
            return [
                'note_added' => true,
                'pushed' => false,
                'invoice_id' => $invoice->getId(),
                'reason' => 'push_failed',
                'message' => 'Note saved in Kanvas but the push to Acumatica failed: ' . $e->getMessage(),
            ];
        }

        return [
            'note_added' => true,
            'pushed' => true,
            'invoice_id' => $invoice->getId(),
            'invoice_ref' => $ref,
            'note' => $stamped,
        ];
    }
}
