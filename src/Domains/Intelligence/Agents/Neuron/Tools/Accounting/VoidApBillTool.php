<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Baka\Support\Str;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Bills\Actions\VoidBillAction;
use Kanvas\Scribe\Bills\Models\Bill;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/** Voids a received AP bill directly in the Kanvas ledger. */
#[AgentTool(name: 'Void AP Bill', category: 'accounting')]
class VoidApBillTool extends Tool
{
    use HasKanvasContext;

    protected string $name = 'void_ap_bill';

    protected ?string $description = 'Voids a received AP bill in the Kanvas ledger and reverses its journal entry. '
        . 'Paid bills cannot be voided. Use only when the user explicitly asks to void the bill.';

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
                description: 'The Kanvas bill id to void (returned as bill_id by create_ap_bill).',
                required: true,
            ),
            new ToolProperty(
                name: 'reason_code',
                type: PropertyType::STRING,
                description: 'Reason for voiding. Defaults to requested_by_user.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $bill_id, ?string $reason_code = null): array
    {
        $app = $this->app;

        $bill = Bill::query()
            ->where('id', $bill_id)
            ->where('apps_id', $app->getId())
            ->where('companies_id', $this->company->getId())
            ->first();

        if ($bill === null) {
            return [
                'voided' => false,
                'reason' => 'bill_not_found',
                'message' => "No bill with id {$bill_id} for this app/company.",
            ];
        }

        try {
            $voidedBill = new VoidBillAction(
                bill: $bill,
                voidReasonCode: Str::trimToNull($reason_code) ?? 'requested_by_user',
                user: $this->user,
            )->execute();
        } catch (Throwable $e) {
            return [
                'voided' => false,
                'bill_id' => $bill->getId(),
                'reason' => 'void_failed',
                'message' => 'Voiding the bill in the Kanvas ledger failed: ' . $e->getMessage(),
            ];
        }

        return [
            'voided' => true,
            'bill_id' => $bill->getId(),
            'document_status' => $voidedBill->document_status->value,
            'next' => 'The bill was voided in the Kanvas ledger and its journal entry was reversed. '
                . 'Any external synchronization is handled by configured workflows.',
        ];
    }
}
