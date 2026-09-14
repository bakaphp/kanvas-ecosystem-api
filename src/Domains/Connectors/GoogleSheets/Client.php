<?php

declare(strict_types=1);

namespace Kanvas\Connectors\GoogleSheets;

use Baka\Contracts\AppInterface;
use Google\Client as GoogleApiClient;
use Google\Service\Sheets as GoogleSheetsService;
use Kanvas\Connectors\GoogleSheets\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mcp\Services\McpConnectionService;
use Kanvas\Connectors\Mcp\Services\McpCredentialService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Models\Integrations;
use Throwable;

/** Thin factory for an authenticated Google Sheets API service: the agent's own Google account where it has one, otherwise the app's configured service account. */
class Client
{
    /** The MCP server row whose OAuth connection doubles as an agent's own Google sign-in. */
    private const string MCP_INTEGRATION = 'google_sheets_mcp';

    public static function getInstance(AppInterface $app): GoogleSheetsService
    {
        $raw = $app->get(ConfigurationEnum::GOOGLE_SHEETS_CREDENTIALS->value);

        // The custom-fields store round-trips a JSON string back as an already-decoded array —
        // accept either shape rather than assuming set() always comes back as the string it was given.
        $decoded = match (true) {
            is_array($raw) => $raw,
            is_string($raw) && $raw !== '' => json_decode($raw, true),
            default => null,
        };

        if ($decoded === null) {
            throw new ValidationException(
                'Google Sheets is not configured for this app — set GOOGLE_SHEETS_CREDENTIALS to a service-account JSON key.'
            );
        }

        if (! is_array($decoded)) {
            throw new ValidationException('Google Sheets credentials are not valid JSON.');
        }

        try {
            $googleClient = new GoogleApiClient();
            $googleClient->setAuthConfig($decoded);
            $googleClient->addScope(GoogleSheetsService::SPREADSHEETS);

            $impersonateUser = trim((string) ($app->get(ConfigurationEnum::IMPERSONATE_USER->value) ?? ''));
            if ($impersonateUser !== '') {
                $googleClient->setSubject($impersonateUser);
            }

            return new GoogleSheetsService($googleClient);
        } catch (Throwable $e) {
            throw new ValidationException('Could not authenticate with Google Sheets: ' . $e->getMessage());
        }
    }

    /**
     * The agent's OWN Google account, from the Sheets MCP connection it signed in with.
     *
     * Null when the agent has no connection, so a caller falls back to the app-wide service account —
     * the two identities are not interchangeable: the service account reaches only sheets explicitly
     * shared with it, the agent's account reaches whatever the person who signed in can open.
     *
     * The token is read at call time because `McpCredentialService::token()` renews an expired one.
     */
    public static function forAgent(Agent $agent): ?GoogleSheetsService
    {
        $integration = self::mcpIntegration();

        if (! $integration instanceof Integrations) {
            return null;
        }

        if (! new McpConnectionService($agent, $integration)->isEnabled()) {
            return null;
        }

        $token = new McpCredentialService($agent, $integration)->token();

        if ($token === null) {
            return null;
        }

        $googleClient = new GoogleApiClient();
        $googleClient->setAccessToken($token);

        return new GoogleSheetsService($googleClient);
    }

    /** The Sheets MCP server row, which is global (apps_id 0) and may not be installed at all. */
    public static function mcpIntegration(): ?Integrations
    {
        return Integrations::query()
            ->where('name', self::MCP_INTEGRATION)
            ->where('is_deleted', 0)
            ->first();
    }
}
