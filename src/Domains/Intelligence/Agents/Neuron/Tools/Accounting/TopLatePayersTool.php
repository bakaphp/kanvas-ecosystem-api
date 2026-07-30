<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Reports\Repositories\ArAgingRepository;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Top Late Payers')]
class TopLatePayersTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'top_late_payers',
            description: 'Returns the N customers with the highest TOTAL overdue balance (any bucket from 1-30 '
                . 'through 90+). Use this when the user asks "who are our biggest late payers", wants a customer '
                . 'collection priority list, or asks "which customer do we need to chase first".',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'How many top late-payers to return. Defaults to 10, max 50.',
                required: false,
            ),
        ];
    }

    public function __invoke(?int $limit = null): array
    {
        $app = $this->app;
        $company = $this->company;
        $limit = max(1, min(50, $limit ?? 10));

        $aging = new ArAgingRepository()->generate(
            app: $app,
            company: $company,
            asOf: Carbon::today(),
        );

        $rows = collect($aging->rows->toArray())
            ->filter(fn (array $r): bool => (
                $r['bucket_1_30']
                + $r['bucket_31_60']
                + $r['bucket_61_90']
                + $r['bucket_90_plus']
            ) > 0.005)
            ->map(fn (array $r): array => [
                'customer_name' => $r['customer_name'],
                'customer_type' => $r['customer_type'],
                'customer_id' => $r['customer_id'],
                'total_overdue' => $r['bucket_1_30'] + $r['bucket_31_60'] + $r['bucket_61_90'] + $r['bucket_90_plus'],
                'bucket_1_30' => $r['bucket_1_30'],
                'bucket_31_60' => $r['bucket_31_60'],
                'bucket_61_90' => $r['bucket_61_90'],
                'bucket_90_plus' => $r['bucket_90_plus'],
                'grand_total' => $r['total'],
            ])
            ->sortByDesc('total_overdue')
            ->take($limit)
            ->values()
            ->all();

        return [
            'as_of' => $aging->as_of->toDateString(),
            'currency' => $aging->currency,
            'count' => count($rows),
            'customers' => $rows,
        ];
    }
}
