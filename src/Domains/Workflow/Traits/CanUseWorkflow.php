<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Actions\ProcessWorkflowEventAction;

trait CanUseWorkflow
{
    protected bool $enableWorkflows = true;

    public function fireWorkflow(
        string $event,
        bool $async = true,
        array $params = []
    ): mixed {
        if (! $this->enableWorkflows) {
            return null;
        }
        $app = ($params['app'] ?? null) instanceof Apps ? $params['app'] : app(Apps::class); // look for a better way to get app
        $processWorkflow = new ProcessWorkflowEventAction($app, $this);

        return $processWorkflow->execute($event, $params);
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
