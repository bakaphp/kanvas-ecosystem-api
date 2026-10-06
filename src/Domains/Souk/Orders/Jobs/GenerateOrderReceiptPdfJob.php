<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Souk\Orders\Actions\GenerateOrderReceiptAction;
use Kanvas\Souk\Orders\DataTransferObject\OrderReceipt;
use Kanvas\Souk\Orders\Models\Order;

class GenerateOrderReceiptPdfJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Order $order,
        public UserInterface $user,
        public ?OrderReceipt $receipt = null,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->order->app);

        new GenerateOrderReceiptAction($this->order, $this->user, $this->receipt)->execute();
    }
}
