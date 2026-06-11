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
 * Daily usage rollup for in-process backends (Neuron, Laravel) that have no
 * deployment row. Their per-turn token usage is written into
 * agent_conversation_messages.usage; this sums a day's worth per agent and
 * writes one agent_usage_snapshot (agent_deployment_id = null), so the unified
 * agent_id-keyed read in AgentCostService sees them alongside container runtimes.
 *
 * Only neuron/laravel are rolled up here. Hermes/OpenClaw are container runtimes
 * collected via collectUsage() into snapshots already, and ADK is remote — folding
 * any of them in would double-count.
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
            ->selectRaw("COALESCE(SUM(CAST(COALESCE(JSON_EXTRACT(m.`usage`, '$.prompt_tokens'), 0) AS UNSIGNED)), 0) as input_tokens")
            ->selectRaw("COALESCE(SUM(CAST(COALESCE(JSON_EXTRACT(m.`usage`, '$.completion_tokens'), 0) AS UNSIGNED)), 0) as output_tokens")
            ->selectRaw("COALESCE(SUM(CAST(COALESCE(JSON_EXTRACT(m.`usage`, '$.cache_read_input_tokens'), 0) AS UNSIGNED)), 0) as cache_read")
            ->selectRaw("COALESCE(SUM(CAST(COALESCE(JSON_EXTRACT(m.`usage`, '$.cache_write_input_tokens'), 0) AS UNSIGNED)), 0) as cache_write")
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
     * Most-used model across this agent's conversations in the window, read from
     * the conversation's runtime meta. Drives pricing; null when meta carries no
     * model (cost falls through to 0, tokens are still recorded).
     */
    private function dominantModel(int $agentId, Carbon $dayStart, Carbon $dayEnd): ?string
    {
        $row = DB::connection('intelligence')
            ->table('agent_conversations')
            ->where('agent_id', $agentId)
            ->where('updated_at', '>=', $dayStart)
            ->where('updated_at', '<', $dayEnd)
            ->whereNotNull('meta')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.model')) as model, COUNT(*) as c")
            ->groupBy('model')
            ->orderByDesc('c')
            ->first();

        $model = $row?->model;

        return is_string($model) && $model !== '' && $model !== 'null' ? $model : null;
    }
}
