<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\OpenClaw;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenClaw\Actions\ChatWithAgentAction;
use Kanvas\Connectors\OpenClaw\Actions\DeployAgentAction;
use Kanvas\Connectors\OpenClaw\Actions\RemoveAgentAction;
use Kanvas\Connectors\OpenClaw\Actions\UpdateAgentDeploymentAction;
use Kanvas\Connectors\OpenClaw\Enums\CustomFieldEnum;
use Kanvas\Connectors\OpenClaw\Services\WorkspaceFileBuilder;
use Kanvas\Intelligence\Agents\Models\Agent;
use Tests\Connectors\Traits\HasOpenClawConfiguration;
use Tests\TestCase;

class OpenClawTest extends TestCase
{
    use HasOpenClawConfiguration;

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

    public function testDeployAgent()
    {
        if (! $this->hasOpenClawCredentials()) {
            $this->markTestSkipped('OpenClaw SSH credentials not configured');
        }

        $company = auth()->user()->getCurrentCompany();
        $this->setupOpenClawConfiguration($company);

        $agent = $this->createTestAgent();

        $result = new DeployAgentAction($agent, $company)->execute();

        $this->assertEquals('deployed', $result->deployment_status);
        $this->assertNotEmpty($result->get(CustomFieldEnum::OPENCLAW_AGENT_ID->value));
        $this->assertNotEmpty($result->get(CustomFieldEnum::OPENCLAW_WORKSPACE_PATH->value));
    }

    public function testUpdateAgentDeployment()
    {
        if (! $this->hasOpenClawCredentials()) {
            $this->markTestSkipped('OpenClaw SSH credentials not configured');
        }

        $company = auth()->user()->getCurrentCompany();
        $this->setupOpenClawConfiguration($company);

        $agent = $this->createTestAgent(['deployment_status' => 'deployed']);
        $agent->set(CustomFieldEnum::OPENCLAW_AGENT_ID->value, $agent->uuid);
        $agent->set(CustomFieldEnum::OPENCLAW_WORKSPACE_PATH->value, '/opt/openclaw/workspaces/' . $agent->uuid);

        $agent->update(['soul' => 'Updated soul content.']);

        $result = new UpdateAgentDeploymentAction($agent, $company)->execute();

        $this->assertEquals('deployed', $result->deployment_status);
    }

    public function testChatWithAgent()
    {
        if (! $this->hasOpenClawCredentials()) {
            $this->markTestSkipped('OpenClaw SSH credentials not configured');
        }

        $company = auth()->user()->getCurrentCompany();
        $this->setupOpenClawConfiguration($company);

        $agent = $this->createTestAgent(['deployment_status' => 'deployed']);
        $agent->set(CustomFieldEnum::OPENCLAW_AGENT_ID->value, $agent->uuid);

        $response = new ChatWithAgentAction(
            $agent,
            $company,
            'Hello, how are you?'
        )->execute();

        $this->assertNotEmpty($response);
        $this->assertIsString($response);
    }

    public function testRemoveAgent()
    {
        if (! $this->hasOpenClawCredentials()) {
            $this->markTestSkipped('OpenClaw SSH credentials not configured');
        }

        $company = auth()->user()->getCurrentCompany();
        $this->setupOpenClawConfiguration($company);

        $agent = $this->createTestAgent(['deployment_status' => 'deployed']);
        $agent->set(CustomFieldEnum::OPENCLAW_AGENT_ID->value, $agent->uuid);

        $result = new RemoveAgentAction($agent, $company)->execute();

        $this->assertTrue($result);
        $agent->refresh();
        $this->assertEquals('pending', $agent->deployment_status);
    }

    public function testRemoveAgentWithoutOpenClawIdReturnsTrue()
    {
        $agent = $this->createTestAgent();
        $company = auth()->user()->getCurrentCompany();

        $result = new RemoveAgentAction($agent, $company)->execute();

        $this->assertTrue($result);
    }

    public function testChatWithAgentWithoutOpenClawIdThrowsException()
    {
        $this->expectException(\Kanvas\Exceptions\ValidationException::class);
        $this->expectExceptionMessage('Agent has not been deployed to OpenClaw');

        $agent = $this->createTestAgent();
        $company = auth()->user()->getCurrentCompany();

        new ChatWithAgentAction($agent, $company, 'Hello')->execute();
    }
}
