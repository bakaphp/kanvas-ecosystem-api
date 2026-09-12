<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use Kanvas\Connectors\Mcp\Services\McpConnectionService;
use Kanvas\Intelligence\Agents\Neuron\Tools\Mcp\RemoteMcpToolkit;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\NervousSystem\Plan\Support\WorkerToolPolicy;
use Tests\Stubs\Connectors\Mcp\FakeMcpServer;

final class RemoteMcpToolkitTest extends McpTestCase
{
    public function testAConnectedAgentResolvesItsTools(): void
    {
        $tool = $this->makeMcpTool($this->makeIntegration());
        $agent = $this->makeAgent();
        $this->connectAgent($agent, $tool);

        $names = array_map(fn ($tool): string => $tool->getName(), new RemoteMcpToolkit($agent, $tool)->tools());

        $this->assertCount(2, $names);
        $this->assertContains('fake__createJiraIssue', $names);
    }

    public function testAnAgentThatNeverConnectedGetsNothingEvenWhenAnotherAgentDid(): void
    {
        $tool = $this->makeMcpTool($this->makeIntegration());
        $this->connectAgent($this->makeAgent(), $tool);

        // Each agent signs in with its own vendor account; one agent's connection is never another's.
        $this->assertSame([], new RemoteMcpToolkit($this->makeAgent(), $tool)->tools());
    }

    public function testAConnectionWhoseCredentialIsGoneGetsNothingEvenWithAWarmCache(): void
    {
        $integration = $this->makeIntegration();
        $tool = $this->makeMcpTool($integration);
        $agent = $this->makeAgent();
        $this->connectAgent($agent, $tool);

        $this->credentials($agent, $integration)->forget();

        $this->assertSame([], new RemoteMcpToolkit($agent, $tool)->tools());
    }

    public function testAFailedConnectionGetsNothing(): void
    {
        $integration = $this->makeIntegration();
        $tool = $this->makeMcpTool($integration);
        $agent = $this->makeAgent();
        $this->connectAgent($agent, $tool);

        new McpConnectionService($agent, $integration)->markFailed('revoked at the vendor');

        $this->assertSame([], new RemoteMcpToolkit($agent, $tool)->tools());
    }

    public function testResolutionNeverThrowsWhenTheRowIsUnusable(): void
    {
        $tool = $this->makeMcpTool($this->makeIntegration(['url' => '']));
        $agent = $this->makeAgent();
        $this->grantWithCredential($agent, $tool);

        // A dead vendor or a broken row costs the agent one toolset, never the turn.
        $this->assertSame([], new RemoteMcpToolkit($agent, $tool)->tools());
    }

    public function testGuidelinesLeadWithTheServerNameBecauseTheHeadingCannot(): void
    {
        $tool = $this->makeMcpTool($this->makeIntegration());
        $agent = $this->makeAgent();
        $this->connectAgent($agent, $tool);

        // Neuron titles the block with the class short name, identical for every MCP server — so three
        // connected servers would render three indistinguishable `# RemoteMcpToolkit` headings unless
        // the text itself says which vendor it is.
        $this->assertStringStartsWith('Fakevendor', (string) new RemoteMcpToolkit($agent, $tool)->guidelines());
    }

    public function testTheWorkerBoundaryCanSeeThroughTheToolkit(): void
    {
        $tool = $this->makeMcpTool($this->makeIntegration());
        $agent = $this->makeAgent();
        $this->connectAgent($agent, $tool);

        $toolkit = new RemoteMcpToolkit($agent, $tool);
        $expanded = WorkerToolPolicy::within(fn (): array => new McpToolHostStub()->boundary([$toolkit]));

        // A toolkit answers to neither getName() nor name(), so without expansion an MCP grant would
        // sail past the worker policy entirely.
        $this->assertCount(2, $expanded);
        $this->assertContains('fake__createJiraIssue', array_map(fn ($tool): string => $tool->getName(), $expanded));
    }

    public function testAToolCallIsRecordedInTheLedgerAgainstTheAgentThatMadeIt(): void
    {
        $tool = $this->makeMcpTool($this->makeIntegration());
        $agent = $this->makeAgent();
        $this->connectAgent($agent, $tool);

        $toolkit = new RemoteMcpToolkit(
            $agent,
            $tool,
            FakeMcpServer::handshakeThenCalls(['content' => [['type' => 'text', 'text' => 'created']]])
        );

        $call = collect($toolkit->tools())->first(fn ($candidate): bool => $candidate->getName() === 'fake__createJiraIssue');
        $call->setInputs(['summary' => 'Ship it'])->execute();

        $event = Event::query()
            ->where('event_type', 'mcp.tool.invoked')
            ->where('source_entity_id', $tool->getId())
            ->latest('id')
            ->first();

        // The catalog row is global (apps_id 0), so the tenant and the actor both have to come from the
        // agent — otherwise the agent's own ledger memory never learns what it did in Jira.
        $this->assertNotNull($event, 'A call on a platform-wide MCP row must still reach the ledger.');
        $this->assertSame('Agent', $event->actor_type);
        $this->assertSame($agent->getId(), $event->actor_id);
        $this->assertSame($agent->apps_id, $event->apps_id);
        $this->assertSame($agent->companies_id, $event->companies_id);
        $this->assertSame('createJiraIssue', $event->payload['remote_tool']);
    }
}
