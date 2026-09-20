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
use Kanvas\Connectors\Mcp\Actions\FollowMcpAsyncJobAction;
use Kanvas\Connectors\Mcp\DataTransferObject\McpAsyncJobConfig;
use Kanvas\NervousSystem\Capability\Enums\McpAsyncJobStatusEnum;
use Kanvas\NervousSystem\Capability\Models\McpAsyncJob;
use Throwable;

/**
 * Checks a running MCP job once, then queues itself again after the job's poll interval until the job
 * ends. A fresh dispatch per check rather than `release()`, so a long job never exhausts the tries.
 */
class PollMcpAsyncJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly Apps $app,
        public readonly McpAsyncJob $asyncJob,
    ) {
        $this->onQueue('agent-chat');
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $job = new FollowMcpAsyncJobAction($this->asyncJob)->execute();

        if (! $job->isRunning()) {
            return;
        }

        $pollSeconds = McpAsyncJobConfig::forJob($job->integration, $job->start_tool)?->pollSeconds
            ?? McpAsyncJobConfig::DEFAULT_POLL_SECONDS;

        self::dispatch($this->app, $job)->delay(now()->addSeconds($pollSeconds));
    }

    /**
     * Without this a crash mid-check leaves the row running forever and the agent never hears back.
     */
    public function failed(Throwable $exception): void
    {
        $job = $this->asyncJob->fresh();

        if ($job === null || ! $job->isRunning()) {
            return;
        }

        $job->finish(McpAsyncJobStatusEnum::FAILED, error: $exception->getMessage());

        ResumeAgentFromMcpAsyncJob::dispatch($this->app, $job);
    }
}
