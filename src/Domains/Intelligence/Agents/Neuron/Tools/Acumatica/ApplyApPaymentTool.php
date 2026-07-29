<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica;

use Kanvas\Connectors\Acumatica\Actions\PushPaymentToAcumaticaAction;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum as AcumaticaCustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Bills\Actions\AllocateBillPaymentAction;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Payments\Enums\PaymentMethodEnum;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use RuntimeException;
use Throwable;

/** Applies a disbursement to an existing, already-pushed AP bill and pushes the payment to Acumatica. */
#[AgentTool(name: 'Apply AP Payment')]
class ApplyApPaymentTool extends Tool
{
    use HasKanvasContext;

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
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'bill_id',
                type: PropertyType::NUMBER,
                description: 'The Kanvas bill id to apply the payment against.',
                required: true,
            ),
            new ToolProperty(
                name: 'amount',
                type: PropertyType::NUMBER,
                description: 'Payment amount. Must not exceed the bill\'s remaining balance.',
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
    public function __invoke(int $bill_id, float $amount, string $reference): array
    {
        $app = $this->app;

        $bill = Bill::query()
            ->where('id', $bill_id)
            ->where('apps_id', $app->getId())
            ->where('companies_id', $this->company->getId())
            ->first();

        if ($bill === null) {
            return [
                'applied' => false,
                'reason' => 'bill_not_found',
                'message' => "No bill with id {$bill_id} for this app/company.",
            ];
        }

        $billRef = (string) $bill->get(AcumaticaCustomFieldEnum::BILL_REF->value, '');

        if ($billRef === '') {
            return [
                'applied' => false,
                'reason' => 'bill_not_pushed',
                'message' => "Bill {$bill_id} hasn't been pushed to Acumatica yet — push it before applying a payment.",
            ];
        }

        try {
            $allocation = new AllocateBillPaymentAction(
                bill: $bill,
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
                'bill_id' => $bill->getId(),
                'reason' => 'push_failed',
                'message' => 'Payment recorded in Kanvas but the push to Acumatica failed: ' . $e->getMessage(),
            ];
        }

        return [
            'applied' => true,
            'pushed' => true,
            'bill_id' => $bill->getId(),
            'bill_ref' => $billRef,
            'amount' => $amount,
            'payment_ref' => $paymentRef,
            'remaining_balance' => (float) $bill->fresh()->balance_due_native,
            'document_status' => $bill->fresh()->document_status->value,
        ];
    }
}
