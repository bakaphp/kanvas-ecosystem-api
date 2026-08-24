<?php

declare(strict_types=1);

namespace Tests\Connectors\ClaudeAgent;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\ClaudeAgent\Services\AgentSpecBuilderService;
use Kanvas\Connectors\ClaudeAgent\Services\CustomToolBridgeService;
use Kanvas\Intelligence\Agents\Models\Agent;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use RuntimeException;
use Tests\Connectors\Traits\HasClaudeAgentConfiguration;
use Tests\TestCase;

/**
 * The bridge is what makes a hosted agent a Kanvas teammate rather than an isolated sandbox: it
 * exposes our PHP tools to the remote agent and executes the calls locally, so credentials never
 * leave our side.
 */
final class CustomToolBridgeServiceTest extends TestCase
{
    use DatabaseTransactions;
    use HasClaudeAgentConfiguration;

    /** Settings live on mysql; agents, types and sessions on intelligence. */
    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private Apps $currentApp;
    private Companies $currentCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->currentCompany = static::$cachedUser->getCurrentCompany();
    }

    private function makeAgent(): Agent
    {
        return $this->makeClaudeAgent($this->currentApp, $this->currentCompany);
    }

    private function lookupTool(): Tool
    {
        return Tool::make('get_lead_status', 'Look up the status of a lead by id.')
            ->addProperty(new ToolProperty(
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas lead id.',
                required: true,
            ))
            ->setCallable(static fn (int $lead_id): array => ['lead_id' => $lead_id, 'status' => 'won']);
    }

    public function testDefinitionsCarryNameDescriptionAndSchema(): void
    {
        $bridge = new CustomToolBridgeService($this->makeAgent(), [$this->lookupTool()]);

        $definition = $bridge->definitions()[0];

        $this->assertSame('custom', $definition['type']);
        $this->assertSame('get_lead_status', $definition['name']);
        $this->assertStringContainsString('Look up the status', $definition['description']);
        $this->assertSame('object', $definition['input_schema']['type']);
        $this->assertSame(['lead_id'], $definition['input_schema']['required']);
        $this->assertArrayHasKey('lead_id', (array) $definition['input_schema']['properties']);
    }

    public function testCallExecutesTheToolAndReturnsItsResult(): void
    {
        $bridge = new CustomToolBridgeService($this->makeAgent(), [$this->lookupTool()]);

        $outcome = $bridge->call('get_lead_status', ['lead_id' => 42]);

        $this->assertFalse($outcome['isError']);
        $this->assertStringContainsString('"status":"won"', $outcome['content']);
        $this->assertStringContainsString('42', $outcome['content']);
    }

    /**
     * A thrown tool must not abort the turn. Returning an error result hands the failure to the
     * agent, which can fix its arguments or take another route.
     */
    public function testAThrowingToolBecomesAnErrorResultNotAnException(): void
    {
        $exploding = Tool::make('explode', 'Always fails.')
            ->setCallable(static fn (): string => throw new RuntimeException('database is on fire'));

        $outcome = new CustomToolBridgeService($this->makeAgent(), [$exploding])->call('explode', []);

        $this->assertTrue($outcome['isError']);
        $this->assertStringContainsString('database is on fire', $outcome['content']);
    }

    /**
     * Same reasoning for a hallucinated tool name — telling the model the tool doesn't exist is more
     * useful than crashing the turn.
     */
    public function testAnUnknownToolNameIsReportedBackAsAnError(): void
    {
        $outcome = new CustomToolBridgeService($this->makeAgent(), [$this->lookupTool()])
            ->call('teleport', []);

        $this->assertTrue($outcome['isError']);
        $this->assertStringContainsString('Unknown tool', $outcome['content']);
    }

    /**
     * NeuronAI tools hold their inputs and last result as instance state, so two calls in one turn
     * would otherwise leak arguments into each other.
     */
    public function testRepeatedCallsDoNotLeakArgumentsBetweenInvocations(): void
    {
        $bridge = new CustomToolBridgeService($this->makeAgent(), [$this->lookupTool()]);

        $first = $bridge->call('get_lead_status', ['lead_id' => 1]);
        $second = $bridge->call('get_lead_status', ['lead_id' => 2]);

        $this->assertStringContainsString('"lead_id":1', $first['content']);
        $this->assertStringContainsString('"lead_id":2', $second['content']);
    }

    public function testMissingRequiredInputComesBackAsAnErrorResult(): void
    {
        $outcome = new CustomToolBridgeService($this->makeAgent(), [$this->lookupTool()])
            ->call('get_lead_status', []);

        $this->assertTrue($outcome['isError']);
        $this->assertStringContainsString('lead_id', $outcome['content']);
    }

    /**
     * Tools ride the agent spec, so granting one changes the fingerprint and versions the remote
     * agent — an agent with different tools is a different agent.
     */
    public function testGrantingAToolChangesTheSpecFingerprint(): void
    {
        $agent = $this->makeAgent();

        $without = new AgentSpecBuilderService($agent, new CustomToolBridgeService($agent, []))->build();
        $with = new AgentSpecBuilderService(
            $agent,
            new CustomToolBridgeService($agent, [$this->lookupTool()]),
        )->build();

        $this->assertNotSame($without->fingerprint(), $with->fingerprint());
        $this->assertCount(1, $without->tools);
        $this->assertCount(2, $with->tools);
        $this->assertSame(AgentSpecBuilderService::AGENT_TOOLSET, $with->tools[0]['type']);
        $this->assertSame('custom', $with->tools[1]['type']);
    }
}
