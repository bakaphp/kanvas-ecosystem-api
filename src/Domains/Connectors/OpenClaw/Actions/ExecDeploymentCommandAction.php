<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Kanvas\Connectors\OpenClaw\Jobs\ExecDeploymentCommandJob;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseExecDeploymentCommandAction;
use Override;

class ExecDeploymentCommandAction extends BaseExecDeploymentCommandAction
{
    #[Override]
    protected function dispatchExecJob(string $sanitizedCommand): void
    {
        ExecDeploymentCommandJob::dispatch(
            $this->deployment,
            $sanitizedCommand,
            $this->sessionId,
        );
    }
}
