<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentConversation;
use Kanvas\Intelligence\Agents\Models\AgentConversationMessage;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Models\AgentUsageSnapshot;
use Tests\TestCase;

final class AgentSpendReportCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'intelligence'];

    private function agent(string $name): Agent
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $agentType = AgentType::factory()->withAppId($app->getId())->create(['provider' => 'neuron']);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'name' => $name,
                'agent_type_id' => $agentType->getId(),
            ]);
    }

    private function recordTurn(Agent $agent, string $model, int $input, int $output): void
    {
        $conversation = AgentConversation::create([
            'id' => (string) Str::uuid(),
            'apps_id' => $agent->apps_id,
            'companies_id' => $agent->companies_id,
            'agent_id' => $agent->getId(),
            'user_id' => auth()->user()->getId(),
            'title' => 'spend report fixture',
        ]);

        AgentConversationMessage::create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'agent' => (string) $agent->uuid,
            'role' => 'assistant',
            'content' => 'fixture',
            'attachments' => [],
            'tool_calls' => [],
            'tool_results' => [],
            'meta' => [],
            'usage' => ['model' => $model, 'prompt_tokens' => $input, 'completion_tokens' => $output],
        ]);
    }

    private function recordSnapshot(Agent $agent, string $source, float $cost, ?int $deploymentId): void
    {
        AgentUsageSnapshot::create([
            'apps_id' => $agent->apps_id,
            'companies_id' => $agent->companies_id,
            'agent_id' => $agent->getId(),
            'agent_deployment_id' => $deploymentId,
            'snapshot_date' => Carbon::now()->toDateString(),
            'source' => $source,
            'input_tokens' => 1000,
            'output_tokens' => 500,
            'total_tokens' => 1500,
            'cache_read_tokens' => 0,
            'cache_write_tokens' => 0,
            'cost_usd' => $cost,
            'total_sessions' => 1,
            'raw_output' => '',
        ]);
    }

    private function report(string $group): string
    {
        Artisan::call('kanvas-intelligence:agent-spend-report', ['--limit' => 0, '--group' => $group]);

        return Artisan::output();
    }

    /**
     * The row for one agent, so an assertion cannot pass on unrelated seed data that happens to
     * contain the same substring elsewhere in the table.
     */
    private function rowFor(string $output, string $agentName): string
    {
        foreach (explode("\n", $output) as $line) {
            if (str_contains($line, $agentName)) {
                return $line;
            }
        }

        return '';
    }

    /**
     * Hosted Claude writes agent_deployment_id = null, exactly like the neuron/laravel rollup does.
     * Partitioning the snapshot side on that FK (the obvious reading) drops every Claude agent from
     * the report silently — the partition has to be on `source` instead.
     */
    public function testHostedClaudeUsageIsCountedEvenThoughItHasNoDeployment(): void
    {
        $this->recordSnapshot($this->agent('Claude Runtime Agent'), 'claude', 12.34, null);

        $row = $this->rowFor($this->report('agent'), 'Claude Runtime Agent');

        // Not assertStringContainsString('claude', $output): seeded openclaw rows carry the model
        // claude-opus-4, so that substring passes even with hosted Claude dropped entirely.
        $this->assertStringContainsString('| claude ', $row, 'Claude runtime must be its own row.');
        $this->assertStringContainsString('12.34', $row);
    }

    /**
     * The rollup writes the same turns into agent_usage_snapshots overnight. The report reads the
     * turns live, so counting the rollup rows too would bill every in-process agent twice.
     */
    public function testTheNeuronRollupIsNotCountedOnTopOfTheLiveTurns(): void
    {
        $agent = $this->agent('Double Count Canary');
        $this->recordTurn($agent, 'gemini-3.8-flash', 1_000_000, 100_000);
        $this->recordSnapshot($agent, 'neuron', 999.99, null);

        $row = $this->rowFor($this->report('agent'), 'Double Count Canary');

        $this->assertStringContainsString('in-process', $row);
        $this->assertStringNotContainsString('neuron', $row, 'The rollup row must not be counted again.');
        $this->assertStringNotContainsString('999.99', $row);
    }

    public function testContainerRuntimeAndInProcessBothAppear(): void
    {
        $this->recordTurn($this->agent('Neuron One'), 'gemini-3.8-flash', 500_000, 50_000);
        $this->recordSnapshot($this->agent('OpenClaw One'), 'openclaw_docker', 5.00, 4242);

        $output = $this->report('agent');

        $this->assertStringContainsString('in-process', $this->rowFor($output, 'Neuron One'));
        $this->assertStringContainsString('openclaw_docker', $this->rowFor($output, 'OpenClaw One'));
    }
}
