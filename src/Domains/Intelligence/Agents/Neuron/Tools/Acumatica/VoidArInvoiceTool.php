<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica;

use Kanvas\Connectors\Acumatica\Actions\VoidArInvoiceAction;
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

/** Voids a previously-pushed AR invoice's cash receipt in Acumatica — the cleanup counterpart to CreateArInvoiceTool. */
#[AgentTool(name: 'Void AR Invoice')]
class VoidArInvoiceTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'void_ar_invoice',
            description: 'Voids a previously-pushed AR invoice\'s cash receipt in Acumatica by creating and '
                . 'releasing a Refund for the same amount, reversing the cash impact. Bypasses the normal human '
                . 'approval gate — use only when the user explicitly asks to void an invoice this way.',
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
                type: PropertyType::NUMBER,
                description: 'The Kanvas invoice id to void (returned as invoice_id by create_ar_invoice).',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $invoice_id): array
    {
        $app = $this->app;

        $invoice = Invoice::query()
            ->where('id', $invoice_id)
            ->where('apps_id', $app->getId())
            ->where('companies_id', $this->company->getId())
            ->first();

        if ($invoice === null) {
            return [
                'voided' => false,
                'reason' => 'invoice_not_found',
                'message' => "No invoice with id {$invoice_id} for this app/company.",
            ];
        }

        try {
            $refundRef = new VoidArInvoiceAction($invoice)->execute();
        } catch (AcumaticaWriteException|Throwable $e) {
            return [
                'voided' => false,
                'invoice_id' => $invoice->getId(),
                'invoice_ref' => (string) $invoice->get(AcumaticaCustomFieldEnum::INVOICE_REF->value, ''),
                'reason' => 'void_failed',
                'message' => 'Voiding the invoice in Acumatica failed: ' . $e->getMessage(),
            ];
        }

        return [
            'voided' => true,
            'invoice_id' => $invoice->getId(),
            'invoice_ref' => (string) $invoice->get(AcumaticaCustomFieldEnum::INVOICE_REF->value, ''),
            'refund_ref' => $refundRef,
            'next' => 'A Refund was created and released for the cash receipt amount — the customer\'s cash '
                . 'position is back to zero. The invoice and payment stay Closed, which is their normal state.',
        ];
    }
}
