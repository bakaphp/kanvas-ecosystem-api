<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Communication\Messages\Actions\DispatchDeliveryAction;
use Kanvas\Communication\Messages\Models\Delivery;

final class DispatchDeliveryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    /**
     * One attempt, on purpose. A second run cannot tell a vendor call that never happened from one
     * whose answer was lost, and guessing wrong sends the message twice.
     */
    public int $tries = 1;

    public function __construct(
        public readonly Delivery $delivery
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->delivery->app);

        new DispatchDeliveryAction($this->delivery)->execute();
    }
}
