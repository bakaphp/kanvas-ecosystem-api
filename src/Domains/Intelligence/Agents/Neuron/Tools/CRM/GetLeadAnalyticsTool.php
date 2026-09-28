<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Analytics\Actions\BuildAnalyticsAction;
use Kanvas\Analytics\DataTransferObject\AnalyticsGroupBy;
use Kanvas\Analytics\DataTransferObject\AnalyticsRequest;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesAnalyticsTimeframe;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Lead pipeline reporting: how many leads came in over a timeframe, broken down by status, source,
 * pipeline, and salesperson, plus a daily trend. Answers "how many leads did we get last month?",
 * "where are our leads coming from?", "how many leads does each rep have?". Read-only, company-scoped.
 */
#[AgentTool(name: 'Get Lead Analytics', category: 'crm')]
class GetLeadAnalyticsTool extends Tool
{
    use HasKanvasContext;
    use ResolvesAnalyticsTimeframe;

    public function __construct()
    {
        parent::__construct(
            name: 'get_lead_analytics',
            description: 'Lead volume and mix over a timeframe: total leads plus breakdowns by status, source, '
                . 'pipeline, and salesperson, and a daily trend. Use for "how many leads this week?", "which sources '
                . 'drive the most leads?", "lead count per rep". Reporting only.',
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
            model: Lead::class,
            app: $this->app,
            company: $this->company,
            request: AnalyticsRequest::fromGraphQL($args, $this->company),
            groupBys: [
                'by_status' => new AnalyticsGroupBy(column: 'leads_status_id', relation: 'status', labelColumn: 'name'),
                'by_source' => new AnalyticsGroupBy(column: 'leads_sources_id', relation: 'source', labelColumn: 'name'),
                'by_pipeline' => new AnalyticsGroupBy(column: 'pipeline_id', relation: 'pipeline', labelColumn: 'name'),
                'by_salesperson' => new AnalyticsGroupBy(column: 'leads_owner_id', relation: 'owner', labelColumn: 'displayname'),
            ],
            extraScopes: $pipeline_id !== null && $pipeline_id > 0
                ? fn (Builder $q) => $q->where('pipeline_id', $pipeline_id)
                : null,
        )->execute();

        return ['status' => 'success', 'timeframe' => ['from' => $args['from'], 'to' => $args['to']], ...$result];
    }
}
