<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use Kanvas\Scribe\Banking\Services\SuspenseQueueService;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'List Suspense', category: 'accounting')]
class ListSuspenseTool extends Tool
{
    use HasKanvasContext;

    protected string $name = 'list_suspense';

    protected ?string $description = 'Lists bank movements parked in Suspense: the cash is booked, but nobody has said what '
        . 'the other side is. Each row has its bank_transaction_id, date, direction, amount, counterparty, memo '
        . 'and any candidate documents the matcher considered. Work them with settle_from_suspense (money out '
        . 'that paid a bill entered later) or reclassify_suspense (a bank fee, a withholding, a transfer '
        . 'between our own accounts, an expense with no bill).';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'direction',
                type: PropertyType::STRING,
                description: '"debit" for money out, "credit" for money in. Omit for both.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max rows, oldest first. Defaults to 25, max 100.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $direction = null, ?int $limit = null): array
    {
        $direction = in_array($direction, ['debit', 'credit'], true) ? $direction : null;
        $query = new SuspenseQueueService()->parked($this->app, $this->company)
            ->when($direction !== null, fn ($q) => $q->where('direction', $direction));

        $total = (clone $query)->count();
        $totalParked = round((float) (clone $query)->sum('amount_native'), 2);
        $rows = $query->with('bankAccount')
            ->orderBy('transaction_date')
            ->limit(max(1, min(100, $limit ?? 25)))
            ->get();

        return [
            'count' => $total,
            'total_parked_native' => $totalParked,
            'movements' => $rows->map(fn (BankTransaction $t): array => [
                'bank_transaction_id' => $t->getId(),
                'date' => $t->transaction_date->toDateString(),
                'direction' => $t->direction->value,
                'amount' => $t->amount_native,
                'currency' => $t->currency,
                'counterparty' => $t->counterparty_name,
                'memo' => $t->memo,
                'bank_account' => $t->bankAccount?->account_name,
                'candidates' => $t->metadata['match_candidates'] ?? [],
            ])->all(),
            'note' => $total === 0 ? 'Suspense is empty: every movement is explained. Do not call again this turn.' : null,
        ];
    }
}
