<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\OpenClaw;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenClaw\Actions\ChatWithAgentAction;
use Kanvas\Connectors\OpenClaw\Actions\DeployAgentAction;
use Kanvas\Connectors\OpenClaw\Actions\GetGatewayLogsAction;
use Kanvas\Connectors\OpenClaw\Actions\ListAgentsAction;
use Kanvas\Connectors\OpenClaw\Actions\RemoveAgentAction;
use Kanvas\Connectors\OpenClaw\Actions\UpdateAgentDeploymentAction;
use Kanvas\Connectors\OpenClaw\Enums\CustomFieldEnum;
use Kanvas\Connectors\OpenClaw\Services\WorkspaceFileBuilder;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Tests\Connectors\Traits\HasOpenClawConfiguration;
use Tests\TestCase;

class OpenClawTest extends TestCase
{
    use HasOpenClawConfiguration;

    /** @var array<int, Agent> */
    private array $deployedAgents = [];

    protected function tearDown(): void
    {
        if (! empty($this->deployedAgents) && $this->hasOpenClawCredentials()) {
            $app = app(Apps::class);
            $company = auth()->user()->getCurrentCompany();
            $this->setupOpenClawConfiguration($company);

            foreach ($this->deployedAgents as $agent) {
                try {
                    new RemoveAgentAction($agent, $app, $company)->execute();
                } catch (\Throwable) {
                }
            }
            $this->deployedAgents = [];
        }

        parent::tearDown();
    }

    protected function createTestAgent(array $overrides = []): Agent
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(array_merge([
                'soul' => 'You are a helpful test assistant.',
                'instructions' => 'Step 1: Greet. Step 2: Help.',
                'output_format' => 'Respond in plain text.',
                'identity' => ['name' => 'TestBot', 'emoji' => '🤖', 'vibe' => 'friendly'],
                'user_context' => 'User is testing the system.',
                'tools_config' => 'Use search tool for lookups.',
                'deployment_status' => 'pending',
            ], $overrides));
    }

    public function testWorkspaceFileBuilderSoulMd()
    {
        $agent = $this->createTestAgent();
        $content = WorkspaceFileBuilder::buildSoulMd($agent);

        $this->assertStringContainsString('# SOUL', $content);
        $this->assertStringContainsString('You are a helpful test assistant.', $content);
        $this->assertStringContainsString('## Output Format', $content);
        $this->assertStringContainsString('Respond in plain text.', $content);
    }

    public function testWorkspaceFileBuilderAgentsMd()
    {
        $agent = $this->createTestAgent();
        $content = WorkspaceFileBuilder::buildAgentsMd($agent);

        $this->assertStringContainsString('# AGENTS', $content);
        $this->assertStringContainsString('Step 1: Greet. Step 2: Help.', $content);
    }

    public function testWorkspaceFileBuilderIdentityMd()
    {
        $agent = $this->createTestAgent();
        $content = WorkspaceFileBuilder::buildIdentityMd($agent);

        $this->assertStringContainsString('# IDENTITY', $content);
        $this->assertStringContainsString('**Name:** TestBot', $content);
        $this->assertStringContainsString('**Vibe:** friendly', $content);
    }

    public function testWorkspaceFileBuilderUserMd()
    {
        $agent = $this->createTestAgent();
        $content = WorkspaceFileBuilder::buildUserMd($agent);

        $this->assertStringContainsString('# USER', $content);
        $this->assertStringContainsString('User is testing the system.', $content);
    }

    public function testWorkspaceFileBuilderToolsMd()
    {
        $agent = $this->createTestAgent();
        $content = WorkspaceFileBuilder::buildToolsMd($agent);

        $this->assertStringContainsString('# TOOLS', $content);
        $this->assertStringContainsString('Use search tool for lookups.', $content);
    }

    public function testWorkspaceFileBuilderBuildAll()
    {
        $agent = $this->createTestAgent();
        $files = WorkspaceFileBuilder::buildAll($agent);

        $this->assertArrayHasKey('SOUL.md', $files);
        $this->assertArrayHasKey('AGENTS.md', $files);
        $this->assertArrayHasKey('IDENTITY.md', $files);
        $this->assertArrayHasKey('USER.md', $files);
        $this->assertArrayHasKey('TOOLS.md', $files);
    }

    public function testWorkspaceFileBuilderSkipsEmptyUserAndTools()
    {
        $agent = $this->createTestAgent([
            'user_context' => null,
            'tools_config' => null,
        ]);

        $files = WorkspaceFileBuilder::buildAll($agent);

        $this->assertArrayHasKey('SOUL.md', $files);
        $this->assertArrayHasKey('AGENTS.md', $files);
        $this->assertArrayHasKey('IDENTITY.md', $files);
        $this->assertArrayNotHasKey('USER.md', $files);
        $this->assertArrayNotHasKey('TOOLS.md', $files);
    }

    public function testWorkspaceFileBuilderFallsBackToLegacyRole()
    {
        $agent = $this->createTestAgent([
            'soul' => null,
            'instructions' => null,
            'output_format' => null,
            'role' => [
                'background' => 'Legacy background text',
                'steps' => 'Legacy step instructions',
            ],
        ]);

        $soulMd = WorkspaceFileBuilder::buildSoulMd($agent);
        $agentsMd = WorkspaceFileBuilder::buildAgentsMd($agent);

        $this->assertStringContainsString('Legacy background text', $soulMd);
        $this->assertStringContainsString('Legacy step instructions', $agentsMd);
        $this->assertStringNotContainsString('## Output Format', $soulMd);
    }

    public function testDeployUpdateChatAndRemoveAgent()
    {
        if (! $this->hasOpenClawCredentials()) {
            $this->markTestSkipped('OpenClaw SSH credentials not configured');
        }

        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $this->setupOpenClawConfiguration($company);

        $agent = $this->createTestAgent();
        $this->deployedAgents[] = $agent;

        $result = new DeployAgentAction($agent, $app, $company)->execute();

        $this->assertEquals('deployed', $result->deployment_status);
        $this->assertNotEmpty($result->get(CustomFieldEnum::OPENCLAW_AGENT_ID->value));
        $this->assertNotEmpty($result->get(CustomFieldEnum::OPENCLAW_WORKSPACE_PATH->value));

        $agent->update(['soul' => 'Updated soul content for testing.']);
        $updateResult = new UpdateAgentDeploymentAction($agent, $app, $company)->execute();
        $this->assertEquals('deployed', $updateResult->deployment_status);

        $response = new ChatWithAgentAction($agent, $app, $company, 'Hello, how are you?')->execute();
        $this->assertNotEmpty($response);
        $this->assertIsString($response);

        $removeResult = new RemoveAgentAction($agent, $app, $company)->execute();
        $this->assertTrue($removeResult);
        $agent->refresh();
        $this->assertEquals('pending', $agent->deployment_status);

        $this->deployedAgents = [];
    }

    public function testGatewayOperations()
    {
        if (! $this->hasOpenClawCredentials()) {
            $this->markTestSkipped('OpenClaw SSH credentials not configured');
        }

        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $this->setupOpenClawConfiguration($company);

        $logs = new GetGatewayLogsAction($app, $company, 10)->execute();
        $this->assertIsString($logs);

        $agents = new ListAgentsAction($app, $company)->execute();
        $this->assertIsArray($agents);
    }

    public function testRemoveAgentWithoutOpenClawIdReturnsTrue()
    {
        $app = app(Apps::class);
        $agent = $this->createTestAgent();
        $company = auth()->user()->getCurrentCompany();

        $result = new RemoveAgentAction($agent, $app, $company)->execute();

        $this->assertTrue($result);
    }

    public function testChatWithAgentWithoutOpenClawIdThrowsException()
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Agent has not been deployed to OpenClaw');

        $app = app(Apps::class);
        $agent = $this->createTestAgent();
        $company = auth()->user()->getCurrentCompany();

        new ChatWithAgentAction($agent, $app, $company, 'Hello')->execute();
    }
}
