<?php

declare(strict_types=1);

namespace Tests\GraphQL\Intelligence;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentModel;
use Kanvas\Intelligence\Agents\Models\AgentSwarm;
use Kanvas\Intelligence\Agents\Models\AgentSwarmBudget;
use Kanvas\Intelligence\Agents\Models\AgentSwarmDailyCycle;
use Kanvas\Intelligence\Agents\Models\AgentSwarmMember;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Tests\TestCase;

class SwarmMetricsTest extends TestCase
{
    private function makeAgentInSwarm(AgentSwarm $swarm): Agent
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $type = AgentType::factory()->withAppId($app->getId())->create();
        $model = AgentModel::factory()->withAppId($app->getId())->create();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'agent_type_id' => $type->id,
                'agent_model_id' => $model->id,
                'awake_state' => 'awake',
                'is_active' => true,
            ]);

        AgentSwarmMember::create([
            'agent_swarm_id' => $swarm->getId(),
            'agent_id' => $agent->getId(),
            'role' => 'member',
            'is_deleted' => false,
        ]);

        return $agent;
    }

    private function makeSwarm(): AgentSwarm
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return AgentSwarm::create([
            'uuid' => (string) Str::uuid(),
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'name' => 'Test Swarm ' . uniqid(),
            'slug' => 'test-swarm-' . uniqid(),
            'description' => null,
            'status' => 'active',
            'is_active' => true,
            'is_deleted' => false,
        ]);
    }

    private function seedSnapshotsForAgent(Agent $agent, int $inputTokens, int $outputTokens, float $costUsd, string $date): void
    {
        $machineId = (int) (DB::connection('intelligence')->table('agent_machines')->value('id') ?? 1);

        $deploymentId = DB::connection('intelligence')->table('agent_deployments')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'apps_id' => $agent->apps_id,
            'companies_id' => $agent->companies_id,
            'agent_id' => $agent->getId(),
            'agent_machine_id' => $machineId,
            'system_user' => 'test',
            'home_directory' => '/tmp/test',
            'gateway_port' => 0,
            'proxy_port' => 0,
            'container_name' => 'test-' . uniqid(),
            'provider' => 'anthropic',
            'status' => 'running',
            'launched_at' => $date,
            'is_deleted' => 0,
            'created_at' => $date,
            'updated_at' => $date,
        ]);

        DB::connection('intelligence')->table('agent_usage_snapshots')->insert([
            'uuid' => (string) Str::uuid(),
            'apps_id' => $agent->apps_id,
            'companies_id' => $agent->companies_id,
            'agent_deployment_id' => $deploymentId,
            'snapshot_date' => $date,
            'source' => 'test',
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $outputTokens,
            'cache_read_tokens' => 0,
            'cache_write_tokens' => 0,
            'cost_usd' => $costUsd,
            'provider' => 'anthropic',
            'model' => 'claude-opus-4',
            'total_sessions' => 1,
            'raw_output' => '{}',
            'parsed_data' => '{}',
            'is_deleted' => 0,
            'created_at' => $date,
            'updated_at' => $date,
        ]);
    }

    public function testCostTodaySumsAcrossAllMemberDeploymentsForToday(): void
    {
        $swarm = $this->makeSwarm();
        $a = $this->makeAgentInSwarm($swarm);
        $b = $this->makeAgentInSwarm($swarm);
        $today = Carbon::now()->toDateString();
        $yesterday = Carbon::now()->subDay()->toDateString();

        $this->seedSnapshotsForAgent($a, 10_000, 5_000, 1.25, $today);
        $this->seedSnapshotsForAgent($b, 20_000, 8_000, 2.50, $today);
        // Yesterday's spend must NOT count toward cost_today
        $this->seedSnapshotsForAgent($a, 999_999, 999_999, 999.99, $yesterday);

        $this->graphQL('
            query($id: ID!) {
                agentSwarm(id: $id) { cost_today }
            }
        ', ['id' => (string) $swarm->getId()])
            ->assertSuccessful()
            ->assertJsonPath('data.agentSwarm.cost_today', 3.75);
    }

    public function testCostTodayPrefersTheMaterializedCycleColumnOverLiveSum(): void
    {
        $swarm = $this->makeSwarm();
        $agent = $this->makeAgentInSwarm($swarm);
        $today = Carbon::now()->toDateString();
        // Seed a snapshot with a tiny live cost so we can tell which path ran.
        $this->seedSnapshotsForAgent($agent, 1, 1, 0.01, $today);

        // Now materialize a much larger value on the cycle row. If the
        // resolver reads the cycle column (correct), it returns 42.75; if
        // it falls back to live SUM (wrong), it returns 0.01.
        AgentSwarmDailyCycle::create([
            'apps_id' => $swarm->apps_id,
            'companies_id' => $swarm->companies_id,
            'agent_swarm_id' => $swarm->getId(),
            'cycle_date' => $today,
            'generated_at' => Carbon::now(),
            'members_active_count' => 1,
            'members_idle_count' => 0,
            'events_processed_count' => 0,
            'proactive_actions_count' => 0,
            'cost_usd_today' => 42.75,
            'mission_progress_pct' => null,
            'briefing_text' => 'materialized path under test',
            'is_deleted' => false,
        ]);

        $this->graphQL('
            query($id: ID!) { agentSwarm(id: $id) { cost_today } }
        ', ['id' => (string) $swarm->getId()])
            ->assertSuccessful()
            ->assertJsonPath('data.agentSwarm.cost_today', 42.75);
    }

    public function testCostTodayFallsBackToLiveSumWhenCycleAbsent(): void
    {
        $swarm = $this->makeSwarm();
        $a = $this->makeAgentInSwarm($swarm);
        $today = Carbon::now()->toDateString();
        $this->seedSnapshotsForAgent($a, 1000, 500, 7.25, $today);

        // No cycle row → resolver must compute live.
        $this->graphQL('
            query($id: ID!) { agentSwarm(id: $id) { cost_today } }
        ', ['id' => (string) $swarm->getId()])
            ->assertSuccessful()
            ->assertJsonPath('data.agentSwarm.cost_today', 7.25);
    }

    public function testDailyCycleReturnsTheSeededRow(): void
    {
        $swarm = $this->makeSwarm();
        $today = Carbon::now()->toDateString();

        AgentSwarmDailyCycle::create([
            'apps_id' => $swarm->apps_id,
            'companies_id' => $swarm->companies_id,
            'agent_swarm_id' => $swarm->getId(),
            'cycle_date' => $today,
            'generated_at' => Carbon::now()->setTime(6, 4),
            'members_active_count' => 1,
            'members_idle_count' => 3,
            'events_processed_count' => 142,
            'proactive_actions_count' => 38,
            'mission_progress_pct' => 73.25,
            'progress_delta_since_yesterday' => 4.2,
            'bottleneck_summary' => 'Operator at 92% load',
            'proposed_options' => [
                ['label' => 'Add another Operator', 'eta_hours' => 4.0, 'impact' => 'removes bottleneck'],
            ],
            'emergent_patterns' => [],
            'briefing_text' => "We're at 73% of the objective.",
            'signed_by_text' => '— Test Swarm, in sync',
            'self_improvement_score' => 0.156,
            'is_deleted' => false,
        ]);

        $this->graphQL('
            query($id: ID!) {
                agentSwarm(id: $id) {
                    daily_cycle {
                        cycle_date
                        mission_progress_pct
                        bottleneck_summary
                        briefing_text
                        proposed_options { label eta_hours impact }
                    }
                }
            }
        ', ['id' => (string) $swarm->getId()])
            ->assertSuccessful()
            ->assertJsonPath('data.agentSwarm.daily_cycle.cycle_date', $today)
            ->assertJsonPath('data.agentSwarm.daily_cycle.mission_progress_pct', 73.25)
            ->assertJsonPath('data.agentSwarm.daily_cycle.bottleneck_summary', 'Operator at 92% load')
            ->assertJsonPath('data.agentSwarm.daily_cycle.proposed_options.0.label', 'Add another Operator');
    }

    public function testDailyCycleReturnsNullWhenNoCycleForToday(): void
    {
        $swarm = $this->makeSwarm();

        $this->graphQL('
            query($id: ID!) { agentSwarm(id: $id) { daily_cycle { cycle_date } } }
        ', ['id' => (string) $swarm->getId()])
            ->assertSuccessful()
            ->assertJsonPath('data.agentSwarm.daily_cycle', null);
    }

    public function testBudgetSnapshotMathReflectsCurrentSpend(): void
    {
        $swarm = $this->makeSwarm();
        $agent = $this->makeAgentInSwarm($swarm);
        $today = Carbon::now()->toDateString();
        // Fractional cost so Float serialization can't collapse to int.
        $this->seedSnapshotsForAgent($agent, 100_000, 50_000, 100.50, $today);

        AgentSwarmBudget::create([
            'uuid' => (string) Str::uuid(),
            'apps_id' => $swarm->apps_id,
            'companies_id' => $swarm->companies_id,
            'agent_swarm_id' => $swarm->getId(),
            'period' => 'monthly',
            'monthly_cost_cap_usd' => 500.75,
            'monthly_token_cap' => 1_000_000,
            'monthly_task_cap' => 50,
            'warn_at_pct' => 80,
            'hard_stop_at_cap' => false,
            'period_resets_on' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->graphQL('
            query($id: ID!) {
                agentSwarm(id: $id) {
                    budget {
                        monthly_cost_cap_usd
                        spent_usd
                        remaining_usd
                        tokens_used
                        monthly_token_cap
                        warn_threshold_hit
                        hard_stop_threshold_hit
                    }
                }
            }
        ', ['id' => (string) $swarm->getId()])
            ->assertSuccessful()
            ->assertJsonPath('data.agentSwarm.budget.monthly_cost_cap_usd', 500.75)
            ->assertJsonPath('data.agentSwarm.budget.spent_usd', 100.5)
            ->assertJsonPath('data.agentSwarm.budget.remaining_usd', 400.25)
            ->assertJsonPath('data.agentSwarm.budget.tokens_used', 150000)
            ->assertJsonPath('data.agentSwarm.budget.warn_threshold_hit', false)
            ->assertJsonPath('data.agentSwarm.budget.hard_stop_threshold_hit', false);
    }

    public function testBudgetIsNullWhenNotSet(): void
    {
        $swarm = $this->makeSwarm();

        $this->graphQL('
            query($id: ID!) { agentSwarm(id: $id) { budget { spent_usd } } }
        ', ['id' => (string) $swarm->getId()])
            ->assertSuccessful()
            ->assertJsonPath('data.agentSwarm.budget', null);
    }

    public function testSetAgentSwarmBudgetCreatesAndUpdatesIdempotently(): void
    {
        $swarm = $this->makeSwarm();
        // Use a non-integer USD value so JSON encoding always emits a float
        // (250.0 would serialize as "250" and trip strict assertJsonPath).
        $variables = [
            'swarmId' => (string) $swarm->getId(),
            'input' => [
                'monthly_cost_cap_usd' => 250.75,
                'monthly_token_cap' => 5_000_000,
                'monthly_task_cap' => 100,
                'warn_at_pct' => 75,
                'hard_stop_at_cap' => true,
                'period_resets_on' => 15,
            ],
        ];

        $this->graphQL('
            mutation($swarmId: ID!, $input: SwarmBudgetInput!) {
                setAgentSwarmBudget(swarm_id: $swarmId, input: $input) {
                    monthly_cost_cap_usd
                    monthly_token_cap
                    monthly_task_cap
                    warn_at_pct
                    hard_stop_at_cap
                }
            }
        ', $variables)
            ->assertSuccessful()
            ->assertJsonPath('data.setAgentSwarmBudget.monthly_cost_cap_usd', 250.75)
            ->assertJsonPath('data.setAgentSwarmBudget.warn_at_pct', 75)
            ->assertJsonPath('data.setAgentSwarmBudget.hard_stop_at_cap', true);

        // Re-call must update in place — one row, not two
        $variables['input']['monthly_cost_cap_usd'] = 999.50;
        $this->graphQL('
            mutation($swarmId: ID!, $input: SwarmBudgetInput!) {
                setAgentSwarmBudget(swarm_id: $swarmId, input: $input) {
                    monthly_cost_cap_usd
                }
            }
        ', $variables)
            ->assertSuccessful()
            ->assertJsonPath('data.setAgentSwarmBudget.monthly_cost_cap_usd', 999.5);

        $rows = AgentSwarmBudget::query()
            ->where('agent_swarm_id', $swarm->getId())
            ->where('is_deleted', 0)
            ->count();
        $this->assertSame(1, $rows, 'setAgentSwarmBudget must update in place, never duplicate');
    }

    public function testSetAgentSwarmBudgetRejectsAllCapsNull(): void
    {
        $swarm = $this->makeSwarm();
        $response = $this->graphQL('
            mutation($swarmId: ID!, $input: SwarmBudgetInput!) {
                setAgentSwarmBudget(swarm_id: $swarmId, input: $input) { spent_usd }
            }
        ', [
            'swarmId' => (string) $swarm->getId(),
            'input' => ['warn_at_pct' => 80],
        ]);

        $errors = $response->json('errors');
        $this->assertNotNull($errors);
        $this->assertStringContainsString('at least one cap', (string) ($errors[0]['message'] ?? ''));
    }

    public function testNeedsAttentionFiresWarningWhenAboveWarnThreshold(): void
    {
        $swarm = $this->makeSwarm();
        $agent = $this->makeAgentInSwarm($swarm);
        $today = Carbon::now()->toDateString();
        // Spend $90 against a $100 cap with warn at 80% → warning hit
        $this->seedSnapshotsForAgent($agent, 1000, 500, 90.00, $today);

        AgentSwarmBudget::create([
            'uuid' => (string) Str::uuid(),
            'apps_id' => $swarm->apps_id,
            'companies_id' => $swarm->companies_id,
            'agent_swarm_id' => $swarm->getId(),
            'period' => 'monthly',
            'monthly_cost_cap_usd' => 100.00,
            'monthly_token_cap' => null,
            'monthly_task_cap' => null,
            'warn_at_pct' => 80,
            'hard_stop_at_cap' => false,
            'period_resets_on' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $this->graphQL('
            query($id: ID!) {
                agentSwarm(id: $id) {
                    needs_attention { reason severity message }
                    budget { warn_threshold_hit hard_stop_threshold_hit }
                }
            }
        ', ['id' => (string) $swarm->getId()])
            ->assertSuccessful()
            ->assertJsonPath('data.agentSwarm.budget.warn_threshold_hit', true)
            ->assertJsonPath('data.agentSwarm.budget.hard_stop_threshold_hit', false)
            ->assertJsonPath('data.agentSwarm.needs_attention.reason', 'BUDGET_WARNING')
            ->assertJsonPath('data.agentSwarm.needs_attention.severity', 'warning');
    }

    public function testDeleteSwarmCascadesToMembersBudgetCyclesButLeavesAgentsIntact(): void
    {
        $swarm = $this->makeSwarm();
        $agent = $this->makeAgentInSwarm($swarm);
        $today = Carbon::now()->toDateString();
        $this->seedSnapshotsForAgent($agent, 1000, 500, 5.0, $today);

        AgentSwarmDailyCycle::create([
            'apps_id' => $swarm->apps_id,
            'companies_id' => $swarm->companies_id,
            'agent_swarm_id' => $swarm->getId(),
            'cycle_date' => $today,
            'generated_at' => Carbon::now(),
            'members_active_count' => 1,
            'members_idle_count' => 0,
            'events_processed_count' => 0,
            'proactive_actions_count' => 0,
            'is_deleted' => false,
        ]);

        AgentSwarmBudget::create([
            'uuid' => (string) Str::uuid(),
            'apps_id' => $swarm->apps_id,
            'companies_id' => $swarm->companies_id,
            'agent_swarm_id' => $swarm->getId(),
            'period' => 'monthly',
            'monthly_cost_cap_usd' => 100.00,
            'warn_at_pct' => 80,
            'hard_stop_at_cap' => false,
            'period_resets_on' => 1,
            'is_active' => true,
            'is_deleted' => false,
        ]);

        $memberId = $swarm->members()->first()?->id;
        $cycleId = $swarm->dailyCycles()->first()?->id;
        $budgetId = $swarm->budgets()->first()?->id;
        $agentId = $agent->getId();

        $this->assertNotNull($memberId);
        $this->assertNotNull($cycleId);
        $this->assertNotNull($budgetId);

        // Soft-delete the swarm via the model — the cascade trait fires on the deleting event.
        $swarm->delete();

        // The swarm itself + all owned children should be soft-deleted.
        $this->assertSame(1, (int) DB::connection('intelligence')->table('agent_swarms')->where('id', $swarm->getId())->value('is_deleted'));
        $this->assertSame(1, (int) DB::connection('intelligence')->table('agent_swarm_members')->where('id', $memberId)->value('is_deleted'));
        $this->assertSame(1, (int) DB::connection('intelligence')->table('agent_swarm_daily_cycles')->where('id', $cycleId)->value('is_deleted'));
        $this->assertSame(1, (int) DB::connection('intelligence')->table('agent_swarm_budgets')->where('id', $budgetId)->value('is_deleted'));

        // The agent itself MUST remain intact — agents exist independently.
        $this->assertSame(
            0,
            (int) DB::connection('intelligence')->table('agents')->where('id', $agentId)->value('is_deleted'),
            'Cascade must not touch the agent — only the membership pivot row.',
        );
    }

    public function testCrossTenantSwarmLookupReturnsNull(): void
    {
        // Create a swarm directly in a foreign tenant — should not be visible
        $foreignSwarmId = DB::connection('intelligence')->table('agent_swarms')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'apps_id' => 999_999,
            'companies_id' => 999_999,
            'users_id' => 1,
            'name' => 'Foreign Swarm',
            'slug' => 'foreign-swarm-' . uniqid(),
            'status' => 'active',
            'is_active' => 1,
            'is_deleted' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->graphQL('
            query($id: ID!) { agentSwarm(id: $id) { id name } }
        ', ['id' => (string) $foreignSwarmId])
            ->assertSuccessful()
            ->assertJsonPath('data.agentSwarm', null);
    }
}
