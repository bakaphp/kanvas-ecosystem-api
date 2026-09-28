<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use App\GraphQL\NervousSystem\Mutations\McpMutation;
use App\GraphQL\NervousSystem\Queries\McpQuery;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Mcp\Enums\McpConnectionStatusEnum;
use Kanvas\Connectors\Mcp\Services\McpConnectionService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Kanvas\NervousSystem\Capability\Models\Tool;

final class McpAdminSurfaceTest extends McpTestCase
{
    public function testServerStateDescribesTheAgentsOwnConnection(): void
    {
        $tool = $this->makeMcpTool($this->makeIntegration());
        $agent = $this->makeAgent();
        $this->connectAgent($agent, $tool);

        $state = new McpQuery()->serverState($tool, ['agent_id' => $agent->getId()]);

        $this->assertTrue($state['connected']);
        $this->assertSame(McpConnectionStatusEnum::ACTIVE->value, $state['status']);
        $this->assertSame('bearer', $state['auth']);
        $this->assertInstanceOf(Carbon::class, $state['connected_at']);
        $this->assertSame(2, $state['tool_count']);

        // Both names matter: one is what the model calls, the other is what the server answers to.
        $this->assertContains('fake__createJiraIssue', array_column($state['tools'], 'name'));
        $this->assertContains('createJiraIssue', array_column($state['tools'], 'remote_name'));
    }

    public function testWithoutAnAgentOnlyTheCatalogIsDescribed(): void
    {
        $tool = $this->makeMcpTool($this->makeIntegration());
        $this->connectAgent($this->makeAgent(), $tool);

        $state = new McpQuery()->serverState($tool, []);

        // Enough for the UI to pick a Connect button or a token field, and nothing about any agent.
        $this->assertSame('fakevendor', $state['vendor']);
        $this->assertSame(['bearer'], $state['auth_methods']);
        $this->assertFalse($state['url_per_connection']);
        $this->assertNull($state['auth']);
        $this->assertFalse($state['connected']);
        $this->assertSame([], $state['tools']);
    }

    public function testAnAgentThatNeverConnectedReadsAsNotConnected(): void
    {
        $tool = $this->makeMcpTool($this->makeIntegration());
        $this->connectAgent($this->makeAgent(), $tool);

        $state = new McpQuery()->serverState($tool, ['agent_id' => $this->makeAgent()->getId()]);

        $this->assertFalse($state['connected']);
        $this->assertNull($state['status']);
        $this->assertSame(0, $state['tool_count']);
    }

    public function testServerStateIsNullForEveryOtherToolType(): void
    {
        $this->assertNull(new McpQuery()->serverState($this->systemTool('read_my_ledger'), []));
    }

    public function testConnectionsListEveryAgentWithItsOwnState(): void
    {
        $integration = $this->makeIntegration();
        $tool = $this->makeMcpTool($integration);
        $healthy = $this->makeAgent();
        $broken = $this->makeAgent();
        $this->connectAgent($healthy, $tool);
        $this->connectAgent($broken, $tool);
        new McpConnectionService($broken, $integration)->markFailed('token revoked');

        $rows = new McpQuery()->connections(null, ['tool_id' => $tool->getId()]);

        $statusByAgent = collect($rows)->mapWithKeys(fn (array $row): array => [$row['agent']->getId() => $row['status']]);

        $this->assertCount(2, $rows);
        $this->assertSame(McpConnectionStatusEnum::ACTIVE->value, $statusByAgent[$healthy->getId()]);
        $this->assertSame(McpConnectionStatusEnum::FAILED->value, $statusByAgent[$broken->getId()]);
    }

    public function testRefreshingAnAgentWithoutAWorkingConnectionFailsWithSomethingAnAdminCanAct(): void
    {
        $tool = $this->makeMcpTool($this->makeIntegration());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('has no working connection');

        new McpMutation()->refreshTools(null, [
            'tool_id' => $tool->getId(),
            'agent_id' => $this->makeAgent()->getId(),
        ]);
    }

    public function testRefreshingANonMcpToolIsRefused(): void
    {
        $this->expectException(ValidationException::class);

        new McpMutation()->refreshTools(null, [
            'tool_id' => $this->systemTool('send_email')->getId(),
            'agent_id' => $this->makeAgent()->getId(),
        ]);
    }

    private function systemTool(string $name): Tool
    {
        $tool = new Tool();
        $tool->apps_id = 0;
        $tool->name = $name;
        $tool->tool_type = ToolTypeEnum::SYSTEM->value;
        $tool->frameworks = ['neuron'];
        $tool->version = '1.0.0';
        $tool->is_active = 1;
        $tool->is_deleted = 0;
        $tool->saveOrFail();

        return $tool;
    }
}
