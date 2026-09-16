<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use App\GraphQL\NervousSystem\Mutations\McpMutation;
use Kanvas\Connectors\Internal\Jobs\OAuthCallbackJob;
use Kanvas\Connectors\Mcp\Enums\McpConnectionStatusEnum;
use Kanvas\Connectors\Mcp\Handlers\McpHandler;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesAgent;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;

final class McpConnectMutationTest extends McpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        WorkflowAction::firstOrCreate(
            ['model_name' => OAuthCallbackJob::class],
            ['name' => 'OAuth Callback']
        );
    }

    public function testAnOAuthServerAnswersWithTheConsentUrlOfAReceiverForThatAgent(): void
    {
        $tool = $this->oauthTool();
        $agent = $this->makeAgent();

        $result = $this->connectServer($tool, $agent);

        $this->assertFalse($result['connected']);
        $this->assertMatchesRegularExpression('#/v1/oauth/[0-9a-f-]{36}$#', $result['url']);

        $configuration = $this->receiverOf($result['url'])->configuration;
        $this->assertSame('mcp', $configuration['oauth_provider']);
        $this->assertSame($tool->getId(), (int) $configuration['tool_id']);
        $this->assertSame($agent->getId(), (int) $configuration['agents_id']);
    }

    public function testConnectingTheSameAgentTwiceReusesItsReceiver(): void
    {
        $tool = $this->oauthTool();
        $agent = $this->makeAgent();

        $this->assertSame($this->connectServer($tool, $agent)['url'], $this->connectServer($tool, $agent)['url']);
    }

    public function testEachAgentGetsItsOwnReceiver(): void
    {
        $tool = $this->oauthTool();

        $this->assertNotSame(
            $this->connectServer($tool, $this->makeAgent())['url'],
            $this->connectServer($tool, $this->makeAgent())['url']
        );
    }

    public function testATokenServerNeedsAToken(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('access token is required');

        $this->connectServer($this->makeMcpTool($this->makeIntegration()), $this->makeAgent());
    }

    public function testATokenThatCannotBeProvenIsNotKept(): void
    {
        // mcp.example.test never resolves, so the probe fails the way an unreachable vendor does.
        $integration = $this->makeIntegration();
        $tool = $this->makeMcpTool($integration);
        $agent = $this->makeAgent();

        try {
            $this->connectServer($tool, $agent, ['token' => 'ghp_pasted']);
            $this->fail('An unproven token must not be reported as connected.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('MCP server', $e->getMessage());
        }

        $this->assertNull($this->credentials($agent, $integration)->rawToken());
        $this->assertSame(McpConnectionStatusEnum::FAILED->value, $this->connectionState($agent, $tool)['status']);
    }

    public function testAServerOfferingBothAnswersWithTheConsentUrlWhenNoKeyIsGiven(): void
    {
        $tool = $this->makeMcpTool($this->makeIntegration(['auth_methods' => ['bearer', 'oauth']]));

        $result = $this->connectServer($tool, $this->makeAgent());

        $this->assertFalse($result['connected']);
        $this->assertNotNull($result['url']);
    }

    public function testAServerOfferingBothTakesTheKeyPathWhenAKeyIsGiven(): void
    {
        // mcp.example.test never resolves, so reaching the probe — and failing it — proves the key path ran.
        $tool = $this->makeMcpTool($this->makeIntegration(['auth_methods' => ['bearer', 'oauth']]));
        $agent = $this->makeAgent();

        try {
            $this->connectServer($tool, $agent, ['token' => 'service-account-key']);
            $this->fail('An unproven key must not be reported as connected.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('MCP server', $e->getMessage());
        }

        $this->assertSame(McpConnectionStatusEnum::FAILED->value, $this->connectionState($agent, $tool)['status']);
    }

    public function testAKeyIsRefusedOnAServerThatOnlyOffersOAuth(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('does not accept bearer');

        $this->connectServer($this->oauthTool(), $this->makeAgent(), ['token' => 'pasted']);
    }

    public function testANonHttpRedirectIsRefusedBeforeTheConsentScreen(): void
    {
        $this->expectException(ValidationException::class);

        $this->connectServer($this->oauthTool(), $this->makeAgent(), ['redirect_url' => 'javascript:alert(1)']);
    }

    public function testACustomerFacingAgentIsRefused(): void
    {
        $this->expectException(ValidationException::class);

        $this->connectServer($this->oauthTool(), $this->makeAgent(SalesAgent::class));
    }

    public function testTheGenericIntegrationsFormIsPointedAtThePerAgentFlow(): void
    {
        $handler = new McpHandler(
            $this->mcpApp,
            $this->mcpCompany,
            new Regions(),
            ['token' => 'would-have-been-company-wide'],
            $this->makeIntegration()
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('connectNervousSystemMcpServer');

        $handler->setup();
    }

    /**
     * @param array<string, mixed> $extra
     * @return array{url: string|null, connected: bool}
     */
    private function connectServer(Tool $tool, Agent $agent, array $extra = []): array
    {
        return new McpMutation()->connectServer(null, [
            'tool_id' => $tool->getId(),
            'agent_id' => $agent->getId(),
            ...$extra,
        ]);
    }

    private function oauthTool(): Tool
    {
        return $this->makeMcpTool($this->makeIntegration(['auth_methods' => ['oauth']]));
    }

    private function receiverOf(string $url): ReceiverWebhook
    {
        $receiver = ReceiverWebhook::query()->where('uuid', substr($url, strrpos($url, '/') + 1))->first();
        $this->assertNotNull($receiver);

        return $receiver;
    }
}
