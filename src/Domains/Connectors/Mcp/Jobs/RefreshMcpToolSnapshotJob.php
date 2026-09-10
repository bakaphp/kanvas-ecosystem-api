<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mcp\Services\McpToolCacheService;
use Kanvas\Workflow\Models\Integrations;
use Throwable;

/**
 * Revalidates one company's descriptor snapshot out of band, so a stale-but-serving turn never pays
 * the three round trips itself.
 *
 * Deliberately on the DEFAULT queue: a dedicated queue would need a worker service added to all three
 * docker-compose files, and this fires at most once per soft-TTL per (company, server).
 *
 * Eloquent models on the constructor rather than a DTO — a Spatie Data object holding models flattens
 * on serialize and comes back with the typed properties uninitialized.
 */
class RefreshMcpToolSnapshotJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly Integrations $integration,
        public readonly string $toolVersion,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        try {
            new McpToolCacheService(
                app: $this->app,
                company: $this->company,
                integration: $this->integration,
                toolVersion: $this->toolVersion,
            )->refresh();
        } catch (Throwable) {
            // A revalidation that cannot reach the server is not a fault — the cache layer has already
            // recorded the failure and the stale snapshot keeps serving. Reporting here would page
            // someone every time a vendor has a slow minute.
        }
    }
}
