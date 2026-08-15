<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\FindsTenantRecordForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\Subaccount;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Looks up ONE bill by its number — status, vendor, amounts (total / paid / balance), dates, and
 * every line with GL coding. The "pull up bill #X" lookup (vs list_open_bills which enumerates).
 * Reads synced data; reports found=false when the bill isn't in Kanvas.
 */
#[AgentTool(name: 'Find Bill', category: 'accounting')]
class FindBillTool extends Tool implements HasRunKey
{
    use FindsTenantRecordForTool;
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'find_bill',
            description: 'Look up a single bill by its number and return the full detail: vendor, document status '
                . '(draft/pending_approval/received/paid/voided), total, amount paid, balance due, dates and every '
                . 'line with GL account + subaccount. Use this when the user names a specific bill number. Returns '
                . 'found=false when the bill is not in the synced data.',
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
                name: 'bill_number',
                type: PropertyType::STRING,
                description: 'The bill number to look up.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $bill_number): array
    {
        $result = $this->findTenantRecordOrNotFound(
            Bill::class,
            'bill_number',
            $bill_number,
            'bill'
        );

        if (is_array($result)) {
            return $result;
        }

        /** @var Bill $bill */
        $bill = $result;

        return [
            'found' => true,
            'bill_number' => $bill->bill_number,
            'vendor' => $bill->vendor_display_name,
            'document_status' => $bill->document_status->value,
            'collection_state' => $bill->collection_state?->value,
            'currency' => $bill->currency,
            'total_native' => (float) $bill->total_native,
            'paid_native' => (float) $bill->paid_native,
            'balance_due_native' => (float) $bill->balance_due_native,
            'bill_date' => $bill->bill_date?->toDateString(),
            'due_date' => $bill->due_date?->toDateString(),
            'lines' => $bill->lines()->orderBy('sort_order')->get()->map(fn ($l): array => [
                'description' => $l->description,
                'quantity' => (float) $l->quantity,
                'unit_price_native' => (float) $l->unit_price_native,
                'gl_account' => $this->code(Account::class, $l->expense_account_id, 'account_number'),
                'subaccount' => $this->code(Subaccount::class, $l->subaccount_id, 'sub_code'),
            ])->all(),
        ];
    }

    /**
     * @param class-string $model
     */
    private function code(string $model, ?int $id, string $column): ?string
    {
        if ($id === null) {
            return null;
        }

        $value = $model::query()->where('id', $id)->value($column);

        return $value !== null ? (string) $value : null;
    }
}
