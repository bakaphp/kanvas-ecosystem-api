<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Connectors\Acumatica\Actions\VoidArInvoiceAction;
use Kanvas\Connectors\Acumatica\Enums\ConfigurationEnum as AcumaticaConfigurationEnum;
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

/**
 * Voids a previously-pushed AR invoice (and its cash receipt) in Acumatica — the cleanup counterpart to
 * CreateArInvoiceTool. Voids the payment, then creates and applies a Credit Memo against the invoice's
 * outstanding balance, closing both documents.
 *
 * STAGING ONLY, same hard gate as CreateArInvoiceTool: refuses to run — and voids nothing — unless the
 * app's ACUMATICA_ENVIRONMENT config is exactly 'staging'.
 *
 * @see \Kanvas\Connectors\Acumatica\Actions\VoidArInvoiceAction — the actual void.
 */
#[AgentTool(name: 'Void AR Invoice')]
class VoidArInvoiceTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'void_ar_invoice',
            description: 'STAGING ONLY. Voids a previously-pushed AR invoice in Acumatica by voiding its cash '
                . 'receipt and applying a Credit Memo against the remaining balance, closing both documents. '
                . 'Only works when this app is explicitly configured as a staging tenant — otherwise it refuses '
                . 'and voids nothing. Use only to clean up invoices created by create_ar_invoice, never on a '
                . 'real customer invoice.',
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

        $environment = (string) $app->get(AcumaticaConfigurationEnum::ACUMATICA_ENVIRONMENT->value, '');

        if ($environment !== 'staging') {
            return [
                'voided' => false,
                'reason' => 'not_staging',
                'message' => 'This app is not marked as an Acumatica staging tenant '
                    . '(ACUMATICA_ENVIRONMENT must equal "staging") — refusing to void anything.',
            ];
        }

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
            $creditMemoRef = new VoidArInvoiceAction($invoice)->execute();
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
            'credit_memo_ref' => $creditMemoRef,
            'next' => 'The cash receipt was voided and a Credit Memo was created and released against the '
                . 'invoice in Acumatica staging — both documents should now show Closed with a zero balance.',
        ];
    }
}
