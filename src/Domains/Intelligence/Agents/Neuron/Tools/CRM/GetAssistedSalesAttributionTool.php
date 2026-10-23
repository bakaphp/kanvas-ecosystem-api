<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Baka\Support\Str;
use Kanvas\Analytics\Actions\BuildAssistedSalesAttributionAction;
use Kanvas\Analytics\DataTransferObject\AnalyticsRequest;
use Kanvas\Analytics\Enums\AttributionPopulationEnum;
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

    protected ?string $description = 'Which leads the AI agent assisted (it messaged the customer) and which went through an '
        . 'Engage action (any action shared with the customer; submitted ones counted separately), with '
        . 'either/both/neither counts and the per-lead rows. population=sold (default) takes the leads '
        . 'marked Sold in the timeframe by close date: "how many sold deals did the AI assist last month?", '
        . '"how many sales had an action shared?". population=all or open takes the leads created in the '
        . 'timeframe and adds an outcomes block comparing the sold rate of assisted vs unassisted leads: '
        . '"what is the impact of the AI / of actions?", "which open leads has the AI talked to?". Close '
        . 'dates are only recorded from 2026-10-23 on, so population=sold over an earlier range returns '
        . 'nothing, not zero assistance. Reporting only.';

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
            new ToolProperty(
                name: 'population',
                type: PropertyType::STRING,
                description: 'sold (default): leads closed as Sold in the timeframe. all: every lead created in the '
                    . 'timeframe, any status. open: leads created in the timeframe that are still open. all and '
                    . 'open add the outcomes block (sold rate of assisted vs unassisted).',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $timeframe = null,
        ?string $from = null,
        ?string $to = null,
        ?string $population = null
    ): array {
        $populationEnum = AttributionPopulationEnum::tryFrom(Str::lowerTrim($population) ?: 'sold');
        if ($populationEnum === null) {
            return [
                'status' => 'error',
                'message' => 'Invalid population. Use one of: ' . implode(', ', AttributionPopulationEnum::values()) . '.',
            ];
        }

        $args = $this->analyticsRangeArgs($timeframe ?? 'last_30_days', $from, $to);

        $result = new BuildAssistedSalesAttributionAction(
            app: $this->app,
            company: $this->company,
            request: AnalyticsRequest::fromGraphQL($args, $this->company),
            population: $populationEnum,
        )->execute();

        return [
            'status' => 'success',
            'timeframe' => ['from' => $args['from'], 'to' => $args['to']],
            ...$result,
            'note' => $result['note'] ?? 'A lead counts as AI-assisted when any AI agent of the company sent the '
                . 'customer a message between the lead\'s creation and its close (or now, while open); as '
                . 'action-assisted when a catalog action was shared with the customer in that same window, and '
                . 'submitted_action counts the ones the customer completed. With population=all or open, '
                . 'outcomes compares the sold rate of assisted and unassisted leads: that difference is the impact. '
                . 'Close dates exist only from 2026-10-23, so earlier sales are not in population=sold at all.',
        ];
    }
}
