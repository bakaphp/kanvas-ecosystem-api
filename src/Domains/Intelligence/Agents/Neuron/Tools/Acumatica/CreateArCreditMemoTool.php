<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica;

use Kanvas\Connectors\Acumatica\Actions\PushInvoiceToAcumaticaAction;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum as AcumaticaCustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Invoices\Actions\IssueCreditNoteAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Spatie\LaravelData\DataCollection;
use Throwable;

/** Issues an AR credit memo against an already-issued invoice, and pushes it to Acumatica. */
#[AgentTool(name: 'Create AR Credit Memo', category: 'accounting')]
class CreateArCreditMemoTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'create_ar_credit_memo',
            description: 'Issues a credit memo against an already-issued invoice — for a credit/allowance the '
                . 'sales team has approved — and pushes it to Acumatica as a Credit Memo. Only call when the '
                . 'user explicitly asks to issue a credit, never on a whim.',
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
                name: 'invoice_number',
                type: PropertyType::STRING,
                description: 'The original invoice number this credit memo is against. Always required — never '
                    . 'guess which invoice; ask the user if it is not clear from context.',
                required: true,
            ),
            new ToolProperty(
                name: 'amount',
                type: PropertyType::NUMBER,
                description: 'The credit amount. Must not exceed the original invoice\'s total.',
                required: true,
            ),
            new ToolProperty(
                name: 'memo',
                type: PropertyType::STRING,
                description: 'Reason for the credit/allowance — becomes the credit memo\'s description.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $invoice_number, float $amount, string $memo): array
    {
        $app = $this->app;
        $company = $this->company;

        if (trim($invoice_number) === '') {
            return [
                'created' => false,
                'reason' => 'invoice_number_required',
                'message' => 'An invoice_number is required — never guess which invoice to credit.',
            ];
        }

        $parentInvoice = Invoice::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('invoice_number', trim($invoice_number))
            ->where('document_type', DocumentTypeEnum::INVOICE->value)
            ->first();

        if ($parentInvoice === null) {
            return [
                'created' => false,
                'reason' => 'invoice_not_found',
                'message' => "No invoice numbered \"{$invoice_number}\" for this app/company.",
            ];
        }

        $validParentStatuses = [
            InvoiceDocumentStatusEnum::ISSUED,
            InvoiceDocumentStatusEnum::SENT,
            InvoiceDocumentStatusEnum::PAID,
        ];

        if (! in_array($parentInvoice->document_status, $validParentStatuses, true)) {
            return [
                'created' => false,
                'reason' => 'invoice_not_creditable',
                'message' => "Invoice {$invoice_number} is '{$parentInvoice->document_status->value}' — it must "
                    . 'be issued, sent, or paid before it can be credited.',
            ];
        }

        $actingUser = $this->user;

        try {
            $creditNote = new IssueCreditNoteAction(
                parentInvoice: $parentInvoice,
                data: new InvoiceData(
                    app: $app,
                    company: $company,
                    billable: null,
                    lines: new DataCollection(InvoiceLineData::class, [
                        new InvoiceLineData(
                            description: $memo,
                            quantity: 1.0,
                            unit_price_native: $amount,
                        ),
                    ]),
                    currency: $parentInvoice->currency,
                    fx_rate_to_base: (float) $parentInvoice->fx_rate_to_base,
                    notes: $memo,
                ),
                user: $actingUser,
            )->execute();
        } catch (Throwable $e) {
            return [
                'created' => false,
                'reason' => 'issue_failed',
                'message' => 'Could not issue the credit memo: ' . $e->getMessage(),
            ];
        }

        try {
            $reference = new PushInvoiceToAcumaticaAction($creditNote)->execute();
        } catch (AcumaticaWriteException|Throwable $e) {
            return [
                'created' => true,
                'pushed' => false,
                'credit_memo_id' => $creditNote->getId(),
                'credit_memo_number' => $creditNote->invoice_number,
                'parent_invoice_number' => $parentInvoice->invoice_number,
                'reason' => 'push_failed',
                'message' => 'Credit memo was issued in Kanvas but the push to Acumatica failed: '
                    . $e->getMessage() . '. It needs manual attention — it will not auto-retry.',
            ];
        }

        return [
            'created' => true,
            'pushed' => true,
            'credit_memo_id' => $creditNote->getId(),
            'credit_memo_number' => $creditNote->invoice_number,
            'parent_invoice_number' => $parentInvoice->invoice_number,
            'amount' => $amount,
            'currency' => $creditNote->currency,
            'memo' => $memo,
            'credit_memo_ref' => $reference,
            'acumatica_invoice_id' => (string) $creditNote->get(AcumaticaCustomFieldEnum::INVOICE_ID->value, ''),
            'next' => 'Pushed to Acumatica as a Credit Memo. credit_memo_ref is the ERP reference. Use '
                . 'add_bill_note-style tools if you need to attach the approval email or add a note.',
        ];
    }
}
