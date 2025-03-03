<?php

declare(strict_types=1);

namespace App\Console\Commands\Souk;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\Order;

class OrderFinishExpiredCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-souk:order-finish-expired {app_id?}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Finish expired orders';

    public function handle(): void
    {
        $appsId = $this->argument('apps_id');
        if (! $appsId) {
            $this->info('No app id provided, skipping');
            return;
        }

        $app = Apps::getById($appsId);
        $this->overwriteAppService($app);

        $ordersInProgress = Order::fromApp($app)->notDeleted()->whereNotFulfilled()->orderBy('id', 'desc')->get();

        foreach ($ordersInProgress as $order) {
            $endDate = $order->metadata['data']['end_date'] ?? null;
            if ($endDate && $endDate < now()->toDateTimeString()) {
                $this->finishOrdersExpiredOrder($order);
            }
        }
    }

    protected function finishOrdersExpiredOrder(Order $order): void
    {
        // get the variant
        $variant = $order->items[0]->variant;
        $channel = $variant->variantChannels()->first();
        $variantWarehouse = $channel?->productVariantWarehouse()->first();
        // Mark order as completed
        $order->fulfill();
        $variant->updateQuantityInWarehouse($variantWarehouse->warehouse, $variantWarehouse->quantity + 1);
    }
}
