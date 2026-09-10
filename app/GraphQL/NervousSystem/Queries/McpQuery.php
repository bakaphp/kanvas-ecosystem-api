<?php

declare(strict_types=1);

namespace App\GraphQL\NervousSystem\Queries;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mcp\DataTransferObject\McpServerConfig;
use Kanvas\Connectors\Mcp\Services\McpConnectionService;
use Kanvas\Connectors\Mcp\Services\McpToolCacheService;
use Kanvas\Connectors\Mcp\Support\McpToolName;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\Integrations;
use Throwable;

/**
 * "What can this agent actually do?" for an MCP grant.
 *
 * Under server-level grants the method list is deliberately never a set of catalog rows, so this reads
 * the durable snapshot rather than the registry — which is also why it costs no network and works while
 * the vendor is down. Nested under the server rather than flattened into `tools`, so "this one is
 * FAILED" and "last synced 3h ago" stay expressible.
 */
class McpQuery
{
    /**
     * @return array<string, mixed>|null
     */
    public function serverState(mixed $rootValue, array $args): ?array
    {
        /** @var Tool $tool */
        $tool = $rootValue;

        if (! $tool->isMcp() || $tool->integrations_id === null) {
            return null;
        }

        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = Companies::getById($user->getCurrentCompany()->getId());

        $integration = $tool->integration;

        if (! $integration instanceof Integrations) {
            return null;
        }

        $config = $this->configOf($integration);
        $connection = new McpConnectionService($app, $company, $integration);
        $row = $connection->integrationCompany();
        $snapshot = new McpToolCacheService(
            app: $app,
            company: $company,
            integration: $integration,
            toolVersion: $tool->version,
        )->snapshot();

        return [
            'vendor' => $config?->vendor,
            'connected' => $connection->isEnabled(),
            'status' => $row?->status->slug,
            'fetched_at' => $snapshot?->fetched_at,
            'tool_count' => $snapshot?->tool_count ?? 0,
            'tools' => $this->describe($snapshot?->payload, $config?->prefix ?? $tool->name),
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
