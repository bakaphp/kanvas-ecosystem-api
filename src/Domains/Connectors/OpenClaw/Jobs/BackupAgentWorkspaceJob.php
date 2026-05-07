<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Connectors\OpenClaw\Actions\BackupAgentWorkspaceAction;
use Kanvas\Intelligence\Agents\Models\AgentBackup;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

class BackupAgentWorkspaceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        protected AgentDeployment $deployment,
        protected AgentBackup $backup,
        protected bool $includeWorkspace = true,
    ) {
    }

    public function handle(): void
    {
        new BackupAgentWorkspaceAction(
            $this->deployment,
            $this->backup,
            $this->includeWorkspace,
        )->execute();
    }
}
