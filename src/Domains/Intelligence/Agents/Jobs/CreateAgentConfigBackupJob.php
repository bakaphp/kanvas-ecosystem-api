<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\CreateAgentConfigBackupAction;
use Kanvas\Intelligence\Agents\Models\Agent;

class CreateAgentConfigBackupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public Agent $agent,
        public Apps $app,
        public ?string $notes = null,
    ) {
        $this->onQueue('agent-runtime');
    }

    public function handle(): void
    {
        (new CreateAgentConfigBackupAction($this->agent, $this->app, $this->notes))->execute();
    }
}
