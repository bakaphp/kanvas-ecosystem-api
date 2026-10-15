<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Traits;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Actions\ProcessWorkflowEventAction;
use Kanvas\Workflow\SyncWorkflowStub;

trait CanUseWorkflow
{
    protected bool $enableWorkflows = true;

    public function fireWorkflow(
        string $event,
        bool $async = true,
        array $params = []
    ): ?SyncWorkflowStub {
        if (! $this->enableWorkflows) {
            return null;
        }
        $app = ($params['app'] ?? null) instanceof Apps ? $params['app'] : app(Apps::class); // look for a better way to get app
        $processWorkflow = new ProcessWorkflowEventAction($app, $this);

        return $processWorkflow->execute($event, $params);
    }

    /**
     * A connector activity may push this record to a third party, so it must not run against (or be
     * rolled back with) a write that has not committed on the model's own connection yet.
     */
    public function fireWorkflowAfterCommit(string $event, array $params = []): void
    {
        DB::connection($this->getConnectionName())->afterCommit(
            fn () => $this->fireWorkflow($event, params: $params)
        );
    }

    /**
     * Enable workflows.
     */
    public function enableWorkflows(): void
    {
        $this->enableWorkflows = true;
    }

    /**
     * Disable workflows.
     */
    public function disableWorkflows(): void
    {
        $this->enableWorkflows = false;
    }
}
