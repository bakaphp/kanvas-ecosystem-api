<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\AgentSwarm;
use Kanvas\Intelligence\Agents\Models\AgentSwarmBudget;
use Kanvas\Intelligence\Agents\Models\AgentSwarmDailyCycle;

class SeedSwarmDemoDataCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * Frontend-integration dummy data so the dashboard renders real-looking
     * numbers against the new swarm cost/budget/cycle GraphQL surface. Safe
     * to re-run — UPSERTs everything on (swarm_id, date) / (swarm_id) keys.
     */
    protected $signature = 'nervous-system:seed-swarm-demo-data
        {--swarm-id=1 : ID of the swarm to seed against}
        {--days=30 : How many days of historical usage snapshots to create}';

    protected $description = 'Seed dummy cost / budget / daily-cycle data for a swarm — used to unblock frontend integration on the dashboard.';

    public function handle(): int
    {
        $swarmId = (int) $this->option('swarm-id');
        $days = (int) $this->option('days');

        /** @var AgentSwarm|null $swarm */
        $swarm = AgentSwarm::query()->where('id', $swarmId)->first();
        if ($swarm === null) {
            $this->error("Swarm #{$swarmId} not found.");

            return self::FAILURE;
        }

        $this->info("Seeding demo data for swarm #{$swarm->getId()} '{$swarm->name}' (app {$swarm->apps_id}, company {$swarm->companies_id})");

        /** @var Apps $app */
        $app = Apps::getById((int) $swarm->apps_id);
        $this->overwriteAppService($app);

        $this->seedModelPricing();
        $deploymentIds = $this->memberDeploymentIds($swarm->getId());
        if ($deploymentIds === []) {
            $this->warn('  ⚠ Swarm has no member deployments — usage snapshots skipped. Cost will read 0.');
        } else {
            $this->seedUsageSnapshots($swarm, $deploymentIds, $days);
        }
        $this->seedBudget($swarm);
        $this->seedDailyCycle($swarm);

        $this->info('Done. Try:');
        $this->line('  query { agentSwarm(id: "' . $swarm->getId() . '") { cost_today budget { spent_usd remaining_usd tokens_used } daily_cycle { briefing_text } } }');

        return self::SUCCESS;
    }

    private function seedModelPricing(): void
    {
        // Current Anthropic / OpenAI / Groq rates (per million tokens, USD).
        // Numbers reflect public pricing as of 2026-05; LiteLLM sync will
        // override / version these once that command lands.
        $rows = [
            ['anthropic', 'claude-opus-4',     15.00,  75.00, 1.50, 18.75],
            ['anthropic', 'claude-sonnet-4',    3.00,  15.00, 0.30,  3.75],
            ['anthropic', 'claude-haiku-4-5',   0.80,   4.00, 0.08,  1.00],
            ['openai',    'gpt-5-large',        5.00,  15.00, 0.50,  null],
            ['openai',    'gpt-5-mini',         0.15,   0.60, 0.015, null],
            ['groq',      'llama-3.3-70b',      0.05,   0.05, null,  null],
        ];

        $effectiveFrom = Carbon::now()->subYear()->toDateString();
        foreach ($rows as [$provider, $model, $in, $out, $cacheRead, $cacheWrite]) {
            DB::connection('intelligence')->table('model_pricing')->updateOrInsert(
                [
                    'provider' => $provider,
                    'model' => $model,
                    'effective_from' => $effectiveFrom,
                ],
                [
                    'input_per_million' => $in,
                    'output_per_million' => $out,
                    'cache_read_per_million' => $cacheRead,
                    'cache_write_per_million' => $cacheWrite,
                    'effective_until' => null,
                    'source' => 'manual',
                    'is_deleted' => 0,
                    'updated_at' => Carbon::now(),
                    'created_at' => Carbon::now(),
                ],
            );
        }
        $this->line('  ✓ model_pricing seeded (' . count($rows) . ' rows)');
    }

    /**
     * @return array<int, int>
     */
    private function memberDeploymentIds(int $swarmId): array
    {
        return DB::connection('intelligence')
            ->table('agent_swarm_members as m')
            ->join('agent_deployments as d', 'd.agent_id', '=', 'm.agent_id')
            ->where('m.agent_swarm_id', $swarmId)
            ->where('m.is_deleted', 0)
            ->where('d.is_deleted', 0)
            ->pluck('d.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param array<int, int> $deploymentIds
     */
    private function seedUsageSnapshots(AgentSwarm $swarm, array $deploymentIds, int $days): void
    {
        $model = 'claude-opus-4';
        $provider = 'anthropic';
        $inputRate = 15.00;
        $outputRate = 75.00;

        $count = 0;
        for ($d = 0; $d < $days; $d++) {
            $date = Carbon::now()->subDays($d)->toDateString();
            foreach ($deploymentIds as $depId) {
                // Reproducible-ish per (deployment, date) so re-runs don't drift wildly.
                $seed = crc32($depId . $date);
                $sessions = 3 + ($seed % 8);                 // 3-10
                $inputTokens = 8_000 + ($seed % 25_000);     // 8k-33k
                $outputTokens = 4_000 + (($seed >> 4) % 18_000); // 4k-22k
                $totalTokens = $inputTokens + $outputTokens;
                $cost = round(
                    ($inputTokens / 1_000_000) * $inputRate
                    + ($outputTokens / 1_000_000) * $outputRate,
                    6,
                );

                DB::connection('intelligence')->table('agent_usage_snapshots')->updateOrInsert(
                    [
                        'agent_deployment_id' => $depId,
                        'snapshot_date' => $date,
                    ],
                    [
                        'uuid' => (string) Str::uuid(),
                        'apps_id' => $swarm->apps_id,
                        'companies_id' => $swarm->companies_id,
                        'source' => 'demo-seed',
                        'input_tokens' => $inputTokens,
                        'output_tokens' => $outputTokens,
                        'total_tokens' => $totalTokens,
                        'cache_read_tokens' => 0,
                        'cache_write_tokens' => 0,
                        'cost_usd' => $cost,
                        'provider' => $provider,
                        'model' => $model,
                        'total_sessions' => $sessions,
                        'raw_output' => '{}',
                        'parsed_data' => '{}',
                        'is_deleted' => 0,
                        'updated_at' => Carbon::now(),
                        'created_at' => Carbon::now(),
                    ],
                );
                $count++;
            }
        }
        $this->line('  ✓ agent_usage_snapshots seeded (' . $count . ' rows over ' . $days . ' days × ' . count($deploymentIds) . ' deployments)');
    }

    private function seedBudget(AgentSwarm $swarm): void
    {
        AgentSwarmBudget::updateOrCreate(
            [
                'agent_swarm_id' => $swarm->getId(),
                'period' => 'monthly',
                'is_deleted' => 0,
            ],
            [
                'apps_id' => $swarm->apps_id,
                'companies_id' => $swarm->companies_id,
                'monthly_cost_cap_usd' => 500.00,
                'monthly_token_cap' => 10_000_000,
                'monthly_task_cap' => 200,
                'hard_stop_at_cap' => false,
                'warn_at_pct' => 80,
                'period_resets_on' => 1,
                'is_active' => true,
            ],
        );
        $this->line('  ✓ budget seeded ($500/mo, 10M tokens, 200 tasks, warn at 80%)');
    }

    private function seedDailyCycle(AgentSwarm $swarm): void
    {
        $today = Carbon::now()->toDateString();
        // Materialize today's cost rollup directly from the seeded snapshots
        // so the SwarmMetricsQuery::costToday resolver hits the cycle row
        // (the materialized path) instead of falling back to live SUM.
        $costToday = app(\Kanvas\Intelligence\Agents\Services\SwarmCostService::class)->costToday($swarm);

        AgentSwarmDailyCycle::updateOrCreate(
            [
                'agent_swarm_id' => $swarm->getId(),
                'cycle_date' => $today,
            ],
            [
                'apps_id' => $swarm->apps_id,
                'companies_id' => $swarm->companies_id,
                'generated_at' => Carbon::now()->setTime(6, 4),
                'members_active_count' => 1,
                'members_idle_count' => 3,
                'events_processed_count' => 142,
                'proactive_actions_count' => 38,
                'cost_usd_today' => $costToday,
                'mission_progress_pct' => 73.00,
                'progress_delta_since_yesterday' => 4.20,
                'bottleneck_summary' => 'Operator is at 92% load and gating 38% of execute-stage flow.',
                'proposed_options' => [
                    ['label' => 'Add a second Operator instance', 'eta_hours' => 4.0, 'impact' => 'removes bottleneck'],
                    ['label' => 'Reroute 30% of low-priority items directly to Concierge fast-path', 'eta_hours' => 1.0, 'impact' => 'partial relief'],
                    ['label' => 'Accept the bottleneck — still on track to finish 0.4d early', 'eta_hours' => 0.0, 'impact' => 'no action needed'],
                ],
                'emergent_patterns' => [
                    ['name' => 'cold-lead re-engagement timing', 'confidence' => 0.84, 'surfaced_in_member_count' => 2],
                    ['name' => 'objection routing by signal strength', 'confidence' => 0.71, 'surfaced_in_member_count' => 3],
                ],
                'briefing_text' => "We're at 73% of the objective with 9 days remaining. At current velocity (26 qualifications/day) we'll cross the line 2 days early. Convergence is high (0.84) — the circuit is coherent. One concern: Operator is at 92% load and gating 38% of execute-stage flow.",
                'signed_by_text' => '— ' . $swarm->name . ', in sync',
                'self_improvement_score' => 0.156,
                'is_deleted' => false,
            ],
        );
        $this->line('  ✓ daily_cycle seeded for ' . $today);
    }
}
