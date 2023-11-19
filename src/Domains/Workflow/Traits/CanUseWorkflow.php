<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Traits;

trait CanUseWorkflow
{
    protected bool $enableWorkflows = true;

    public function fireWorkflow(
        string $event,
        bool $async = true,
        array $params = []
    ): void {
        if (! $this->enableWorkflows) {
            return;
        }
    }
}
