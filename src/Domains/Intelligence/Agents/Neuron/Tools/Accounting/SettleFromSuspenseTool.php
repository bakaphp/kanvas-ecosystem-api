<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesBankingRecordsForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\Banking\Actions\SettleBillFromSuspenseAction;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use RuntimeException;

#[AgentTool(name: 'Settle Bill From Suspense', category: 'accounting')]
class SettleFromSuspenseTool extends Tool
{
    use HasKanvasContext;
    use ReportsToolOutcome;
    use ResolvesBankingRecordsForTool;
    use TrackByInputs;

    protected string $name = 'settle_from_suspense';

    protected ?string $description = 'Pays a bill with money that ALREADY left the bank and is parked in Suspense (the '
        . 'statement arrived before the vendor\'s invoice was entered). Receives the bill if it is still a draft, '
        . 'marks it paid for the movement\'s amount and drains Suspense — cash is not credited a second time. '
        . 'Use it instead of record_bill_payment whenever the payment is already a movement on list_suspense.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'bank_transaction_id',
                type: PropertyType::INTEGER,
                description: 'The parked money-out movement, from list_suspense.',
                required: true,
            ),
            new ToolProperty(
                name: 'bill_id',
                type: PropertyType::INTEGER,
                description: 'The bill that movement paid, from find_bill or list_open_bills.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $bank_transaction_id, int $bill_id): array
    {
        $transaction = $this->resolveBankTransactionOrError($bank_transaction_id);
        if (is_array($transaction)) {
            return $transaction;
        }

        $bill = $this->resolveBillOrError($bill_id);
        if (is_array($bill)) {
            return $bill;
        }

        if ($bill->vendor === null) {
            return $this->invalidArgs(
                "Bill {$bill->bill_number} has no vendor, so it cannot be received.",
                ['settled' => false],
                'Set the vendor on the bill first.',
            );
        }

        try {
            $settled = new SettleBillFromSuspenseAction(
                bankTransaction: $transaction,
                bill: $bill,
                vendor: $bill->vendor,
                user: $this->user,
            )->execute();
        } catch (RuntimeException $e) {
            return $this->invalidArgs($e->getMessage(), ['settled' => false], 'Nothing was settled.');
        }

        return $this->ok([
            'settled' => true,
            'bill_id' => $settled->getId(),
            'bill_number' => $settled->bill_number,
            'bill_status' => $settled->document_status->value,
            'balance_due' => (float) $settled->balance_due_native,
            'bank_transaction_id' => $transaction->getId(),
        ]);
    }
}
