<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Jobs;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Connectors\OpenClaw\Actions\CollectDeploymentUsageAction;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

/**
 * Queued job to collect usage data from a Docker-deployed OpenClaw agent.
 *
 * Can be dispatched after each chat or on a cron schedule to collect
 * session-level token usage (input, output, cache, model, context utilization)
 * and store it in AgentUsageSnapshot.
 */
class CollectDeploymentUsageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        protected AgentDeployment $deployment,
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected ?string $date = null,
    ) {
    }

    public function handle(): void
    {
        new CollectDeploymentUsageAction(
            $this->deployment,
            $this->app,
            $this->company,
            $this->date,
        )->execute();
    }
}
