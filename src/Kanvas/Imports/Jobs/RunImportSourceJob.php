<?php

declare(strict_types=1);

namespace Kanvas\Imports\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Imports\Actions\RunImportSourceAction;
use Kanvas\Imports\Models\ImportSource;

class RunImportSourceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    // Not retried: a partial run already recorded FAILED, and the next scheduled run starts clean.
    public int $tries = 1;
    public int $timeout = 1800;

    public function __construct(
        public readonly ImportSource $source,
    ) {
        $this->onQueue('imports');
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->source->app);

        new RunImportSourceAction($this->source)->execute();
    }
}
