<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\SalesOrders\Kpis\OrderKpiService;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/** The order-to-cash indicators for a period. */
#[AgentTool(name: 'Order KPIs', category: 'accounting')]
class OrderKpisTool extends Tool
{
    use HasKanvasContext;
    use TrackByInputs;

    protected string $name = 'order_kpis';

    protected ?string $description = 'The order-to-cash indicators for a period, each with its target and whether it is met: '
        . 'orders accepted within 72 hours, orders Operations returned, orders right the first time, shipments on time, '
        . 'documents packages on time, perfect deliveries, invoices collected within the term counted from the bill of '
        . 'lading, and the average days to collect. Each order counts in the period of its own event. The hand-off to '
        . 'Operations within 4 hours is not measured. Read-only.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'from',
                type: PropertyType::STRING,
                description: 'First day of the period, as YYYY-MM-DD.',
                required: true,
            ),
            new ToolProperty(
                name: 'to',
                type: PropertyType::STRING,
                description: 'Last day of the period, as YYYY-MM-DD.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $from, string $to): array
    {
        try {
            $start = Carbon::parse($from);
            $end = Carbon::parse($to);
        } catch (Throwable) {
            return ['reason' => 'invalid_period', 'message' => 'Give the period as from and to dates, YYYY-MM-DD.'];
        }

        if ($end->lt($start)) {
            return ['reason' => 'invalid_period', 'message' => 'The end of the period is before its start.'];
        }

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'indicators' => new OrderKpiService()->report($this->app, $this->company, $start, $end),
        ];
    }
}
