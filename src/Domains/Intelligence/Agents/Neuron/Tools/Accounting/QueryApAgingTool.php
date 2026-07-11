<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * AP aging — open payables grouped by vendor and bucketed by how far past due they are. The AP-side
 * mirror of the CFO agent's AR aging. Computed directly off open (received, unpaid) bills.
 */
#[AgentTool(name: 'Query AP Aging')]
class QueryApAgingTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'query_ap_aging',
            description: 'Returns AP aging as of a date — open bills grouped by vendor and bucketed by days past '
                . 'due (current/not-yet-due, 1-30, 31-60, 61-90, 90+). Use this when the user asks what we owe, '
                . 'how much is overdue to vendors, or wants a per-vendor payables breakdown to prioritise payment.',
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
                name: 'as_of',
                type: PropertyType::STRING,
                description: 'ISO date (YYYY-MM-DD) — defaults to today.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max vendors to return (ranked by total owed). Defaults to 25.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $as_of = null, ?int $limit = null): array
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $asOf = $as_of !== null ? Carbon::parse($as_of) : Carbon::today();
        $limit = max(1, min(200, $limit ?? 25));

        $bills = Bill::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('document_status', BillDocumentStatusEnum::RECEIVED->value)
            ->where('balance_due_native', '>', 0.005)
            ->where('is_deleted', false)
            ->get();

        $vendors = [];
        $grand = ['current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90_plus' => 0.0, 'total' => 0.0];

        foreach ($bills as $bill) {
            $name = $bill->vendor_display_name ?? 'Unknown vendor';
            $balance = (float) $bill->balance_due_native;
            $bucket = $this->bucket($bill->due_date, $asOf);

            $vendors[$name] ??= ['vendor' => $name, 'current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90_plus' => 0.0, 'total' => 0.0];
            $vendors[$name][$bucket] += $balance;
            $vendors[$name]['total'] += $balance;
            $grand[$bucket] += $balance;
            $grand['total'] += $balance;
        }

        $rows = collect($vendors)
            ->sortByDesc('total')
            ->take($limit)
            ->map(fn (array $r): array => array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $r))
            ->values()
            ->all();

        return [
            'as_of' => $asOf->toDateString(),
            'grand_total' => round($grand['total'], 2),
            'buckets' => array_map(fn ($v) => round($v, 2), $grand),
            'vendor_count' => count($vendors),
            'vendors' => $rows,
        ];
    }

    private function bucket(?Carbon $dueDate, Carbon $asOf): string
    {
        if ($dueDate === null || $dueDate->greaterThanOrEqualTo($asOf)) {
            return 'current';
        }

        $days = (int) $dueDate->diffInDays($asOf);

        return match (true) {
            $days <= 30 => 'd1_30',
            $days <= 60 => 'd31_60',
            $days <= 90 => 'd61_90',
            default => 'd90_plus',
        };
    }
}
