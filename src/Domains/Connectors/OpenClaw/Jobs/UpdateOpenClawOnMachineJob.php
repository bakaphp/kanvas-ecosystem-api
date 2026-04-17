<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Connectors\OpenClaw\Actions\UpdateOpenClawOnMachineAction;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Scan the machine for OpenClaw installations and dispatch one
 * UpdateOpenClawForUserJob per user so each update runs independently.
 */
class UpdateOpenClawOnMachineJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 30;

    public int $tries = 1;

    public function __construct(
        protected AgentMachine $machine,
    ) {
    }

    public function handle(): void
    {
        $users = new UpdateOpenClawOnMachineAction($this->machine)->execute();

        foreach ($users as $user) {
            UpdateOpenClawForUserJob::dispatch($this->machine, $user);
        }
    }
}
