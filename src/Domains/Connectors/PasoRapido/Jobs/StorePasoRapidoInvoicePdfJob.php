<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PasoRapido\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Connectors\PasoRapido\Actions\StorePasoRapidoInvoicePdfAction;
use Kanvas\Souk\Orders\Models\Order;

final class StorePasoRapidoInvoicePdfJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly Order $order,
    ) {
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->order->app);

        new StorePasoRapidoInvoicePdfAction($this->order)->execute();
    }
}
