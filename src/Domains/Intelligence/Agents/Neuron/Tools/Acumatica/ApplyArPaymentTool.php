<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica;

use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum as AcumaticaCustomFieldEnum;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Scribe\Invoices\Actions\AllocateInvoicePaymentAction;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Models\BaseModel;
use Kanvas\Scribe\Payments\Enums\PaymentMethodEnum;
use Kanvas\Scribe\Payments\Models\Payment;
use Override;

/** Applies a cash receipt to an existing, already-pushed AR invoice and pushes the payment to Acumatica. */
#[AgentTool(name: 'Apply AR Payment', category: 'accounting')]
class ApplyArPaymentTool extends AbstractApplyAcumaticaPaymentTool
{
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
     * @return array<string, mixed>
     */
    public function __invoke(int $invoice_id, float $amount, string $reference): array
    {
        return $this->applyPayment($invoice_id, $amount, $reference);
    }

    #[Override]
    protected function noun(): string
    {
        return 'invoice';
    }

    #[Override]
    protected function refCustomField(): string
    {
        return AcumaticaCustomFieldEnum::INVOICE_REF->value;
    }

    #[Override]
    protected function resolveDocument(int $id): ?BaseModel
    {
        return Invoice::query()
            ->where('id', $id)
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->first();
    }

    #[Override]
    protected function allocatePayment(BaseModel $document, float $amount, string $reference): Payment
    {
        /** @var Invoice $document */
        return new AllocateInvoicePaymentAction(
            invoice: $document,
            amountNative: $amount,
            method: PaymentMethodEnum::CHECK,
            cashAccountSubType: AccountSubTypeEnum::CASH_CHECKING,
            reference: $reference,
            user: $this->user,
        )->execute()->payment;
    }

    /**
     * @return array{remaining_balance: float, document_status: string}
     */
    #[Override]
    protected function refreshedState(BaseModel $document): array
    {
        /** @var Invoice $fresh */
        $fresh = $document->fresh();

        return [
            'remaining_balance' => $fresh->balance_due_native,
            'document_status' => $fresh->document_status->value,
        ];
    }
}
