<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseCheckHealthAction;
use Kanvas\Intelligence\AgentRuntime\Enums\DeploymentStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Exceptions\AgentRuntimeUnreachableException;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\NervousSystem\Plan\Actions\Kanban\SyncDeploymentKanbanAction;
use Throwable;

/**
 * Mirrors one deployment's kanban board into Kanvas Plans/Tasks. overwriteAppService first —
 * the ingest creates Plans (SystemModule/Bouncer), so the worker scope must not leak.
 */
final class SyncDeploymentKanbanJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly AgentDeployment $deployment
    ) {
        $this->onQueue('agent-runtime');
    }

    public function handle(): void
    {
        try {
            $this->overwriteAppService($this->deployment->app);

            new SyncDeploymentKanbanAction($this->deployment)->execute();
        } catch (AgentRuntimeUnreachableException $e) {
            // Machine down or container reaped — expected for a dead deployment. Count it toward the
            // auto-flag threshold and stay out of Sentry instead of re-reporting every cron tick.
            $this->flagUnreachable($e->getMessage());
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function flagUnreachable(string $reason): void
    {
        $this->deployment->health_check_failures++;

        if ($this->deployment->health_check_failures >= BaseCheckHealthAction::MAX_CONSECUTIVE_FAILURES) {
            $this->deployment->status = DeploymentStatusEnum::FAILED->value;
            $this->deployment->error_message = 'Auto-flagged after '
                . $this->deployment->health_check_failures . ' consecutive unreachable kanban syncs';
        }

        $this->deployment->saveOrFail();

        Log::warning('Kanban sync skipped: agent runtime unreachable', [
            'deployment_id' => $this->deployment->getId(),
            'container_name' => $this->deployment->container_name,
            'failures' => $this->deployment->health_check_failures,
            'status' => $this->deployment->status,
            'reason' => $reason,
        ]);
    }
}
