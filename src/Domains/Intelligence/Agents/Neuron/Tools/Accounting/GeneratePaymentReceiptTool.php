<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\PaymentReceipts\Actions\CreatePaymentReceiptAction;
use Kanvas\Scribe\PaymentReceipts\DataTransferObject\PaymentReceipt as PaymentReceiptData;
use Kanvas\Scribe\PaymentReceipts\Services\PaymentReceiptPdfService;
use Kanvas\Scribe\Payments\Models\Payment;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/** Issues the payment receipt for a confirmed customer payment and renders it as a one-page PDF. */
#[AgentTool(name: 'Generate Payment Receipt', category: 'accounting')]
class GeneratePaymentReceiptTool extends Tool
{
    use HasKanvasContext;
    use TrackByInputs;

    protected string $name = 'generate_payment_receipt';

    protected ?string $description = 'Issues the payment receipt for a customer payment that is confirmed and applied to '
        . 'invoices, and returns it as a PDF. One receipt covers every invoice the payment paid; asking again for the '
        . 'same payment returns the same receipt. Call it right after apply_ar_payment, with the payment_id it returned. '
        . 'It refuses a payment that is not confirmed.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'payment_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas payment_id returned by apply_ar_payment. Never guess it.',
                required: true,
            ),
            new ToolProperty(
                name: 'payer_bank',
                type: PropertyType::STRING,
                description: 'The customer\'s bank, only when the payment or remittance names it.',
                required: false,
            ),
            new ToolProperty(
                name: 'instrument_number',
                type: PropertyType::STRING,
                description: 'The cheque or transfer number, only when the payment or remittance names it.',
                required: false,
            ),
            new ToolProperty(
                name: 'instrument_date',
                type: PropertyType::STRING,
                description: 'The date on the cheque or transfer as YYYY-MM-DD, only when it names one.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $payment_id,
        ?string $payer_bank = null,
        ?string $instrument_number = null,
        ?string $instrument_date = null,
    ): array {
        $user = $this->contextUser();

        if ($user === null) {
            return $this->failure('no_user_context', 'No acting user in scope, so the receipt cannot be stored against this company.');
        }

        $payment = Payment::query()
            ->whereKey($payment_id)
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->notDeleted()
            ->first();

        if ($payment === null) {
            return $this->failure('payment_not_found', "No payment {$payment_id} for this company. Use the payment_id apply_ar_payment returned.");
        }

        try {
            $receipt = new CreatePaymentReceiptAction(
                new PaymentReceiptData(
                    app: $this->app,
                    company: $this->company,
                    user: $user,
                    payment: $payment,
                    payerBank: $payer_bank,
                    instrumentNumber: $instrument_number,
                    instrumentDate: $instrument_date === null ? null : Carbon::parse($instrument_date),
                )
            )->execute();

            $file = $receipt->getFileByName(PaymentReceiptPdfService::FIELD_NAME)?->filesystem
                ?? new PaymentReceiptPdfService($receipt)->generate($user);
        } catch (ValidationException $e) {
            return $this->failure('not_receivable', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->failure(
                'receipt_failed',
                'Could not produce the receipt: ' . $e->getMessage() . ' Asking again for the same payment is safe; its number is not spent twice.'
            );
        }

        return [
            'generated' => true,
            'receipt_id' => $receipt->getId(),
            'receipt_number' => $receipt->receipt_number,
            'customer' => $receipt->customer_name,
            'amount' => $receipt->amount,
            'currency' => $receipt->currency,
            'invoices' => array_column($receipt->invoices, 'invoice_number'),
            'filesystem_id' => $file->getId(),
            'file_url' => $file->url,
            'file_name' => $file->name,
            'message' => 'Receipt issued and its PDF stored. Hand the person a link with get_file_link, never the raw id.',
        ];
    }

    /**
     * @return array{generated: false, reason: string, message: string}
     */
    private function failure(string $reason, string $message): array
    {
        return ['generated' => false, 'reason' => $reason, 'message' => $message];
    }
}
