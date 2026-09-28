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
 * Sales/deal reporting: how many deals over a timeframe, broken down by pipeline stage, status, and
 * salesperson, with a daily trend. Answers "how are deals moving this month?", "how many deals per
 * stage?", "which rep is closing the most?". Read-only, company-scoped.
 */
#[AgentTool(name: 'Get Sales Summary', category: 'crm')]
class GetSalesSummaryTool extends Tool
{
    use HasKanvasContext;
    use ResolvesAnalyticsTimeframe;

    public function __construct()
    {
        parent::__construct(
            name: 'get_sales_summary',
            description: 'Deal pipeline performance over a timeframe: total deals plus breakdowns by stage, status, '
                . 'and salesperson, and a daily trend. Use for "how are sales this month?", "deals per stage", "who '
                . 'is closing the most deals?". Reporting only.',
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
                'by_stage' => new AnalyticsGroupBy(column: 'pipeline_stage_id', relation: 'pipelineStage', labelColumn: 'name'),
                'by_status' => new AnalyticsGroupBy(column: 'status_id', relation: 'leadStatus', labelColumn: 'name'),
                'by_salesperson' => new AnalyticsGroupBy(column: 'owner_id', relation: 'owner', labelColumn: 'displayname'),
            ],
            extraScopes: $pipeline_id !== null && $pipeline_id > 0
                ? fn (Builder $q) => $q->where('pipeline_id', $pipeline_id)
                : null,
        )->execute();

        return ['status' => 'success', 'timeframe' => ['from' => $args['from'], 'to' => $args['to']], ...$result];
    }
}
