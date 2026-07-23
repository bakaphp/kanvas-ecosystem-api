<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Acumatica\Actions\PushInvoiceToAcumaticaAction;
use Kanvas\Connectors\Acumatica\Actions\PushPaymentToAcumaticaAction;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum as AcumaticaCustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Invoices\Actions\AllocateInvoicePaymentAction;
use Kanvas\Scribe\Invoices\Actions\CreateInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Payments\Enums\PaymentMethodEnum;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Spatie\LaravelData\DataCollection;
use Throwable;

/** Creates a one-line AR invoice, issues it, pushes it to Acumatica, then applies and pushes a cash receipt — the AR mirror of CreateApBillTool. */
#[AgentTool(name: 'Create AR Invoice')]
class CreateArInvoiceTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'create_ar_invoice',
            description: 'Creates a one-line AR invoice for a customer, issues it, pushes it to Acumatica, then '
                . 'applies a cash receipt against it and pushes that too — returning the invoice ref and payment '
                . 'ref. Bypasses the normal human approval gate — use only when the user explicitly asks to '
                . 'create an invoice this way, never on a whim.',
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
                name: 'amount',
                type: PropertyType::NUMBER,
                description: 'The invoice amount, e.g. 1.00. The cash receipt applied afterward is for the same amount.',
                required: true,
            ),
            new ToolProperty(
                name: 'memo',
                type: PropertyType::STRING,
                description: 'Description / memo for the invoice and its single line, e.g. "TEST write-path".',
                required: true,
            ),
            new ToolProperty(
                name: 'customer_name',
                type: PropertyType::STRING,
                description: 'Customer name to match (substring). Omit to use any existing active customer '
                    . 'organization on this app/company — fine for a write-path smoke test.',
                required: false,
            ),
            new ToolProperty(
                name: 'currency',
                type: PropertyType::STRING,
                description: 'Currency code. Defaults to USD.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        float $amount,
        string $memo,
        ?string $customer_name = null,
        ?string $currency = null,
    ): array {
        $app = $this->app;
        $company = $this->company;

        $customerQuery = Organization::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', false);

        if ($customer_name !== null && trim($customer_name) !== '') {
            $customerQuery->where('name', 'like', '%' . trim($customer_name) . '%');
        }

        $customer = $customerQuery->first();

        if ($customer === null) {
            return [
                'created' => false,
                'reason' => 'customer_not_found',
                'message' => $customer_name !== null
                    ? "No customer organization matching \"{$customer_name}\" for this app/company."
                    : 'No active customer organization exists for this app/company yet.',
            ];
        }

        $currency = $currency !== null && trim($currency) !== '' ? strtoupper(trim($currency)) : 'USD';
        $actingUser = $this->user;

        $invoice = new CreateInvoiceAction(
            new InvoiceData(
                app: $app,
                company: $company,
                billable: $customer,
                lines: new DataCollection(InvoiceLineData::class, [
                    new InvoiceLineData(
                        description: $memo,
                        quantity: 1.0,
                        unit_price_native: $amount,
                    ),
                ]),
                currency: $currency,
                fx_rate_to_base: 1.0,
                issued_date: Carbon::today(),
                notes: $memo,
            ),
            $actingUser,
        )->execute();

        $invoice = new IssueInvoiceAction($invoice, $customer, $actingUser)->execute();

        try {
            $invoiceRef = $this->pushInvoiceWithCreditHoldRetry($invoice);
        } catch (AcumaticaWriteException|Throwable $e) {
            return [
                'created' => true,
                'invoice_pushed' => false,
                'invoice_id' => $invoice->getId(),
                'invoice_number' => $invoice->invoice_number,
                'document_status' => $invoice->document_status->value,
                'reason' => 'invoice_push_failed',
                'message' => 'Invoice was created and issued in Kanvas (status: issued) but the push to '
                    . 'Acumatica failed: ' . $e->getMessage() . '. It needs manual attention — it will not '
                    . 'auto-retry.',
            ];
        }

        // Payment application matches against the invoice's own invoice_number.
        $invoice->invoice_number = $invoiceRef;
        $invoice->saveQuietly();

        $allocation = new AllocateInvoicePaymentAction(
            invoice: $invoice,
            amountNative: $amount,
            method: PaymentMethodEnum::CHECK,
            cashAccountSubType: AccountSubTypeEnum::CASH_CHECKING,
            reference: 'TEST-' . $invoiceRef,
            user: $actingUser,
        )->execute();

        $payment = $allocation->payment;

        try {
            $paymentRef = $this->retryOnReleaseDisabled(
                fn (): string => new PushPaymentToAcumaticaAction($payment)->execute(),
            );
        } catch (AcumaticaWriteException|Throwable $e) {
            return [
                'created' => true,
                'invoice_pushed' => true,
                'invoice_ref' => $invoiceRef,
                'payment_pushed' => false,
                'invoice_id' => $invoice->getId(),
                'reason' => 'payment_push_failed',
                'message' => "Invoice pushed to Acumatica (ref {$invoiceRef}) and the cash receipt was recorded "
                    . 'in Kanvas, but pushing the payment to Acumatica failed: ' . $e->getMessage()
                    . '. It needs manual attention — it will not auto-retry.',
            ];
        }

        return [
            'created' => true,
            'invoice_pushed' => true,
            'payment_pushed' => true,
            'invoice_id' => $invoice->getId(),
            'document_status' => $invoice->fresh()->document_status->value,
            'customer' => $customer->name,
            'amount' => $amount,
            'currency' => $currency,
            'memo' => $memo,
            'invoice_ref' => $invoiceRef,
            'acumatica_invoice_id' => (string) $invoice->get(AcumaticaCustomFieldEnum::INVOICE_ID->value, ''),
            'payment_ref' => $paymentRef,
            'acumatica_payment_id' => (string) $payment->get(AcumaticaCustomFieldEnum::PAYMENT_ID->value, ''),
            'next' => 'Invoice and cash receipt both pushed to Acumatica. Use invoice_ref and payment_ref to '
                . 'find the records.',
        ];
    }

    private function pushInvoiceWithCreditHoldRetry(Invoice $invoice): string
    {
        return $this->retryOnReleaseDisabled(
            fn (): string => new PushInvoiceToAcumaticaAction($invoice)->execute(),
        );
    }

    /** Retries a push a few times on "Release button is disabled" — intermittent on this tenant's Credit Hold check. */
    private function retryOnReleaseDisabled(callable $push, int $maxAttempts = 3): string
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $push();
            } catch (AcumaticaWriteException|Throwable $e) {
                $lastException = $e;

                if (! str_contains($e->getMessage(), 'Release button is disabled') || $attempt === $maxAttempts) {
                    throw $e;
                }

                sleep(3);
            }
        }

        throw $lastException;
    }
}
