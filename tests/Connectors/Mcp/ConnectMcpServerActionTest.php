<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\Mcp\Actions\ConnectMcpServerAction;
use Kanvas\Connectors\Mcp\DataTransferObject\McpServerConfig;
use Kanvas\Connectors\Mcp\Enums\McpAuthEnum;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;
use Kanvas\Connectors\Mcp\Enums\McpConnectionStatusEnum;
use Kanvas\Connectors\Mcp\Exceptions\McpAuthException;
use Kanvas\Connectors\Mcp\Services\McpConnectionService;
use Kanvas\Connectors\Mcp\Services\McpToolCacheService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\Capability\Actions\SetAgentToolAction;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Kanvas\NervousSystem\Capability\Models\Tool;
use NeuronAI\MCP\McpTransportInterface;

/**
 * Connecting is granting: one action proves an agent's credential against the server and turns the
 * tool on for that agent, and turning the tool off deletes the agent's credential.
 */
final class ConnectMcpServerActionTest extends McpTestCase
{
    public function testConnectingTurnsTheToolOnForTheAgentAndProvesTheCredential(): void
    {
        $integration = $this->makeIntegration();
        $tool = $this->makeMcpTool($integration);
        $agent = $this->makeAgent();

        $this->assertSame(2, $this->connectAgent($agent, $tool));

        $this->assertSame('agent-token', $this->credentials($agent, $integration)->rawToken());
        $this->assertContains($tool->getId(), $agent->selectedTools()->get()->modelKeys());

        $state = $this->connectionState($agent, $tool);
        $this->assertSame(McpConnectionStatusEnum::ACTIVE->value, $state['status']);
        $this->assertNotEmpty($state['connected_at']);

        // The probe doubles as the first snapshot write, so the agent's first turn is already warm.
        $snapshot = new McpToolCacheService(agent: $agent, integration: $integration)->snapshot();
        $this->assertSame(2, $snapshot?->tool_count);
    }

    public function testARejectedCredentialIsNotKept(): void
    {
        $integration = $this->makeIntegration();
        $tool = $this->makeMcpTool($integration);
        $agent = $this->makeAgent();

        try {
            $this->connectAgent($agent, $tool, $this->rejectingTransport());
            $this->fail('A credential the server rejected must not be reported as connected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertNull($this->credentials($agent, $integration)->rawToken());

        // The grant stays, marked failed with the reason, so the UI can say "reconnect" instead of the
        // tool silently vanishing.
        $state = $this->connectionState($agent, $tool);
        $this->assertSame(McpConnectionStatusEnum::FAILED->value, $state['status']);
        $this->assertNotEmpty($state['last_error']);
    }

    public function testTwoAgentsOnTheSameServerHoldSeparateCredentials(): void
    {
        $integration = $this->makeIntegration();
        $tool = $this->makeMcpTool($integration);
        $first = $this->makeAgent();
        $second = $this->makeAgent();

        $this->connectAgent($first, $tool, token: 'token-first');
        $this->connectAgent($second, $tool, token: 'token-second');

        $this->assertSame('token-first', $this->credentials($first, $integration)->rawToken());
        $this->assertSame('token-second', $this->credentials($second, $integration)->rawToken());
    }

    public function testTurningTheToolOffDeletesOnlyThatAgentsConnection(): void
    {
        $integration = $this->makeIntegration();
        $tool = $this->makeMcpTool($integration);
        $revoked = $this->makeAgent();
        $kept = $this->makeAgent();
        $this->connectAgent($revoked, $tool);
        $this->connectAgent($kept, $tool);

        new SetAgentToolAction(
            agent: $revoked,
            tool: $tool,
            enabled: false,
            actor: $this->mcpUser,
        )->execute();

        $this->assertNull($this->credentials($revoked, $integration)->rawToken());
        $this->assertNull(Cache::get(McpToolCacheService::cacheKeyFor(
            $revoked->apps_id,
            $revoked->companies_id,
            $revoked->getId(),
            $integration->getId(),
            $tool->version
        )));
        $this->assertFalse(new McpConnectionService($revoked, $integration)->isEnabled());

        $this->assertSame('agent-token', $this->credentials($kept, $integration)->rawToken());
        $this->assertTrue(new McpConnectionService($kept, $integration)->isEnabled());
    }

    public function testReconnectingDropsThePreviousGrantsRefreshTokenAndExpiry(): void
    {
        $integration = $this->makeIntegration();
        $tool = $this->makeMcpTool($integration);
        $agent = $this->makeAgent();
        $credentials = $this->credentials($agent, $integration);
        $credentials->store('old-token', 'old-refresh', 3600);

        $this->connectAgent($agent, $tool, token: 'new-token');

        $this->assertSame('new-token', $credentials->rawToken());
        $this->assertNull($credentials->refreshToken());
        $this->assertNull($credentials->expiresAt());
    }

    public function testAToolThatIsNotAnMcpServerIsRefused(): void
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

        new ConnectMcpServerAction(
            agent: $this->makeAgent(),
            tool: $tool,
            actor: $this->mcpUser,
            method: McpAuthEnum::BEARER,
            grant: ['token' => 'anything'],
        )->execute();
    }

    public function testAKeyIsRefusedOnAServerThatOnlyOffersOAuthAndNothingIsGranted(): void
    {
        $integration = $this->makeIntegration(['auth_methods' => ['oauth']]);
        $tool = $this->makeMcpTool($integration);
        $agent = $this->makeAgent();

        try {
            $this->connectAgent($agent, $tool);
            $this->fail('A key must not be accepted by a server that only offers OAuth.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('does not accept bearer', $e->getMessage());
        }

        $this->assertNull($this->credentials($agent, $integration)->rawToken());
        $this->assertNull($this->grantFor($agent, $tool));
    }

    public function testTheConnectionRemembersHowItWasMade(): void
    {
        $tool = $this->makeMcpTool($this->makeIntegration(['auth_methods' => ['bearer', 'oauth']]));
        $agent = $this->makeAgent();

        $this->connectAgent($agent, $tool);

        $this->assertSame('bearer', $this->connectionState($agent, $tool)['auth']);
    }

    public function testOnlyAnIntegrationTypedMcpIsReadAsAServer(): void
    {
        $integration = $this->makeIntegration();
        $integration->type = IntegrationTypeEnum::KEY->value;
        $integration->saveOrFail();

        // The metadata still looks like a server; the type is the one source of truth.
        $this->expectException(ValidationException::class);

        McpServerConfig::fromIntegration($integration);
    }

    private function rejectingTransport(): McpTransportInterface
    {
        return new class () implements McpTransportInterface {
            public function connect(): void
            {
            }

            public function send(array $data): void
            {
                throw new McpAuthException('MCP server rejected the credential (HTTP 401).');
            }

            public function receive(): array
            {
                return [];
            }

            public function disconnect(): void
            {
            }
        };
    }
}
