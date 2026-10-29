<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Actions\RunConnectionCleanupAction;
use Kanvas\Communication\Connections\Models\Connection;

/**
 * The connection is not `$connection`: Queueable already owns that name for the queue connection.
 *
 * One attempt; what is left is retried by the sweep, which also recovers a worker that died with a
 * task leased.
 */
final class RunConnectionCleanupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly Apps $app,
        public readonly Connection $communicationConnection
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        new RunConnectionCleanupAction($this->communicationConnection)->execute();
    }
}
