<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Analytics\Actions\BuildAnalyticsAction;
use Kanvas\Analytics\DataTransferObject\AnalyticsGroupBy;
use Kanvas\Analytics\DataTransferObject\AnalyticsRequest;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesAnalyticsTimeframe;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Deal pipeline reporting — the deal-side mirror of get_lead_analytics. How many deals over a
 * timeframe, broken down by status, pipeline, pipeline stage and owner, plus a daily trend.
 * Answers "how many deals did we open last month?", "where are deals stuck in the pipeline?",
 * "how many deals does each rep have?". Read-only, company-scoped.
 */
#[AgentTool(name: 'Get Deal Analytics', category: 'crm')]
class GetDealAnalyticsTool extends Tool
{
    use HasKanvasContext;
    use ResolvesAnalyticsTimeframe;

    public function __construct()
    {
        parent::__construct(
            name: 'get_deal_analytics',
            description: 'Deal volume and mix over a timeframe: total deals plus breakdowns by status, pipeline, '
                . 'pipeline stage, and owner, and a daily trend. Use for "how many deals this month?", "which stage '
                . 'holds the most deals?", "deal count per rep". Reporting only — the deal equivalent of '
                . 'get_lead_analytics.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'timeframe', type: PropertyType::STRING, description: 'today, yesterday, last_7_days (default), or last_30_days. Ignored when from/to are given.', required: false),
            new ToolProperty(name: 'from', type: PropertyType::STRING, description: 'Custom range start (YYYY-MM-DD). Requires "to".', required: false),
            new ToolProperty(name: 'to', type: PropertyType::STRING, description: 'Custom range end (YYYY-MM-DD). Requires "from".', required: false),
            new ToolProperty(name: 'pipeline_id', type: PropertyType::INTEGER, description: 'Restrict to a single pipeline.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $timeframe = null, ?string $from = null, ?string $to = null, ?int $pipeline_id = null): array
    {
        $args = $this->analyticsRangeArgs($timeframe, $from, $to);

        $result = new BuildAnalyticsAction(
            model: Deal::class,
            app: $this->app,
            company: $this->company,
            request: AnalyticsRequest::fromGraphQL($args, $this->company),
            groupBys: [
                'by_status' => new AnalyticsGroupBy(column: 'status_id', relation: 'leadStatus', labelColumn: 'name'),
                'by_pipeline' => new AnalyticsGroupBy(column: 'pipeline_id', relation: 'pipeline', labelColumn: 'name'),
                'by_stage' => new AnalyticsGroupBy(column: 'pipeline_stage_id', relation: 'pipelineStage', labelColumn: 'name'),
                'by_owner' => new AnalyticsGroupBy(column: 'owner_id', relation: 'owner', labelColumn: 'displayname'),
            ],
            extraScopes: $pipeline_id !== null && $pipeline_id > 0
                ? fn (Builder $q) => $q->where('pipeline_id', $pipeline_id)
                : null,
        )->execute();

        return ['status' => 'success', 'timeframe' => ['from' => $args['from'], 'to' => $args['to']], ...$result];
    }
}
