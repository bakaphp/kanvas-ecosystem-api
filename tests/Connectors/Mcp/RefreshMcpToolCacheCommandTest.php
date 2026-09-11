<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use Kanvas\Connectors\Mcp\Services\McpConnectionService;
use Kanvas\NervousSystem\Capability\Models\McpToolSnapshot;

final class RefreshMcpToolCacheCommandTest extends McpTestCase
{
    public function testAnUnreachableServerDoesNotFailTheSweep(): void
    {
        $integration = $this->makeIntegration();
        $this->grantWithCredential($this->makeAgent(), $this->makeMcpTool($integration));

        // The real transport cannot reach mcp.example.test, which is the point: a vendor being down
        // must not fail the scheduled sweep for every other agent behind it.
        $this->artisan('kanvas:mcp:refresh-tool-cache', ['--integration' => $integration->getId()])
            ->expectsOutputToContain('Refreshed 0 MCP tool list(s), 0 skipped, 1 failed')
            ->assertSuccessful();
    }

    public function testAnUnreachableServerLeavesNoSnapshotBehind(): void
    {
        $integration = $this->makeIntegration();
        $agent = $this->makeAgent();
        $this->grantWithCredential($agent, $this->makeMcpTool($integration));

        $this->artisan('kanvas:mcp:refresh-tool-cache', ['--integration' => $integration->getId()])
            ->assertSuccessful();

        // Only a successful tools/list writes; a failed sweep must never invent an empty tool list.
        $this->assertNull(
            McpToolSnapshot::query()
                ->where('agents_id', $agent->getId())
                ->where('integrations_id', $integration->getId())
                ->first()
        );
    }

    public function testAFailedConnectionIsNotDialledAgain(): void
    {
        $integration = $this->makeIntegration();
        $agent = $this->makeAgent();
        $this->grantWithCredential($agent, $this->makeMcpTool($integration));
        new McpConnectionService($agent, $integration)->markFailed('token revoked');

        // It is waiting on a person to reconnect; dialling out would only 401 again.
        $this->artisan('kanvas:mcp:refresh-tool-cache', ['--integration' => $integration->getId()])
            ->expectsOutputToContain('Refreshed 0 MCP tool list(s), 1 skipped, 0 failed')
            ->assertSuccessful();
    }

    public function testAServerNoAgentHasConnectedIsLeftAlone(): void
    {
        $integration = $this->makeIntegration();
        $this->makeMcpTool($integration);

        $this->artisan('kanvas:mcp:refresh-tool-cache', ['--integration' => $integration->getId()])
            ->expectsOutputToContain('Refreshed 0 MCP tool list(s), 0 skipped, 0 failed')
            ->assertSuccessful();
    }
}
