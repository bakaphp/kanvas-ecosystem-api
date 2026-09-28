<?php

declare(strict_types=1);

namespace Kanvas\Guild\Duplicates\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Duplicates\Actions\CheckPeopleDuplicateOnCreateAction;

class CheckPeopleDuplicateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Apps $app,
        public readonly int $peopleId,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $people = People::query()->where('id', $this->peopleId)->first();
        if ($people === null) {
            return;
        }

        new CheckPeopleDuplicateOnCreateAction($people)->execute();
    }
}
