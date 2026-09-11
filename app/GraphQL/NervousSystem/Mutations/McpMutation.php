<?php

declare(strict_types=1);

namespace App\GraphQL\NervousSystem\Mutations;

use App\GraphQL\Concerns\ResolvesActingContext;
use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Mcp\Actions\ConnectMcpServerAction;
use Kanvas\Connectors\Mcp\Actions\CreateMcpOAuthReceiverAction;
use Kanvas\Connectors\Mcp\DataTransferObject\McpServerConfig;
use Kanvas\Connectors\Mcp\Enums\McpAuthEnum;
use Kanvas\Connectors\Mcp\Services\McpCredentialService;
use Kanvas\Connectors\Mcp\Services\McpToolCacheService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Actions\SetAgentToolAction;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Workflow\Models\Integrations;
use Throwable;

class McpMutation
{
    use ResolvesActingContext;

    /**
     * Connect an MCP server for one agent. A key connects immediately. With no key, a server that offers
     * OAuth returns the consent URL a browser opens, and the vendor redirects back through
     * OAuthIntegrationController. Either way the agent ends up holding the tool — connecting is granting.
     *
     * @return array{url: string|null, connected: bool}
     */
    public function connectServer(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();
        [$tool, $integration] = $this->mcpToolOrFail((int) $request['tool_id'], $ctx->app);
        $agent = $this->agentOrFail((int) $request['agent_id']);

        SetAgentToolAction::assertMayHold($agent, $tool);

        $config = McpServerConfig::fromIntegration($integration);
        $serverUrl = $this->serverUrlFor($config, $agent, $integration, $request['server_url'] ?? null);
        $token = Str::trimToNull($request['token'] ?? null);

        if ($token === null && $config->supports(McpAuthEnum::OAUTH)) {
            return [
                'url' => new CreateMcpOAuthReceiverAction(
                    agent: $agent,
                    tool: $tool,
                    user: $ctx->user,
                    redirectUrl: $this->redirectUrlFrom($request['redirect_url'] ?? null),
                    serverUrl: $serverUrl,
                )->execute()->getOAuthUrl(),
                'connected' => false,
            ];
        }

        new ConnectMcpServerAction(
            agent: $agent,
            tool: $tool,
            actor: $ctx->user,
            method: McpAuthEnum::BEARER,
            grant: [
                'token' => $token,
                'server_url' => $serverUrl,
            ],
        )->execute();

        return [
            'url' => null,
            'connected' => true,
        ];
    }

    /**
     * The tool cache is long-lived on purpose; this is the admin's "look again now" after changing
     * something on the vendor side.
     */
    public function refreshTools(mixed $rootValue, array $request): bool
    {
        $ctx = $this->actingContext();
        [$tool, $integration] = $this->mcpToolOrFail((int) $request['tool_id'], $ctx->app);
        $agent = $this->agentOrFail((int) $request['agent_id']);

        $cache = new McpToolCacheService(
            agent: $agent,
            integration: $integration,
            toolVersion: $tool->version,
        );

        if (! $cache->connection()->isEnabled()) {
            throw new ValidationException(sprintf('%s has no working connection to that MCP server.', $agent->name));
        }

        try {
            $cache->refresh();
        } catch (Throwable $e) {
            throw new ValidationException('Could not refresh the tool list: ' . $e->getMessage());
        }

        return true;
    }

    /**
     * A reconnect may leave the address out and keep the one the agent already uses — the Reconnect
     * button should not make an admin retype it. Validated here too, before an OAuth receiver carries it
     * off to a consent screen.
     */
    private function serverUrlFor(
        McpServerConfig $config,
        Agent $agent,
        Integrations $integration,
        mixed $requested
    ): ?string {
        $requested = Str::trimToNull(is_string($requested) ? $requested : null);

        if (! $config->urlPerConnection) {
            if ($requested !== null) {
                throw new ValidationException(sprintf('"%s" has a fixed address; server_url is not accepted.', $integration->name));
            }

            return null;
        }

        return McpServerConfig::connectionUrl(
            $requested ?? new McpCredentialService($agent, $integration)->serverUrl(),
            $integration->name
        );
    }

    /**
     * @return array{0: Tool, 1: Integrations}
     */
    private function mcpToolOrFail(int $toolId, Apps $app): array
    {
        $tool = Tool::query()
            ->where('id', $toolId)
            ->fromAppOrGlobal($app)
            ->first();

        if ($tool === null || ! $tool->isMcp()) {
            throw new ValidationException(sprintf('Tool #%d is not an MCP server.', $toolId));
        }

        $integration = $tool->integration;

        if (! $integration instanceof Integrations) {
            throw new ValidationException('This MCP tool is not linked to an integration.');
        }

        return [$tool, $integration];
    }

    private function agentOrFail(int $agentId): Agent
    {
        $ctx = $this->actingContext();

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp($agentId, $ctx->company, $ctx->app);

        return $agent;
    }

    /**
     * The browser is sent here after the callback, so only an http(s) URL is accepted — rejected now,
     * where the admin sees why, rather than silently ignored after the vendor's consent screen.
     */
    private function redirectUrlFrom(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        if (filter_var($value, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['http', 'https'], true)) {
            throw new ValidationException('redirect_url must be an http(s) URL.');
        }

        return $value;
    }
}
