<?php

declare(strict_types=1);

namespace Tests\Intelligence\Services;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\AgentCostService;
use Tests\TestCase;

class AgentCostServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private function makeAgent(): Agent
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();
    }

    private function makeDeployment(Agent $agent): int
    {
        return (int) DB::connection('intelligence')->table('agent_deployments')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'apps_id' => $agent->apps_id,
            'companies_id' => $agent->companies_id,
            'agent_id' => $agent->getId(),
            'agent_machine_id' => 99999,
            'system_user' => 'agent_test_' . Str::random(6),
            'home_directory' => '/home/agent_test',
            'gateway_port' => random_int(20000, 60000),
            'proxy_port' => random_int(20000, 60000),
            'container_name' => 'agent_test_' . Str::random(6),
            'provider' => 'openclaw',
            'status' => 'running',
            'is_deleted' => 0,
            'created_at' => Carbon::now(),
        ]);
    }

    private function makeSnapshot(Agent $agent, int $deploymentId, string $date, int $input, int $output, float $cost): void
    {
        DB::connection('intelligence')->table('agent_usage_snapshots')->insert([
            'uuid' => (string) Str::uuid(),
            'apps_id' => $agent->apps_id,
            'companies_id' => $agent->companies_id,
            'agent_deployment_id' => $deploymentId,
            'snapshot_date' => $date,
            'source' => 'openclaw_docker',
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $input + $output,
            'cost_usd' => $cost,
            'is_deleted' => 0,
            'raw_output' => '{}',
            'created_at' => Carbon::now(),
        ]);
    }

    public function testSumsTokensAndCostForCurrentMonth(): void
    {
        $now = Carbon::create(2026, 6, 15, 12);
        Carbon::setTestNow($now);

        $agent = $this->makeAgent();
        $deployment = $this->makeDeployment($agent);

        $this->makeSnapshot($agent, $deployment, '2026-06-01', 1000, 500, 1.50);
        $this->makeSnapshot($agent, $deployment, '2026-06-10', 2000, 1000, 3.25);
        // Previous month — must be excluded.
        $this->makeSnapshot($agent, $deployment, '2026-05-31', 9999, 9999, 99.99);

        $usage = app(AgentCostService::class)->usageForMonth($agent);

        $this->assertSame('2026-06-01', $usage['period_start']);
        $this->assertSame(4500, $usage['tokens']);
        $this->assertEqualsWithDelta(4.75, $usage['cost_usd'], 0.001);

        Carbon::setTestNow();
    }

    public function testAggregatesAcrossMultipleDeployments(): void
    {
        $now = Carbon::create(2026, 6, 15, 12);
        Carbon::setTestNow($now);

        $agent = $this->makeAgent();
        $depA = $this->makeDeployment($agent);
        $depB = $this->makeDeployment($agent);

        $this->makeSnapshot($agent, $depA, '2026-06-02', 1000, 0, 1.00);
        $this->makeSnapshot($agent, $depB, '2026-06-03', 0, 2000, 2.00);

        $usage = app(AgentCostService::class)->usageForMonth($agent);

        $this->assertSame(3000, $usage['tokens']);
        $this->assertEqualsWithDelta(3.00, $usage['cost_usd'], 0.001);

        Carbon::setTestNow();
    }

    public function testReturnsZeroWhenNoSnapshots(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 12));

        $agent = $this->makeAgent();

        $usage = app(AgentCostService::class)->usageForMonth($agent);

        $this->assertSame(0, $usage['tokens']);
        $this->assertSame(0.0, $usage['cost_usd']);

        Carbon::setTestNow();
    }

    public function testMonthlyUsageResolvesThroughGraphQL(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 15, 12));

        $agent = $this->makeAgent();
        $deployment = $this->makeDeployment($agent);
        $this->makeSnapshot($agent, $deployment, '2026-06-04', 1000, 500, 2.50);

        $this->graphQL('
            query ($where: QueryAgentsAiWhereWhereConditions) {
                agentsAi(where: $where) {
                    data {
                        id
                        monthly_usage {
                            period_start
                            tokens
                            cost_usd
                        }
                    }
                }
            }
        ', ['where' => ['column' => 'ID', 'operator' => 'EQ', 'value' => $agent->getId()]])
            ->assertSuccessful()
            ->assertJson([
                'data' => [
                    'agentsAi' => [
                        'data' => [
                            [
                                'id' => (string) $agent->getId(),
                                'monthly_usage' => [
                                    'period_start' => '2026-06-01',
                                    'tokens' => 1500,
                                    'cost_usd' => 2.5,
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        Carbon::setTestNow();
    }

    public function testResultIsCachedWithinTtl(): void
    {
        $now = Carbon::create(2026, 6, 15, 12);
        Carbon::setTestNow($now);

        $agent = $this->makeAgent();
        $deployment = $this->makeDeployment($agent);
        $this->makeSnapshot($agent, $deployment, '2026-06-05', 1000, 500, 2.00);

        $service = app(AgentCostService::class);
        $first = $service->usageForMonth($agent);
        $this->assertSame(1500, $first['tokens']);

        // A snapshot written after the first read must NOT change the cached value.
        $this->makeSnapshot($agent, $deployment, '2026-06-06', 5000, 5000, 50.00);
        $second = $service->usageForMonth($agent);
        $this->assertSame(1500, $second['tokens'], 'value should be served from cache');

        Cache::forget("agent_monthly_usage:{$agent->getId()}:2026-06-15");
        $third = $service->usageForMonth($agent);
        $this->assertSame(11500, $third['tokens'], 'value should refresh after cache clear');

        Carbon::setTestNow();
    }
}
