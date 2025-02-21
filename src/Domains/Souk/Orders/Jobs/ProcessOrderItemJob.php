<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Orders\Services\OrderItemService;
use Kanvas\Users\Models\Users;

class ProcessOrderItemJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use KanvasJobsTrait;

    public function __construct(
        public Apps $app,
        public Users $user,
        public Companies $currentUserCompany,
        public array $orderItems,
        public int $channelId,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);
        $cart = app('cart')->session($this->user->getId());

        $orderItemService = new OrderItemService($this->app, $this->user, $this->currentUserCompany);
        $orderItemService->processOrderItems($this->orderItems, $this->channelId, $cart);
    }
}
