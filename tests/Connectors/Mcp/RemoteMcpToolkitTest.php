<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use Kanvas\Connectors\Mcp\Services\McpConnectionService;
use Kanvas\Connectors\Mcp\Services\McpToolCacheService;
use Kanvas\Intelligence\Agents\Neuron\Tools\Mcp\RemoteMcpToolkit;
use Kanvas\NervousSystem\Plan\Support\WorkerToolPolicy;
use Kanvas\Workflow\Models\Integrations;
use Tests\Stubs\Connectors\Mcp\FakeMcpServer;

final class RemoteMcpToolkitTest extends McpTestCase
{
    public function testAGrantedAndConnectedServerResolvesItsTools(): void
    {
        $integration = $this->makeIntegration();
        $this->enableForCompany($integration);
        $this->warm($integration);

        $toolkit = new RemoteMcpToolkit($this->mcpApp, $this->mcpCompany, $this->makeMcpTool($integration));

        $names = array_map(fn ($tool): string => $tool->getName(), $toolkit->tools());

        $this->assertCount(2, $names);
        $this->assertContains('fake__createJiraIssue', $names);
    }

    public function testACompanyThatNeverConnectedTheServerGetsNothing(): void
    {
        $integration = $this->makeIntegration();
        $this->warm($integration);

        // The grant says "this agent may use Linear"; the integration row says "this company has
        // Linear". Both gates are required, and this is the one the grant cannot speak for.
        $toolkit = new RemoteMcpToolkit($this->mcpApp, $this->mcpCompany, $this->makeMcpTool($integration));

        $this->assertSame([], $toolkit->tools());
    }

    public function testAFailedIntegrationGetsNothing(): void
    {
        $integration = $this->makeIntegration();
        $this->enableForCompany($integration, 'failed');
        $this->warm($integration);

        $toolkit = new RemoteMcpToolkit($this->mcpApp, $this->mcpCompany, $this->makeMcpTool($integration));

        $this->assertSame([], $toolkit->tools());
    }

    public function testResolutionNeverThrowsWhenTheRowIsUnusable(): void
    {
        $integration = $this->makeIntegration(['url' => '']);
        $this->enableForCompany($integration);

        $toolkit = new RemoteMcpToolkit($this->mcpApp, $this->mcpCompany, $this->makeMcpTool($integration));

        // A dead vendor or a broken row costs the agent one toolset, never the turn.
        $this->assertSame([], $toolkit->tools());
    }

    public function testGuidelinesLeadWithTheServerNameBecauseTheHeadingCannot(): void
    {
        $integration = $this->makeIntegration();
        $this->enableForCompany($integration);
        $this->warm($integration);

        $toolkit = new RemoteMcpToolkit($this->mcpApp, $this->mcpCompany, $this->makeMcpTool($integration));

        // Neuron titles the block with the class short name, identical for every MCP server — so three
        // granted servers would render three indistinguishable `# RemoteMcpToolkit` headings unless the
        // text itself says which vendor it is.
        $this->assertStringStartsWith('Fakevendor', (string) $toolkit->guidelines());
    }

    public function testTheWorkerBoundaryCanSeeThroughTheToolkit(): void
    {
        $integration = $this->makeIntegration();
        $this->enableForCompany($integration);
        $this->warm($integration);

        $toolkit = new RemoteMcpToolkit($this->mcpApp, $this->mcpCompany, $this->makeMcpTool($integration));
        $host = new McpToolHostStub();

        $expanded = WorkerToolPolicy::within(fn (): array => $host->boundary([$toolkit]));

        // A toolkit answers to neither getName() nor name(), so without expansion an MCP grant would
        // sail past the worker policy entirely.
        $this->assertCount(2, $expanded);
        $this->assertContains(
            'fake__createJiraIssue',
            array_map(fn ($tool): string => $tool->getName(), $expanded)
        );
    }

    private function warm(Integrations $integration): void
    {
        new McpToolCacheService(
            app: $this->mcpApp,
            company: $this->mcpCompany,
            integration: $integration,
            connection: new McpConnectionService(
                $this->mcpApp,
                $this->mcpCompany,
                $integration,
                FakeMcpServer::listing(FakeMcpServer::twoTools())
            ),
        )->refresh();
    }
}
