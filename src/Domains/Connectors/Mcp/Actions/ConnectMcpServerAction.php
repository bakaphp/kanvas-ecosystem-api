<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\Mcp\DataTransferObject\McpServerConfig;
use Kanvas\Connectors\Mcp\Enums\McpAuthEnum;
use Kanvas\Connectors\Mcp\Exceptions\McpAuthException;
use Kanvas\Connectors\Mcp\Services\McpConnectionService;
use Kanvas\Connectors\Mcp\Services\McpCredentialService;
use Kanvas\Connectors\Mcp\Services\McpToolCacheService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Actions\SetAgentToolAction;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\Integrations;
use NeuronAI\MCP\McpTransportInterface;
use Throwable;

/**
 * The only way an agent's MCP connection is made, by key or by OAuth. Connecting grants the tool
 * (revoking it deletes the credential, see AgentToolObserver), and the credential is proven with a real
 * `tools/list`, which also warms the first snapshot.
 */
class ConnectMcpServerAction
{
    /**
     * @param array{token?: string|null, refresh_token?: string|null, expires_in?: int|string|null, server_url?: string|null} $grant
     */
    public function __construct(
        private readonly Agent $agent,
        private readonly Tool $tool,
        private readonly Users $actor,
        private readonly McpAuthEnum $method,
        private readonly array $grant,
        private readonly ?McpTransportInterface $transport = null,
    ) {
    }

    /**
     * @return int how many tools the server exposes to this agent
     */
    public function execute(): int
    {
        $integration = $this->tool->integration;

        if (! $this->tool->isMcp() || ! $integration instanceof Integrations) {
            throw new ValidationException(sprintf('Tool "%s" is not an MCP server.', $this->tool->name));
        }

        $config = McpServerConfig::fromIntegration($integration);

        if (! $config->supports($this->method)) {
            throw new ValidationException(sprintf(
                '"%s" does not accept %s connections.',
                $this->tool->name,
                $this->method->value
            ));
        }

        // Checked before anything is granted, so a bad address leaves the agent exactly as it was.
        $serverUrl = $config->urlPerConnection
            ? McpServerConfig::connectionUrl($this->grant['server_url'] ?? null, $this->tool->name)
            : null;

        $token = Str::trimToNull($this->grant['token'] ?? null);

        // A `none` server carries its own credential in its address — Browserbase puts the API key in the
        // query string — or wants none at all, so there is no token to demand.
        if ($token === null && $this->method !== McpAuthEnum::NONE) {
            throw new ValidationException('An MCP access token is required.');
        }

        // Granted first: the grant enforces who may hold MCP at all, and granting can hard-delete stale
        // duplicate rows whose delete forgets the agent's credential — storing before that would lose it.
        new SetAgentToolAction(
            agent: $this->agent,
            tool: $this->tool,
            enabled: true,
            actor: $this->actor,
        )->execute();

        $credentials = new McpCredentialService($this->agent, $integration);

        // forget() first: this is a NEW grant, and a previous grant's expiry must not outlive it.
        $credentials->forget();
        $credentials->store(
            accessToken: $token,
            refreshToken: Str::trimToNull($this->grant['refresh_token'] ?? null),
            expiresIn: is_numeric($this->grant['expires_in'] ?? null) ? (int) $this->grant['expires_in'] : null,
            serverUrl: $serverUrl,
        );

        $connection = new McpConnectionService($this->agent, $integration, $this->transport);

        try {
            $descriptors = $connection->fetchDescriptors();
        } catch (McpAuthException $e) {
            $this->fail($credentials, $connection, $e);

            throw new ValidationException('The MCP server rejected that credential: ' . $e->getMessage());
        } catch (Throwable $e) {
            $this->fail($credentials, $connection, $e);

            throw new ValidationException('Could not reach the MCP server: ' . $e->getMessage());
        }

        $connection->recordConnected($this->method);
        $this->warmSnapshot($integration, $connection, $descriptors);

        return count($descriptors);
    }

    /**
     * The grant stays, marked failed with the reason — the agent shows as "needs reconnecting" rather
     * than silently losing the tool — but the rejected credential goes, so nothing can replay it.
     */
    private function fail(McpCredentialService $credentials, McpConnectionService $connection, Throwable $e): void
    {
        $credentials->forget();
        $connection->markFailed($e->getMessage());
    }

    /**
     * @param list<array<string, mixed>> $descriptors
     */
    private function warmSnapshot(Integrations $integration, McpConnectionService $connection, array $descriptors): void
    {
        try {
            new McpToolCacheService(
                agent: $this->agent,
                integration: $integration,
                toolVersion: $this->tool->version,
                connection: $connection,
            )->store($descriptors);
        } catch (Throwable) {
            // A cold cache is a slow first turn, not a failed connection — the credential is proven
            // either way, and refusing the connection over a cache write would be the wrong trade.
        }
    }
}
