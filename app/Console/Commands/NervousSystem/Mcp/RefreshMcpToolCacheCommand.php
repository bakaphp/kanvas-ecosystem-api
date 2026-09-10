<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Mcp;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mcp\Services\McpToolCacheService;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Throwable;

/**
 * Re-primes every connected company's descriptor snapshot on a schedule, so cold-start cost lands in
 * the background instead of on somebody's first chat of the day.
 */
class RefreshMcpToolCacheCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:mcp:refresh-tool-cache {--integration= : Limit to one integrations_id}';

    protected $description = 'Refresh the cached MCP tool list for every company with a connected MCP server.';

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
            ->get();

        if ($tools->isEmpty()) {
            $this->info('No MCP tools registered.');

            return self::SUCCESS;
        }

        $refreshed = 0;
        $failed = 0;

        foreach ($tools as $tool) {
            $integration = $tool->integration;

            if ($integration === null) {
                continue;
            }

            $rows = IntegrationsCompany::query()
                ->where('integrations_id', $integration->getId())
                ->where('is_active', 1)
                ->where('is_deleted', 0)
                ->get();

            foreach ($rows as $row) {
                $company = Companies::query()->where('id', $row->companies_id)->first();

                if ($company === null) {
                    continue;
                }

                $app = Apps::query()->where('id', $tool->apps_id > 0 ? $tool->apps_id : $company->apps_id)->first();

                if (! $app instanceof Apps) {
                    continue;
                }

                // Mandatory when iterating apps/companies: the worker and this process are long-lived,
                // and Bouncer's scope plus the container-bound Apps both survive between iterations —
                // omitting it silently serves every later tenant under the first one's scope.
                $this->overwriteAppService($app);

                try {
                    new McpToolCacheService(
                        app: $app,
                        company: $company,
                        integration: $integration,
                        toolVersion: $tool->version,
                    )->refresh();

                    $refreshed++;
                } catch (Throwable $e) {
                    $failed++;
                    $this->warn(sprintf(
                        'company %d / %s: %s',
                        $company->getId(),
                        $integration->name,
                        $e->getMessage()
                    ));
                }
            }
        }

        $this->info(sprintf('Refreshed %d MCP tool list(s), %d failed.', $refreshed, $failed));

        return self::SUCCESS;
    }
}
