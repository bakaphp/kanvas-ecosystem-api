<?php

declare(strict_types=1);

namespace Tests\Connectors\ClaudeAgent;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\ClaudeAgent\Actions\RecordSessionUsageAction;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentUsageSnapshot;
use Tests\Connectors\Traits\HasClaudeAgentConfiguration;
use Tests\TestCase;

/**
 * A hosted session is billed for running time and web searches on top of tokens, and Anthropic is
 * the only one who knows the total — so these pin that we store its reported `list_cost` rather than
 * re-deriving a number that would always be too low.
 */
final class RecordSessionUsageActionTest extends TestCase
{
    use DatabaseTransactions;
    use HasClaudeAgentConfiguration;

    /** Settings live on mysql; agents and usage snapshots on intelligence. */
    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private Apps $currentApp;
    private Companies $currentCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->currentCompany = static::$cachedUser->getCurrentCompany();
    }

    /**
     * The payload shape is copied from a real `session.usage` event.
     *
     * @return array<string, mixed>
     */
    private function usage(int $cents, int $input = 2, int $output = 727): array
    {
        return [
            'active_seconds' => 15.943,
            'cache_creation' => ['ephemeral_1h_input_tokens' => 0, 'ephemeral_5m_input_tokens' => 31586],
            'cache_read_input_tokens' => 0,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'list_cost' => ['amount' => (string) $cents, 'currency' => 'USD'],
        ];
    }

    private function snapshotFor(Agent $agent): ?AgentUsageSnapshot
    {
        return AgentUsageSnapshot::query()
            ->where('agent_id', $agent->getId())
            ->where('source', AgentProviderEnum::CLAUDE->value)
            ->first();
    }

    public function testItStoresTheReportedCostAndTokens(): void
    {
        $agent = $this->makeClaudeAgent($this->currentApp, $this->currentCompany);

        new RecordSessionUsageAction($agent, 'sesn_01', $this->usage(22))->execute();

        $snapshot = $this->snapshotFor($agent);

        $this->assertNotNull($snapshot);
        $this->assertSame('0.220000', $snapshot->cost_usd);
        $this->assertSame(2, $snapshot->input_tokens);
        $this->assertSame(727, $snapshot->output_tokens);
        $this->assertSame(31586, $snapshot->cache_write_tokens);
        $this->assertSame(1, $snapshot->total_sessions);
    }

    /**
     * The event reports the session's running total, so a second call for the same session must
     * replace the figure. Adding it would double the day's cost on every poll.
     */
    public function testTheSameSessionReportingAgainReplacesRatherThanAdds(): void
    {
        $agent = $this->makeClaudeAgent($this->currentApp, $this->currentCompany);

        new RecordSessionUsageAction($agent, 'sesn_01', $this->usage(22))->execute();
        new RecordSessionUsageAction($agent, 'sesn_01', $this->usage(58, output: 1500))->execute();

        $snapshot = $this->snapshotFor($agent);

        $this->assertSame('0.580000', $snapshot->cost_usd);
        $this->assertSame(1500, $snapshot->output_tokens);
        $this->assertSame(1, $snapshot->total_sessions);
    }

    /**
     * Different sessions on the same day DO add up — an agent that ran three tasks costs the sum of
     * the three.
     */
    public function testSeparateSessionsAccumulateIntoTheDay(): void
    {
        $agent = $this->makeClaudeAgent($this->currentApp, $this->currentCompany);

        new RecordSessionUsageAction($agent, 'sesn_01', $this->usage(22))->execute();
        new RecordSessionUsageAction($agent, 'sesn_02', $this->usage(30))->execute();

        $snapshot = $this->snapshotFor($agent);

        $this->assertSame('0.520000', $snapshot->cost_usd);
        $this->assertSame(2, $snapshot->total_sessions);
        $this->assertCount(2, $snapshot->parsed_data['sessions']);
    }

    /** An empty usage payload is not a zero-cost session worth a row. */
    public function testAnEmptyPayloadRecordsNothing(): void
    {
        $agent = $this->makeClaudeAgent($this->currentApp, $this->currentCompany);

        $this->assertNull(new RecordSessionUsageAction($agent, 'sesn_01', [])->execute());
        $this->assertNull($this->snapshotFor($agent));
    }

    /**
     * Two agents in the same company share every column of the snapshot unique key except the one
     * that matters, since neither has a deployment row.
     */
    public function testTwoHostedAgentsGetSeparateSnapshots(): void
    {
        $first = $this->makeClaudeAgent($this->currentApp, $this->currentCompany);
        $second = $this->makeClaudeAgent($this->currentApp, $this->currentCompany);

        new RecordSessionUsageAction($first, 'sesn_01', $this->usage(22))->execute();
        new RecordSessionUsageAction($second, 'sesn_02', $this->usage(30))->execute();

        $this->assertSame('0.220000', $this->snapshotFor($first)->cost_usd);
        $this->assertSame('0.300000', $this->snapshotFor($second)->cost_usd);
    }
}
