<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\AgentUsageSnapshot;
use Kanvas\Intelligence\Agents\Services\ModelPricingCalculator;

/**
 * Daily rollup of Neuron/Laravel token usage (recorded per-turn in
 * agent_conversation_messages) into agent_usage_snapshots, so the agent_id-keyed
 * AgentCostService sees in-process backends alongside container runtimes.
 *
 * ADK is excluded — it's metered remotely, so rolling it up here would double-count.
 */
class RollupLocalAgentUsageAction
{
    public function __construct(
        private readonly AppInterface $app,
        private readonly ?Carbon $date = null,
    ) {
    }

    /**
     * @return list<AgentUsageSnapshot>
     */
    public function execute(): array
    {
        $date = $this->date ?? Carbon::now();
        $dayStart = $date->copy()->startOfDay();
        $dayEnd = $date->copy()->startOfDay()->addDay();

        $rows = DB::connection('intelligence')
            ->table('agent_conversation_messages as m')
            ->join('agent_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->join('agents as a', 'a.id', '=', 'c.agent_id')
            ->join('agent_types as t', 't.id', '=', 'a.agent_type_id')
            ->where('c.apps_id', $this->app->getId())
            ->whereIn('t.provider', AgentProviderEnum::localUsageProviderValues())
            ->whereNotNull('c.agent_id')
            ->where('m.created_at', '>=', $dayStart)
            ->where('m.created_at', '<', $dayEnd)
            ->groupBy('c.agent_id', 'c.apps_id', 'c.companies_id', 't.provider')
            ->selectRaw('c.agent_id, c.apps_id, c.companies_id, t.provider as agent_provider')
            // usage key names vary by runtime/version: prompt_tokens/completion_tokens/
            // cache_*_input_tokens OR input_tokens/output_tokens/cache_*. Accept both.
            ->selectRaw("COALESCE(SUM(CAST(COALESCE(JSON_EXTRACT(m.`usage`, '$.prompt_tokens'), JSON_EXTRACT(m.`usage`, '$.input_tokens'), 0) AS UNSIGNED)), 0) as input_tokens")
            ->selectRaw("COALESCE(SUM(CAST(COALESCE(JSON_EXTRACT(m.`usage`, '$.completion_tokens'), JSON_EXTRACT(m.`usage`, '$.output_tokens'), 0) AS UNSIGNED)), 0) as output_tokens")
            ->selectRaw("COALESCE(SUM(CAST(COALESCE(JSON_EXTRACT(m.`usage`, '$.cache_read_input_tokens'), JSON_EXTRACT(m.`usage`, '$.cache_read'), 0) AS UNSIGNED)), 0) as cache_read")
            ->selectRaw("COALESCE(SUM(CAST(COALESCE(JSON_EXTRACT(m.`usage`, '$.cache_write_input_tokens'), JSON_EXTRACT(m.`usage`, '$.cache_write'), 0) AS UNSIGNED)), 0) as cache_write")
            ->selectRaw('COUNT(DISTINCT c.id) as total_sessions')
            ->havingRaw('input_tokens > 0 OR output_tokens > 0')
            ->get();

        $snapshots = [];
        $calculator = app(ModelPricingCalculator::class);

        foreach ($rows as $row) {
            $inputTokens = (int) $row->input_tokens;
            $outputTokens = (int) $row->output_tokens;
            $cacheRead = (int) $row->cache_read;
            $cacheWrite = (int) $row->cache_write;

            $model = $this->dominantModel((int) $row->agent_id, $dayStart, $dayEnd);
            $llmProvider = $model !== null ? ModelPricingCalculator::inferProvider($model) : null;

            $costUsd = $calculator->costFor(
                $llmProvider,
                $model,
                $inputTokens,
                $outputTokens,
                $cacheRead,
                $cacheWrite,
                $date,
            );

            /** @var AgentUsageSnapshot $snapshot */
            $snapshot = AgentUsageSnapshot::updateOrCreate(
                [
                    'apps_id' => (int) $row->apps_id,
                    'companies_id' => (int) $row->companies_id,
                    'agent_id' => (int) $row->agent_id,
                    'agent_deployment_id' => null,
                    'snapshot_date' => $dayStart->toDateString(),
                    'source' => (string) $row->agent_provider,
                ],
                [
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                    'total_tokens' => $inputTokens + $outputTokens,
                    'cache_read_tokens' => $cacheRead,
                    'cache_write_tokens' => $cacheWrite,
                    'cost_usd' => $costUsd,
                    'provider' => $llmProvider,
                    'model' => $model,
                    'total_sessions' => (int) $row->total_sessions,
                    'raw_output' => '',
                    'parsed_data' => null,
                ]
            );

            $snapshots[] = $snapshot;
        }

        return $snapshots;
    }

    /**
     * Most-used model across the agent's messages in the window. The Laravel/Neuron
     * chat paths record it in each message's usage JSON (usage.model). Null → cost 0,
     * tokens still recorded.
     */
    private function dominantModel(int $agentId, Carbon $dayStart, Carbon $dayEnd): ?string
    {
        $row = DB::connection('intelligence')
            ->table('agent_conversation_messages as m')
            ->join('agent_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('c.agent_id', $agentId)
            ->where('m.created_at', '>=', $dayStart)
            ->where('m.created_at', '<', $dayEnd)
            ->whereRaw("JSON_EXTRACT(m.`usage`, '$.model') IS NOT NULL")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(m.`usage`, '$.model')) as model, COUNT(*) as c")
            ->groupBy('model')
            ->orderByDesc('c')
            ->first();

        $model = $row?->model;

        return is_string($model) && $model !== '' && $model !== 'null' ? $model : null;
    }
}
