<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Mcp;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Connectors\Mcp\Services\McpToolCacheService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Kanvas\NervousSystem\Capability\Models\AgentTool;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Throwable;

/**
 * Re-primes every connected agent's tool snapshot on a schedule, so cold-start cost lands in the
 * background instead of on somebody's first chat of the day.
 */
class RefreshMcpToolCacheCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:mcp:refresh-tool-cache {--integration= : Limit to one integrations_id}';

    protected $description = 'Refresh the cached MCP tool list for every agent with a working MCP connection.';

    public function handle(): int
    {
        $tools = Tool::query()
            ->where('tool_type', ToolTypeEnum::MCP->value)
            ->whereNotNull('integrations_id')
            ->active()
            ->when(
                $this->option('integration') !== null,
                fn ($query) => $query->where('integrations_id', (int) $this->option('integration'))
            )
            ->get()
            ->keyBy('id');

        if ($tools->isEmpty()) {
            $this->info('No MCP tools registered.');

            return self::SUCCESS;
        }

        $grants = AgentTool::query()
            ->whereIn('tool_id', $tools->keys()->all())
            ->active()
            ->with('agent')
            ->get();

        $refreshed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($grants as $grant) {
            $tool = $tools[$grant->tool_id];
            $integration = $tool->integration;
            $agent = $grant->agent;

            if ($integration === null || ! $agent instanceof Agent || $agent->is_deleted) {
                continue;
            }

            // Per iteration: the worker's Bouncer scope and bound app would otherwise leak between tenants.
            $this->overwriteAppService($agent->app);

            $cache = new McpToolCacheService(
                agent: $agent,
                integration: $integration,
                toolVersion: $tool->version,
            );

            // A failed or credential-less connection waits on a person to reconnect; dialling would 401.
            if (! $cache->connection()->isEnabled()) {
                $skipped++;

                continue;
            }

            try {
                $cache->refresh();
                $refreshed++;
            } catch (Throwable $e) {
                $failed++;
                $this->warn(sprintf('agent %d / %s: %s', $agent->getId(), $integration->name, $e->getMessage()));
            }
        }

        $this->info(sprintf('Refreshed %d MCP tool list(s), %d skipped, %d failed.', $refreshed, $skipped, $failed));

        return self::SUCCESS;
    }
}
