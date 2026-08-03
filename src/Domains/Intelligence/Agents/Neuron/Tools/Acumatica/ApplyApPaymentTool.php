<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica;

use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum as AcumaticaCustomFieldEnum;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Scribe\Bills\Actions\AllocateBillPaymentAction;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Models\BaseModel;
use Kanvas\Scribe\Payments\Enums\PaymentMethodEnum;
use Kanvas\Scribe\Payments\Models\Payment;
use Override;

/** Applies a disbursement to an existing, already-pushed AP bill and pushes the payment to Acumatica. */
#[AgentTool(name: 'Apply AP Payment', category: 'accounting')]
class ApplyApPaymentTool extends AbstractApplyAcumaticaPaymentTool
{
    public function __construct()
    {
        parent::__construct(
            name: 'apply_ap_payment',
            description: 'Applies a disbursement to an existing AP bill (partial or full) and pushes the '
                . 'payment to Acumatica. Only call when the user explicitly asks to record a real vendor '
                . 'payment against a bill — never on a whim.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $bill_id, float $amount, string $reference): array
    {
        return $this->applyPayment($bill_id, $amount, $reference);
    }

    #[Override]
    protected function noun(): string
    {
        return 'bill';
    }

    #[Override]
    protected function refCustomField(): string
    {
        return AcumaticaCustomFieldEnum::BILL_REF->value;
    }

    #[Override]
    protected function resolveDocument(int $id): ?BaseModel
    {
        return Bill::query()
            ->where('id', $id)
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->first();
    }

    #[Override]
    protected function allocatePayment(BaseModel $document, float $amount, string $reference): Payment
    {
        /** @var Bill $document */
        return new AllocateBillPaymentAction(
            bill: $document,
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
        /** @var Bill $fresh */
        $fresh = $document->fresh();

        return [
            'remaining_balance' => $fresh->balance_due_native,
            'document_status' => $fresh->document_status->value,
        ];
    }
}
