<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\OAuth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Contracts\OAuthProviderInterface;
use Kanvas\Connectors\Mcp\Actions\ConnectMcpServerAction;
use Kanvas\Connectors\Mcp\DataTransferObject\McpServerConfig;
use Kanvas\Connectors\Mcp\Enums\McpAuthEnum;
use Kanvas\Connectors\Mcp\Services\McpOAuthClientService;
use Kanvas\Connectors\Mcp\Services\McpOAuthDiscoveryService;
use Kanvas\Connectors\Mcp\Services\McpOAuthService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Actions\SetAgentToolAction;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Workflow\Models\ReceiverWebhook;
use NeuronAI\MCP\McpTransportInterface;
use Override;

/**
 * One agent's OAuth connection to an MCP server through the shared OAuthIntegrationController: discovered
 * endpoints, a dynamically registered public client, PKCE. The receiver carries the agent, the tool and,
 * for a self-hosted server, `server_url` through the consent screen.
 *
 * The PKCE verifier is cached under `state`, and that lookup doubles as the state check — the shared
 * controller never compares `state` to its nonce, so without it a callback carrying someone else's code
 * would be accepted.
 */
class McpOAuthProvider implements OAuthProviderInterface
{
    private const string VERIFIER_KEY_PREFIX = 'mcp_oauth_pkce:';

    /** Matches the lifetime OAuthIntegrationController gives its own state entry. */
    private const int STATE_TTL_SECONDS = 1800;

    #[Override]
    public function getAuthorizationUrl(
        ReceiverWebhook $receiver,
        Apps $app,
        Request $request,
        string $nonce
    ): string {
        [$agent, $tool, $integration] = $this->connectionFor($receiver);
        SetAgentToolAction::assertMayHold($agent, $tool);

        $serverUrl = $this->serverUrl($receiver);
        $redirectUri = $this->redirectUri($receiver);
        $endpoints = new McpOAuthDiscoveryService($integration, $serverUrl)->endpoints();
        $client = new McpOAuthClientService($app, $integration, $serverUrl)->clientFor($redirectUri);
        $oauth = McpServerConfig::fromIntegration($integration)->oauth;
        $verifier = Str::random(96);

        Cache::put(self::VERIFIER_KEY_PREFIX . $nonce, $verifier, self::STATE_TTL_SECONDS);

        // Vendor extras go first so they can never displace the security parameters after them.
        $params = [
            ...(is_array($oauth['authorize_params'] ?? null) ? $oauth['authorize_params'] : []),
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => $redirectUri,
            'state' => $nonce,
            'code_challenge' => $this->challengeFor($verifier),
            'code_challenge_method' => 'S256',
        ];

        if ($endpoints['resource'] !== null) {
            $params['resource'] = $endpoints['resource'];
        }

        $scope = $this->scopeFrom($oauth['scopes'] ?? null);

        if ($scope !== null) {
            $params['scope'] = $scope;
        }

        $separator = str_contains($endpoints['authorization_endpoint'], '?') ? '&' : '?';

        return $endpoints['authorization_endpoint'] . $separator . http_build_query($params);
    }

    #[Override]
    public function handleCallback(
        ReceiverWebhook $receiver,
        Apps $app,
        Request $request
    ): array {
        $error = $request->input('error');

        if (is_string($error) && $error !== '') {
            throw new ValidationException(trim(
                'MCP authorization was declined: ' . $error . ' ' . (string) $request->input('error_description', '')
            ));
        }

        $state = (string) $request->input('state', '');
        $verifier = $state === '' ? null : Cache::pull(self::VERIFIER_KEY_PREFIX . $state);

        if (! is_string($verifier) || $verifier === '') {
            throw new ValidationException(
                'This OAuth callback does not match a connection started here, or it expired. Start the connection again.'
            );
        }

        $code = trim((string) $request->input('code', ''));

        if ($code === '') {
            throw new ValidationException('The MCP server returned no authorization code.');
        }

        [$agent, $tool, $integration] = $this->connectionFor($receiver);
        $serverUrl = $this->serverUrl($receiver);

        $grant = new McpOAuthService($agent, $integration, $serverUrl)->exchangeCode($code, $this->redirectUri($receiver), $verifier);

        $toolCount = new ConnectMcpServerAction(
            agent: $agent,
            tool: $tool,
            actor: $receiver->user ?? $agent->user,
            method: McpAuthEnum::OAUTH,
            grant: [
                'token' => $grant['access_token'],
                'refresh_token' => $grant['refresh_token'],
                'expires_in' => $grant['expires_in'],
                'server_url' => $serverUrl,
            ],
            transport: $this->transportFor(),
        )->execute();

        return [
            'message' => sprintf('Connected the %s MCP server for %s.', $tool->name, $agent->name),
            'tools' => $toolCount,
        ];
    }

    #[Override]
    public function getStateKeyPrefix(): string
    {
        return 'mcp_oauth';
    }

    /** Overridden in tests to probe through a fake transport; production always uses the guarded one. */
    protected function transportFor(): ?McpTransportInterface
    {
        return null;
    }

    /**
     * The agent is looked up inside the receiver's own company, so a tampered receiver can never connect
     * a server for another tenant's agent.
     *
     * @return array{0: Agent, 1: Tool, 2: Integrations}
     */
    private function connectionFor(ReceiverWebhook $receiver): array
    {
        $configuration = $this->configuration($receiver);

        $agent = Agent::query()
            ->where('id', (int) ($configuration['agents_id'] ?? 0))
            ->fromApp($receiver->app)
            ->fromCompany($receiver->company)
            ->notDeleted()
            ->first();

        $tool = Tool::query()
            ->where('id', (int) ($configuration['tool_id'] ?? 0))
            ->fromAppOrGlobal($receiver->app)
            ->first();

        $integration = $tool?->integration;

        if (! $agent instanceof Agent || ! $tool instanceof Tool || ! $tool->isMcp() || ! $integration instanceof Integrations) {
            throw new ValidationException('This OAuth receiver is not linked to an agent and an MCP server. Start the connection again.');
        }

        if (! McpServerConfig::fromIntegration($integration)->supports(McpAuthEnum::OAUTH)) {
            throw new ValidationException(sprintf('"%s" does not offer OAuth.', $integration->name));
        }

        return [$agent, $tool, $integration];
    }

    private function serverUrl(ReceiverWebhook $receiver): ?string
    {
        $serverUrl = $this->configuration($receiver)['server_url'] ?? null;

        return is_string($serverUrl) && $serverUrl !== '' ? $serverUrl : null;
    }

    private function redirectUri(ReceiverWebhook $receiver): string
    {
        $override = $this->configuration($receiver)['oauth_callback_url'] ?? null;

        return is_string($override) && $override !== '' ? $override : $receiver->getOAuthCallbackUrl();
    }

    /**
     * @return array<string, mixed>
     */
    private function configuration(ReceiverWebhook $receiver): array
    {
        return is_array($receiver->configuration) ? $receiver->configuration : [];
    }

    private function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function scopeFrom(mixed $scopes): ?string
    {
        if (is_array($scopes) && $scopes !== []) {
            return implode(' ', array_map('strval', $scopes));
        }

        return is_string($scopes) && trim($scopes) !== '' ? trim($scopes) : null;
    }
}
