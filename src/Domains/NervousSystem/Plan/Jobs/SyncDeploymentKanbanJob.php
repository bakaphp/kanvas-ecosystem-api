<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\NervousSystem\Plan\Actions\Kanban\SyncDeploymentKanbanAction;
use Throwable;

/**
 * Mirrors one deployment's kanban board into Kanvas Plans/Tasks. Runs on the agent-runtime queue.
 * `overwriteAppService` first — the ingest touches Bouncer-scoped models (SystemModule registration
 * inside CreatePlanAction), and the worker is long-lived, so the previous job's scope must not leak.
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
        } catch (Throwable $e) {
            report($e);
        }
    }
}
