<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica;

use Kanvas\Connectors\Acumatica\Actions\PushPaymentToAcumaticaAction;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum as AcumaticaCustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Invoices\Actions\AllocateInvoicePaymentAction;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Payments\Enums\PaymentMethodEnum;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use RuntimeException;
use Throwable;

/** Applies a cash receipt to an existing, already-pushed AR invoice and pushes the payment to Acumatica. */
#[AgentTool(name: 'Apply AR Payment', category: 'accounting')]
class ApplyArPaymentTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'apply_ar_payment',
            description: 'Applies a cash receipt to an existing AR invoice (partial or full) and pushes the '
                . 'payment to Acumatica. Only call when the user explicitly asks to record a real customer '
                . 'payment against an invoice — never on a whim.',
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
                description: 'The Kanvas invoice id to apply the payment against.',
                required: true,
            ),
            new ToolProperty(
                name: 'amount',
                type: PropertyType::NUMBER,
                description: 'Payment amount. Must not exceed the invoice\'s remaining balance.',
                required: true,
            ),
            new ToolProperty(
                name: 'reference',
                type: PropertyType::STRING,
                description: 'Payment reference (check number, wire ref, etc). Acumatica rejects an empty one.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $invoice_id, float $amount, string $reference): array
    {
        $app = $this->app;

        $invoice = Invoice::query()
            ->where('id', $invoice_id)
            ->where('apps_id', $app->getId())
            ->where('companies_id', $this->company->getId())
            ->first();

        if ($invoice === null) {
            return [
                'applied' => false,
                'reason' => 'invoice_not_found',
                'message' => "No invoice with id {$invoice_id} for this app/company.",
            ];
        }

        $invoiceRef = (string) $invoice->get(AcumaticaCustomFieldEnum::INVOICE_REF->value, '');

        if ($invoiceRef === '') {
            return [
                'applied' => false,
                'reason' => 'invoice_not_pushed',
                'message' => "Invoice {$invoice_id} hasn't been pushed to Acumatica yet — push it before applying a payment.",
            ];
        }

        try {
            $allocation = new AllocateInvoicePaymentAction(
                invoice: $invoice,
                amountNative: $amount,
                method: PaymentMethodEnum::CHECK,
                cashAccountSubType: AccountSubTypeEnum::CASH_CHECKING,
                reference: $reference,
                user: $this->user,
            )->execute();
        } catch (RuntimeException $e) {
            return [
                'applied' => false,
                'reason' => 'allocation_failed',
                'message' => $e->getMessage(),
            ];
        }

        $payment = $allocation->payment;

        try {
            $paymentRef = new PushPaymentToAcumaticaAction($payment)->execute();
        } catch (AcumaticaWriteException|Throwable $e) {
            return [
                'applied' => true,
                'pushed' => false,
                'invoice_id' => $invoice->getId(),
                'reason' => 'push_failed',
                'message' => 'Payment recorded in Kanvas but the push to Acumatica failed: ' . $e->getMessage(),
            ];
        }

        return [
            'applied' => true,
            'pushed' => true,
            'invoice_id' => $invoice->getId(),
            'invoice_ref' => $invoiceRef,
            'amount' => $amount,
            'payment_ref' => $paymentRef,
            'remaining_balance' => (float) $invoice->fresh()->balance_due_native,
            'document_status' => $invoice->fresh()->document_status->value,
        ];
    }
}
