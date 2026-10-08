<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\TrackAgentUsageAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentPerformanceMetric;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Tests\TestCase;

final class TrackAgentUsageActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = [null, 'intelligence'];

    public function testTheBusyThreadWaitIsItsOwnMetricAndOnlyWhenThereWasOne(): void
    {
        $this->assertSame(
            ['duration_ms' => 1200.0, 'input_chars' => 2.0, 'output_chars' => 5.0, 'thread_wait_ms' => 5000.0],
            $this->metricsFor(threadWaitMs: 5000),
        );
        $this->assertSame(
            ['duration_ms' => 1200.0, 'input_chars' => 2.0, 'output_chars' => 5.0],
            $this->metricsFor(threadWaitMs: 0),
        );
    }

    /**
     * @return array<string, float>
     */
    private function metricsFor(int $threadWaitMs): array
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agentType = AgentType::factory()->withAppId($app->getId())->create(['provider' => 'neuron']);
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $agentType->getId()]);

        $history = new TrackAgentUsageAction(
            agent: $agent,
            app: $app,
            company: $company,
            message: 'hi',
            response: 'hello',
            durationMs: 1200.0,
            threadWaitMs: $threadWaitMs,
        )->execute();

        return AgentPerformanceMetric::query()
            ->where('agent_history_id', $history->getId())
            ->orderBy('id')
            ->pluck('value', 'metric_type')
            ->map(fn (mixed $value): float => (float) $value)
            ->all();
    }
}
