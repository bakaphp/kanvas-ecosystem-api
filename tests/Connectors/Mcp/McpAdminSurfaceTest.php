<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use App\GraphQL\NervousSystem\Mutations\McpMutation;
use App\GraphQL\NervousSystem\Queries\McpQuery;
use Kanvas\Connectors\Mcp\Services\McpConnectionService;
use Kanvas\Connectors\Mcp\Services\McpToolCacheService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Workflow\Models\Integrations;
use Tests\Stubs\Connectors\Mcp\FakeMcpServer;

final class McpAdminSurfaceTest extends McpTestCase
{
    public function testServerStateListsTheMethodsWithoutMakingThemCatalogRows(): void
    {
        $integration = $this->makeIntegration();
        $this->enableForCompany($integration);
        $this->warm($integration);
        $tool = $this->makeMcpTool($integration);

        $state = new McpQuery()->serverState($tool, []);

        $this->assertTrue($state['connected']);
        $this->assertSame(2, $state['tool_count']);
        $this->assertCount(2, $state['tools']);

        // Both names matter: one is what the model calls, the other is what the server answers to.
        $names = array_column($state['tools'], 'name');
        $remote = array_column($state['tools'], 'remote_name');
        $this->assertContains('fake__createJiraIssue', $names);
        $this->assertContains('createJiraIssue', $remote);
    }

    public function testServerStateAnswersEvenWhenTheCompanyNeverConnectedIt(): void
    {
        $integration = $this->makeIntegration();
        $tool = $this->makeMcpTool($integration);

        $state = new McpQuery()->serverState($tool, []);

        // A flat tool list could not express this; nested state can say "granted but not connected".
        $this->assertFalse($state['connected']);
        $this->assertSame(0, $state['tool_count']);
        $this->assertSame([], $state['tools']);
    }

    public function testServerStateIsNullForEveryOtherToolType(): void
    {
        $tool = new Tool();
        $tool->apps_id = 0;
        $tool->name = 'read_my_ledger';
        $tool->tool_type = ToolTypeEnum::SYSTEM->value;
        $tool->frameworks = ['neuron'];
        $tool->version = '1.0.0';
        $tool->is_active = 1;
        $tool->is_deleted = 0;
        $tool->saveOrFail();

        $this->assertNull(new McpQuery()->serverState($tool, []));
    }

    public function testRefreshingAnUnconnectedServerFailsWithSomethingAnAdminCanAct(): void
    {
        $integration = $this->makeIntegration();
        $tool = $this->makeMcpTool($integration);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('has not connected');

        new McpMutation()->refreshTools(null, ['tool_id' => $tool->getId()]);
    }

    public function testRefreshingANonMcpToolIsRefused(): void
    {
        $tool = new Tool();
        $tool->apps_id = 0;
        $tool->name = 'send_email';
        $tool->tool_type = ToolTypeEnum::SYSTEM->value;
        $tool->frameworks = ['neuron'];
        $tool->version = '1.0.0';
        $tool->is_active = 1;
        $tool->is_deleted = 0;
        $tool->saveOrFail();

        $this->expectException(ValidationException::class);

        new McpMutation()->refreshTools(null, ['tool_id' => $tool->getId()]);
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
