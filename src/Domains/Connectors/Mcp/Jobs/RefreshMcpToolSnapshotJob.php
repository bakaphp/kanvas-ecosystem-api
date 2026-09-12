<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Connectors\Mcp\Services\McpToolCacheService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Models\Integrations;
use Throwable;

/**
 * Revalidates one agent's tool snapshot out of band, so a stale-but-serving turn never pays the round
 * trips itself. On the default queue on purpose: McpToolCacheService debounces the dispatch, so the
 * volume is a couple of jobs per hour per (agent, server) — too little to justify a dedicated worker.
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
        public readonly Agent $agent,
        public readonly Integrations $integration,
        public readonly string $toolVersion,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->agent->app);

        try {
            new McpToolCacheService(
                agent: $this->agent,
                integration: $this->integration,
                toolVersion: $this->toolVersion,
            )->refresh();
        } catch (Throwable) {
            // Not a fault: the cache already recorded it and the stale snapshot keeps serving.
        }
    }
}
