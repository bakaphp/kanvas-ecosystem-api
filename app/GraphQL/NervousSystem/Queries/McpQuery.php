<?php

declare(strict_types=1);

namespace App\GraphQL\NervousSystem\Queries;

use App\GraphQL\Concerns\ResolvesActingContext;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Mcp\DataTransferObject\McpServerConfig;
use Kanvas\Connectors\Mcp\Enums\McpAuthEnum;
use Kanvas\Connectors\Mcp\Services\McpConnectionService;
use Kanvas\Connectors\Mcp\Services\McpToolCacheService;
use Kanvas\Connectors\Mcp\Support\McpToolName;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Models\AgentTool;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Workflow\Models\Integrations;
use Throwable;

/**
 * An MCP server's methods are never catalog rows, so this reads the agent's snapshot rather than the
 * registry — no network, and it still answers while the vendor is down.
 */
class McpQuery
{
    use ResolvesActingContext;

    /**
     * Connection fields only for the agent in `agent_id` — every connection belongs to one agent.
     *
     * @return array<string, mixed>|null
     */
    public function serverState(mixed $rootValue, array $args): ?array
    {
        /** @var Tool $tool */
        $tool = $rootValue;

        if (! $tool->isMcp() || ! $tool->integration instanceof Integrations) {
            return null;
        }

        $integration = $tool->integration;
        $config = $this->configOf($integration);

        $catalog = [
            'vendor' => $config?->vendor,
            'auth_methods' => array_map(
                fn (McpAuthEnum $method): string => $method->value,
                $config?->authMethods ?? []
            ),
            'url_per_connection' => $config?->urlPerConnection ?? false,
        ];

        if (! isset($args['agent_id'])) {
            return [
                ...$catalog,
                ...$this->connectionFields([]),
                'connected' => false,
                'fetched_at' => null,
                'tool_count' => 0,
                'tools' => [],
            ];
        }

        $ctx = $this->actingContext();

        /** @var Agent $agent */
        $agent = Agent::getByIdFromCompanyApp((int) $args['agent_id'], $ctx->company, $ctx->app);

        $connection = new McpConnectionService($agent, $integration);
        $snapshot = new McpToolCacheService(
            agent: $agent,
            integration: $integration,
            toolVersion: $tool->version,
        )->snapshot();

        return [
            ...$catalog,
            ...$this->connectionFields(McpConnectionService::stateOf($connection->grant())),
            'connected' => $connection->isEnabled(),
            'fetched_at' => $snapshot?->fetched_at,
            'tool_count' => $snapshot?->tool_count ?? 0,
            'tools' => $this->describe($snapshot?->payload, $config?->prefix ?? $tool->name),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function connections(mixed $rootValue, array $args): array
    {
        $ctx = $this->actingContext();

        $tool = Tool::query()
            ->where('id', (int) $args['tool_id'])
            ->fromAppOrGlobal($ctx->app)
            ->first();

        if ($tool === null || ! $tool->isMcp()) {
            return [];
        }

        return AgentTool::query()
            ->where('tool_id', $tool->getId())
            ->fromApp($ctx->app)
            ->fromCompany($ctx->company)
            ->active()
            ->with('agent')
            ->get()
            ->toBase()
            ->filter(fn (AgentTool $grant): bool => $grant->agent !== null)
            ->map(fn (AgentTool $grant): array => [
                'agent' => $grant->agent,
                ...$this->connectionFields(McpConnectionService::stateOf($grant)),
            ])
            ->values()
            ->all();
    }

    /**
     * `connected_at` is stored as ISO-8601; the DateTime scalar wants a Carbon, not the string.
     *
     * @param array<string, mixed> $mcp
     * @return array<string, mixed>
     */
    private function connectionFields(array $mcp): array
    {
        $connectedAt = $mcp['connected_at'] ?? null;

        return [
            'auth' => $mcp['auth'] ?? null,
            'status' => $mcp['status'] ?? null,
            'connected_at' => is_string($connectedAt) && $connectedAt !== '' ? Carbon::parse($connectedAt) : null,
            'connected_as' => $mcp['connected_as'] ?? null,
            'last_error' => $mcp['last_error'] ?? null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function describe(mixed $payload, string $prefix): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $tools = [];

        foreach ($payload as $descriptor) {
            if (! is_array($descriptor) || ! isset($descriptor['name'])) {
                continue;
            }

            $remote = (string) $descriptor['name'];

            $tools[] = [
                'name' => McpToolName::format($prefix, $remote),
                'remote_name' => $remote,
                'description' => isset($descriptor['description']) ? (string) $descriptor['description'] : null,
            ];
        }

        return $tools;
    }

    private function configOf(Integrations $integration): ?McpServerConfig
    {
        try {
            return McpServerConfig::fromIntegration($integration);
        } catch (Throwable) {
            return null;
        }
    }
}
