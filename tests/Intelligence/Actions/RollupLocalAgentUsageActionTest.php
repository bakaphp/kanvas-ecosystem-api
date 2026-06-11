<?php

declare(strict_types=1);

namespace Tests\Intelligence\Actions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\RollupLocalAgentUsageAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Models\AgentUsageSnapshot;
use Tests\TestCase;

class RollupLocalAgentUsageActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private function makeAgent(string $provider): Agent
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $type = AgentType::factory()
            ->withAppId($app->getId())
            ->create(['provider' => $provider]);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $type->getId()]);
    }

    private function makeConversation(Agent $agent, ?string $model = null): string
    {
        $id = 'conv_' . Str::random(20);
        DB::connection('intelligence')->table('agent_conversations')->insert([
            'id' => $id,
            'apps_id' => $agent->apps_id,
            'companies_id' => $agent->companies_id,
            'agent_id' => $agent->getId(),
            'user_id' => $agent->users_id,
            'title' => 'test',
            'meta' => $model !== null ? json_encode(['model' => $model]) : null,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return $id;
    }

    /**
     * @param array<string, mixed>|null $usage
     */
    private function makeMessage(string $conversationId, string $role, ?array $usage, Carbon $at): void
    {
        DB::connection('intelligence')->table('agent_conversation_messages')->insert([
            'id' => 'msg_' . Str::random(30),
            'conversation_id' => $conversationId,
            'role' => $role,
            'agent' => 'Test\\Agent',
            'content' => 'hi',
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => $usage !== null ? json_encode($usage) : '[]',
            'meta' => '[]',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    public function testRollsUpLocalAgentUsageIntoSnapshot(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 9, 12));
        $app = app(Apps::class);

        $agent = $this->makeAgent('laravel');
        $conv = $this->makeConversation($agent);

        $day = Carbon::create(2026, 6, 9, 10);
        $this->makeMessage($conv, 'user', [], $day);
        $this->makeMessage($conv, 'assistant', [
            'prompt_tokens' => 1000,
            'completion_tokens' => 400,
            'cache_read_input_tokens' => 50,
            'cache_write_input_tokens' => 20,
        ], $day);
        $this->makeMessage($conv, 'assistant', [
            'prompt_tokens' => 500,
            'completion_tokens' => 100,
        ], $day->copy()->addHour());

        $snapshots = new RollupLocalAgentUsageAction($app, Carbon::create(2026, 6, 9))->execute();

        $this->assertCount(1, $snapshots);

        $snapshot = AgentUsageSnapshot::query()
            ->where('agent_id', $agent->getId())
            ->where('snapshot_date', '2026-06-09')
            ->where('source', 'laravel')
            ->firstOrFail();

        $this->assertNull($snapshot->agent_deployment_id);
        $this->assertSame(1500, $snapshot->input_tokens);
        $this->assertSame(500, $snapshot->output_tokens);
        $this->assertSame(2000, $snapshot->total_tokens);
        $this->assertSame(50, $snapshot->cache_read_tokens);
        $this->assertSame(20, $snapshot->cache_write_tokens);
        $this->assertSame(1, $snapshot->total_sessions);

        Carbon::setTestNow();
    }

    public function testHandlesInputOutputTokenKeyVariant(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 9, 12));
        $app = app(Apps::class);

        $agent = $this->makeAgent('neuron');
        $conv = $this->makeConversation($agent);

        // Some runtimes/versions emit input_tokens/output_tokens instead of
        // prompt_tokens/completion_tokens — both must sum.
        $this->makeMessage($conv, 'assistant', [
            'input_tokens' => 5935,
            'output_tokens' => 152,
            'cache_read' => 100,
            'model' => 'gemini-3.5-flash',
        ], Carbon::create(2026, 6, 9, 10));

        new RollupLocalAgentUsageAction($app, Carbon::create(2026, 6, 9))->execute();

        $snapshot = AgentUsageSnapshot::query()
            ->where('agent_id', $agent->getId())
            ->where('snapshot_date', '2026-06-09')
            ->firstOrFail();

        $this->assertSame(5935, $snapshot->input_tokens);
        $this->assertSame(152, $snapshot->output_tokens);
        $this->assertSame(100, $snapshot->cache_read_tokens);
        $this->assertSame('gemini-3.5-flash', $snapshot->model);

        Carbon::setTestNow();
    }

    public function testCostUsdSurvivesMassAssignment(): void
    {
        // Regression: cost_usd was missing from $fillable, so updateOrCreate
        // silently dropped it and every snapshot persisted cost as 0.
        $agent = $this->makeAgent('laravel');

        $snapshot = AgentUsageSnapshot::updateOrCreate(
            [
                'apps_id' => $agent->apps_id,
                'companies_id' => $agent->companies_id,
                'agent_id' => $agent->getId(),
                'agent_deployment_id' => null,
                'snapshot_date' => '2026-06-01',
                'source' => 'laravel',
            ],
            [
                'cost_usd' => 1.234567,
                'input_tokens' => 10,
                'output_tokens' => 5,
                'total_tokens' => 15,
                'raw_output' => '',
            ]
        );

        $this->assertEqualsWithDelta(1.234567, (float) $snapshot->fresh()->cost_usd, 0.0000001);
    }

    public function testIgnoresContainerRuntimeAgents(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 9, 12));
        $app = app(Apps::class);

        // Hermes is a container runtime — its conversations must NOT be rolled up
        // here (they're collected via the sessions DB; rolling up would double-count).
        $agent = $this->makeAgent('hermes');
        $conv = $this->makeConversation($agent);
        $this->makeMessage($conv, 'assistant', [
            'prompt_tokens' => 9999,
            'completion_tokens' => 9999,
        ], Carbon::create(2026, 6, 9, 10));

        $snapshots = new RollupLocalAgentUsageAction($app, Carbon::create(2026, 6, 9))->execute();

        $this->assertSame(
            0,
            AgentUsageSnapshot::query()->where('agent_id', $agent->getId())->count(),
            'Hermes agent must be skipped by the local rollup',
        );
        $this->assertEmpty(array_filter(
            $snapshots,
            fn (AgentUsageSnapshot $s) => $s->agent_id === $agent->getId(),
        ));

        Carbon::setTestNow();
    }

    /**
     * The rollup's job is to resolve the model used (from the message usage blob,
     * where the Laravel/Neuron chat paths record it) and the LLM provider, and
     * hand them to ModelPricingCalculator. The cost math itself is covered by
     * ModelPricingCalculatorTest — not re-asserted here because the intelligence
     * connection's read/write split (sticky=false) means a test-inserted pricing
     * row isn't visible to the calculator's read PDO.
     */
    public function testResolvesModelAndProviderFromMessageUsage(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 9, 12));
        $app = app(Apps::class);

        $agent = $this->makeAgent('laravel');
        $conv = $this->makeConversation($agent);
        $this->makeMessage($conv, 'assistant', [
            'prompt_tokens' => 1200,
            'completion_tokens' => 300,
            'model' => 'gemini-3.1-pro-preview',
        ], Carbon::create(2026, 6, 9, 10));

        new RollupLocalAgentUsageAction($app, Carbon::create(2026, 6, 9))->execute();

        $snapshot = AgentUsageSnapshot::query()
            ->where('agent_id', $agent->getId())
            ->where('snapshot_date', '2026-06-09')
            ->firstOrFail();

        $this->assertSame('gemini-3.1-pro-preview', $snapshot->model);
        $this->assertSame('google', $snapshot->provider);
        $this->assertSame(1200, $snapshot->input_tokens);
        $this->assertSame(300, $snapshot->output_tokens);

        Carbon::setTestNow();
    }

    public function testReRunIsIdempotent(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 6, 9, 12));
        $app = app(Apps::class);

        $agent = $this->makeAgent('neuron');
        $conv = $this->makeConversation($agent);
        $this->makeMessage($conv, 'assistant', [
            'prompt_tokens' => 300,
            'completion_tokens' => 100,
        ], Carbon::create(2026, 6, 9, 10));

        new RollupLocalAgentUsageAction($app, Carbon::create(2026, 6, 9))->execute();
        new RollupLocalAgentUsageAction($app, Carbon::create(2026, 6, 9))->execute();

        $this->assertSame(
            1,
            AgentUsageSnapshot::query()
                ->where('agent_id', $agent->getId())
                ->where('snapshot_date', '2026-06-09')
                ->where('source', 'neuron')
                ->count(),
            'Re-running the rollup must upsert, not duplicate',
        );

        Carbon::setTestNow();
    }
}
