<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use Kanvas\Connectors\Mcp\Actions\ConnectMcpServerAction;
use Kanvas\Connectors\Mcp\Enums\McpAuthEnum;
use Kanvas\Connectors\Mcp\Services\McpConnectionService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Tests\Stubs\Connectors\Mcp\FakeMcpServer;

/**
 * Servers that take no token: Browserbase carries its API key in the query string, and a self-hosted
 * Playwright server may want nothing at all. For these the address IS the credential, so the token the
 * other paths demand would be an invented value with nowhere to go.
 */
final class McpKeylessConnectionTest extends McpTestCase
{
    // A literal public IP, like McpServerUrlPerConnectionTest: the address only has to survive the SSRF
    // guard, and a hostname that never resolves does not.
    private const string URL_WITH_KEY = 'https://8.8.4.4/mcp?browserbaseApiKey=secret-in-the-address';

    public function testAServerThatTakesNoTokenConnectsOnItsAddressAlone(): void
    {
        [$agent, $tool] = $this->keylessServer();

        $count = new ConnectMcpServerAction(
            agent: $agent,
            tool: $tool,
            actor: $this->mcpUser,
            method: McpAuthEnum::NONE,
            grant: ['server_url' => self::URL_WITH_KEY],
            transport: FakeMcpServer::listing(FakeMcpServer::twoTools()),
        )->execute();

        $this->assertSame(2, $count);
        $this->assertSame(McpAuthEnum::NONE->value, $this->connectionState($agent, $tool)['auth'] ?? null);
    }

    public function testSuchAConnectionCountsAsEnabledEvenWithNoTokenStored(): void
    {
        [$agent, $tool] = $this->keylessServer();

        new ConnectMcpServerAction(
            agent: $agent,
            tool: $tool,
            actor: $this->mcpUser,
            method: McpAuthEnum::NONE,
            grant: ['server_url' => self::URL_WITH_KEY],
            transport: FakeMcpServer::listing(FakeMcpServer::twoTools()),
        )->execute();

        $credentials = $this->credentials($agent, $tool->integration);

        // The whole point: nothing to look for but the address, which the transport reads at send time.
        $this->assertNull($credentials->rawToken());
        $this->assertSame(self::URL_WITH_KEY, $credentials->serverUrl());
        $this->assertTrue(new McpConnectionService($agent, $tool->integration)->isEnabled());
    }

    public function testTheAddressIsStillRequired(): void
    {
        [$agent, $tool] = $this->keylessServer();

        $this->expectException(ValidationException::class);

        new ConnectMcpServerAction(
            agent: $agent,
            tool: $tool,
            actor: $this->mcpUser,
            method: McpAuthEnum::NONE,
            grant: [],
            transport: FakeMcpServer::listing(FakeMcpServer::twoTools()),
        )->execute();
    }

    public function testAServerThatWantsAKeyStillRefusesAnEmptyOne(): void
    {
        $integration = $this->makeIntegration(['auth_methods' => ['bearer']]);
        $tool = $this->makeMcpTool($integration);
        $agent = $this->makeAgent();

        $this->expectExceptionMessage('An MCP access token is required.');

        new ConnectMcpServerAction(
            agent: $agent,
            tool: $tool,
            actor: $this->mcpUser,
            method: McpAuthEnum::BEARER,
            grant: ['token' => '   '],
            transport: FakeMcpServer::listing(FakeMcpServer::twoTools()),
        )->execute();
    }

    public function testAKeylessConnectionIsRefusedOnAServerThatDoesNotOfferIt(): void
    {
        $integration = $this->makeIntegration(['auth_methods' => ['bearer'], 'url_per_connection' => true]);
        $tool = $this->makeMcpTool($integration);
        $agent = $this->makeAgent();

        $this->expectExceptionMessage('does not accept none connections');

        new ConnectMcpServerAction(
            agent: $agent,
            tool: $tool,
            actor: $this->mcpUser,
            method: McpAuthEnum::NONE,
            grant: ['server_url' => self::URL_WITH_KEY],
            transport: FakeMcpServer::listing(FakeMcpServer::twoTools()),
        )->execute();
    }

    /**
     * @return array{0: Agent, 1: Tool}
     */
    private function keylessServer(): array
    {
        $integration = $this->makeIntegration([
            'auth_methods' => ['none'],
            'url_per_connection' => true,
        ]);

        return [$this->makeAgent(), $this->makeMcpTool($integration)];
    }
}
