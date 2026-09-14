<?php

declare(strict_types=1);

namespace Kanvas\Connectors\GoogleSheets\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\GoogleSheets\Client;
use Kanvas\Connectors\GoogleSheets\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mcp\Services\McpOAuthClientService;
use Kanvas\NervousSystem\Capability\DataTransferObject\ConnectorReadiness;
use Kanvas\NervousSystem\Capability\Services\SingleKeyConnectorReadiness;
use Kanvas\Workflow\Models\Integrations;
use Override;
use Throwable;

class GoogleSheetsReadinessService extends SingleKeyConnectorReadiness
{
    #[Override]
    public function slug(): string
    {
        return 'google-sheets';
    }

    #[Override]
    public function label(): string
    {
        return 'Google Sheets';
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function toolAreas(): array
    {
        return ['GoogleSheets'];
    }

    /**
     * Ready on either identity: the app-wide service account, or an agent signing in with its own
     * Google account through the Sheets MCP connection.
     *
     * Readiness is asked per app, never per agent, so the OAuth half can only answer "an agent COULD
     * connect here". An agent that has not is refused by the tool itself, which names the fix — a
     * cheaper failure than hiding tools that would have worked.
     */
    #[Override]
    public function readiness(AppInterface $app): ConnectorReadiness
    {
        $serviceAccount = parent::readiness($app);

        if ($serviceAccount->ready || ! $this->agentSignInConfigured($app)) {
            return $serviceAccount;
        }

        return ConnectorReadiness::ready($this->slug(), $this->label(), [
            $this->checkName() => false,
            'agent_google_sign_in' => true,
        ]);
    }

    #[Override]
    protected function configKey(): string
    {
        return ConfigurationEnum::GOOGLE_SHEETS_CREDENTIALS->value;
    }

    #[Override]
    protected function checkName(): string
    {
        return 'service_account';
    }

    #[Override]
    protected function setupInstruction(): string
    {
        return 'Google Sheets is not configured for this app — an admin must connect Google Sheets for the agent, '
            . 'or set a Google service-account JSON key in';
    }

    /** Whether this app holds the OAuth client an agent needs to connect its own Google account. */
    private function agentSignInConfigured(AppInterface $app): bool
    {
        $integration = Client::mcpIntegration();

        if (! $app instanceof Apps || ! $integration instanceof Integrations) {
            return false;
        }

        try {
            return new McpOAuthClientService($app, $integration)->stored() !== null;
        } catch (Throwable) {
            // A malformed server row must not turn "is this set up" into a failed turn.
            return false;
        }
    }
}
