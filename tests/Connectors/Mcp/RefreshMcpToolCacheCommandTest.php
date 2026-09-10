<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use Kanvas\NervousSystem\Capability\Models\McpToolSnapshot;

final class RefreshMcpToolCacheCommandTest extends McpTestCase
{
    public function testItRunsCleanlyWhenAServerCannotBeReached(): void
    {
        $integration = $this->makeIntegration();
        $this->enableForCompany($integration);
        $this->makeMcpTool($integration);

        // The real transport cannot reach mcp.example.test, which is the point: a vendor being down
        // must not fail the scheduled sweep for every other tenant behind it.
        $this->artisan('kanvas:mcp:refresh-tool-cache', ['--integration' => $integration->getId()])
            ->assertSuccessful();
    }

    public function testAnUnreachableServerLeavesNoSnapshotBehind(): void
    {
        $integration = $this->makeIntegration();
        $this->enableForCompany($integration);
        $this->makeMcpTool($integration);

        $this->artisan('kanvas:mcp:refresh-tool-cache', ['--integration' => $integration->getId()])
            ->assertSuccessful();

        // Only a successful tools/list writes; a failed sweep must never invent an empty tool list.
        $this->assertNull(
            McpToolSnapshot::query()->where('integrations_id', $integration->getId())->first()
        );
    }

    public function testItSkipsServersNoCompanyHasConnected(): void
    {
        $integration = $this->makeIntegration();
        $this->makeMcpTool($integration);

        $this->artisan('kanvas:mcp:refresh-tool-cache', ['--integration' => $integration->getId()])
            ->expectsOutputToContain('Refreshed 0 MCP tool list(s)')
            ->assertSuccessful();
    }
}
