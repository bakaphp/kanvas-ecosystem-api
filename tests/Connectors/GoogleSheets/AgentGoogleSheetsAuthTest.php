<?php

declare(strict_types=1);

namespace Tests\Connectors\GoogleSheets;

use Kanvas\Connectors\GoogleSheets\Client;
use Kanvas\Connectors\GoogleSheets\Services\GoogleSheetsReadinessService;
use Kanvas\Connectors\Mcp\Enums\ConfigurationEnum as McpConfigurationEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\GoogleSheets\CreateGoogleSpreadsheetTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\GoogleSheets\ReadGoogleSheetTool;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Kanvas\NervousSystem\Capability\Models\Tool as CapabilityTool;
use Kanvas\Workflow\Models\Integrations;
use ReflectionMethod;
use Tests\Connectors\Mcp\McpTestCase;

/**
 * The second Google identity a sheets tool can act with: the agent's own account, from the Sheets MCP
 * connection, instead of the app-wide service account.
 *
 * Extends the MCP case for its connection fixtures — the credential lives on the agent's custom fields,
 * on a connection this suite is careful to roll back.
 */
final class AgentGoogleSheetsAuthTest extends McpTestCase
{
    /** The shared Google OAuth client id lives in app settings, which Redis keeps beyond the transaction. */
    private mixed $originalClientId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalClientId = $this->mcpApp->get($this->clientIdKey());
    }

    protected function tearDown(): void
    {
        $this->mcpApp->set($this->clientIdKey(), $this->originalClientId);

        parent::tearDown();
    }

    public function testAnAgentWithNoConnectionFallsBackToTheServiceAccount(): void
    {
        $this->assertNull(
            Client::forAgent($this->makeAgent()),
            'With no Google connection the client must be null so the action keeps using the service account.'
        );
    }

    public function testAConnectedAgentActsAsItsOwnGoogleAccount(): void
    {
        [$agent] = $this->connectedSheetsAgent();

        $service = Client::forAgent($agent);

        $this->assertNotNull($service, 'A connected agent must get a client built from its own token.');
        $this->assertNotNull($service->spreadsheets_values);
    }

    public function testAToolTakesTheAgentsAccountWhenItHasOne(): void
    {
        [$agent] = $this->connectedSheetsAgent();

        $tool = new ReadGoogleSheetTool()->withContext(
            $this->mcpApp,
            $this->mcpCompany,
            $this->mcpUser,
            $agent
        );

        $this->assertNotNull($this->resolvedService($tool));
    }

    public function testAToolWithNoAgentInContextUsesTheServiceAccount(): void
    {
        $tool = new ReadGoogleSheetTool()->withContext($this->mcpApp, $this->mcpCompany, $this->mcpUser);

        $this->assertNull($this->resolvedService($tool));
    }

    public function testCreatingASpreadsheetRefusesWithoutTheAgentsOwnAccount(): void
    {
        $result = new CreateGoogleSpreadsheetTool()
            ->withContext(
                $this->mcpApp,
                $this->mcpCompany,
                $this->mcpUser,
                $this->makeAgent()
            )
            ->__invoke(title: 'Q3 Invoices');

        // A spreadsheet made by the service account is owned by an account no person can open, so the
        // refusal is the honest answer rather than a "created it" the user cannot act on.
        $this->assertFalse($result['success']);
        $this->assertSame('no_google_account_connected', $result['reason']);
    }

    public function testCreatingASpreadsheetNeedsATitle(): void
    {
        $result = new CreateGoogleSpreadsheetTool()
            ->withContext(
                $this->mcpApp,
                $this->mcpCompany,
                $this->mcpUser,
                $this->makeAgent()
            )
            ->__invoke(title: '   ');

        $this->assertFalse($result['success']);
        $this->assertSame('title_required', $result['reason']);
    }

    public function testTheConnectorReadsAsReadyOnTheAgentSignInAlone(): void
    {
        $this->mcpApp->set($this->clientIdKey(), 'google-oauth-client-id.apps.googleusercontent.com');

        $readiness = new GoogleSheetsReadinessService()->readiness($this->mcpApp);

        // Without this, ToolGrantResolver refuses the sheets tools and the agent is told Google Sheets
        // is not set up — while its own connection would have worked.
        $this->assertTrue($readiness->ready);
        $this->assertFalse($readiness->checks['service_account']);
    }

    public function testTheConnectorIsNotReadyWithNeitherIdentity(): void
    {
        $this->mcpApp->set($this->clientIdKey(), '');

        $readiness = new GoogleSheetsReadinessService()->readiness($this->mcpApp);

        $this->assertFalse($readiness->ready);
        $this->assertNotSame([], $readiness->issues);
    }

    /**
     * @return array{0: Agent, 1: CapabilityTool}
     */
    private function connectedSheetsAgent(): array
    {
        $integration = Client::mcpIntegration();

        $this->assertInstanceOf(
            Integrations::class,
            $integration,
            'The google_sheets_mcp server row ships in a migration — without it agents cannot sign in to Google.'
        );

        $tool = CapabilityTool::query()
            ->where('tool_type', ToolTypeEnum::MCP->value)
            ->where('integrations_id', $integration->getId())
            ->first();

        $this->assertInstanceOf(CapabilityTool::class, $tool);

        $agent = $this->makeAgent();
        $this->grantWithCredential($agent, $tool, 'google-access-token');

        return [$agent, $tool];
    }

    private function resolvedService(object $tool): mixed
    {
        return new ReflectionMethod($tool, 'sheetsServiceForAgent')->invoke($tool);
    }

    private function clientIdKey(): string
    {
        // The Workspace servers share one hand-made client, keyed by `metadata.oauth.client_key`.
        return McpConfigurationEnum::OAUTH_CLIENT_ID_PREFIX->value . 'google';
    }
}
