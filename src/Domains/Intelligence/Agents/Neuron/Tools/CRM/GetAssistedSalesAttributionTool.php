<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Analytics\Actions\BuildAssistedSalesAttributionAction;
use Kanvas\Analytics\DataTransferObject\AnalyticsRequest;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesAnalyticsTimeframe;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * "Of the deals we sold last month, how many did the AI touch, and how many had an Action?" The
 * manager-side attribution report; sibling of get_sales_summary (which counts sales but never
 * says who helped). Company-wide, so it belongs to internal agents only.
 */
#[AgentTool(name: 'Get Assisted Sales Attribution', category: 'crm')]
class GetAssistedSalesAttributionTool extends Tool
{
    use HasKanvasContext;
    use ResolvesAnalyticsTimeframe;

    protected string $name = 'get_assisted_sales_attribution';

    protected ?string $description = 'Of the leads marked Sold in a timeframe, how many were assisted by the AI agent (it '
        . 'messaged the customer before the sale) and how many by an Engage action (any action shared with '
        . 'the customer, with the ones they submitted counted separately), plus either/both/neither counts '
        . 'and the per-lead list. Use for "how many sold deals did the AI assist last month?", "how many '
        . 'sales had an action shared?", "how many sales submitted an action?", '
        . '"AI-assisted close rate". Sold leads are counted by their close date, which is only recorded '
        . 'from 2026-10-23 on: a range before that returns nothing, not zero assistance. Reporting only.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'timeframe',
                type: PropertyType::STRING,
                description: 'today, yesterday, last_7_days, or last_30_days (default). Ignored when from/to are given.',
                required: false,
            ),
            new ToolProperty(
                name: 'from',
                type: PropertyType::STRING,
                description: 'Custom range start (YYYY-MM-DD). Requires "to".',
                required: false,
            ),
            new ToolProperty(
                name: 'to',
                type: PropertyType::STRING,
                description: 'Custom range end (YYYY-MM-DD). Requires "from".',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $timeframe = null, ?string $from = null, ?string $to = null): array
    {
        $args = $this->analyticsRangeArgs($timeframe ?? 'last_30_days', $from, $to);

        $result = new BuildAssistedSalesAttributionAction(
            app: $this->app,
            company: $this->company,
            request: AnalyticsRequest::fromGraphQL($args, $this->company),
        )->execute();

        return [
            'status' => 'success',
            'timeframe' => ['from' => $args['from'], 'to' => $args['to']],
            ...$result,
            'note' => $result['note'] ?? 'A sold lead counts as AI-assisted when any AI agent of the company sent the '
                . 'customer a message between the lead\'s creation and its close; as action-assisted when a '
                . 'catalog action was shared with the customer in that same window, and submitted_action counts the '
                . 'ones the customer completed. '
                . 'Close dates exist only from 2026-10-23, so earlier sales are not in "sold" at all.',
        ];
    }
}
