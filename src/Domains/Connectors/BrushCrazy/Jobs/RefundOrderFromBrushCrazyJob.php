<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\BrushCrazy\Actions\RefundOrderFromBrushCrazyAction;
use Kanvas\Souk\Orders\Models\Order;

/**
 * Dispatched by the mirror after its write commits, one per pending refund identity. Safe to run
 * twice: the action only acts on an entry it can move from pending to processing.
 */
class RefundOrderFromBrushCrazyJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public $tries = 3;

    public $backoff = 60;

    public $afterCommit = true;

    public function __construct(
        public readonly Apps $app,
        public readonly Order $order,
        public readonly string $refundKey,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        new RefundOrderFromBrushCrazyAction($this->order, $this->refundKey)->execute();
    }
}
