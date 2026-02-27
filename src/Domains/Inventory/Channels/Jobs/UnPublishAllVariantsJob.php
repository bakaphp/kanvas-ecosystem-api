<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Channels\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Inventory\Channels\Actions\UnPublishAllVariantsAction;
use Kanvas\Inventory\Channels\Models\Channels;

class UnPublishAllVariantsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        protected Channels $channel,
    ) {
    }

    public function handle(): void
    {
        new UnPublishAllVariantsAction($this->channel)->execute();
    }
}
