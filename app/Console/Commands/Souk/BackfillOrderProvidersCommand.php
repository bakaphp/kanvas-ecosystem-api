<?php

declare(strict_types=1);

namespace App\Console\Commands\Souk;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Actions\SyncOrderProvidersAction;
use Kanvas\Souk\Orders\Models\Order;

class BackfillOrderProvidersCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-souk:backfill-order-providers {app_id}';

    protected $description = 'Backfill the order_providers pivot table for existing orders';

    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $orders = Order::fromApp($app)
            ->notDeleted()
            ->with('items.variant.product')
            ->orderBy('id')
            ->cursor();

        $synced = 0;
        foreach ($orders as $order) {
            new SyncOrderProvidersAction($order)->execute();
            $synced++;

            if ($synced % 100 === 0) {
                $this->info("Synced {$synced} orders...");
            }
        }

        $this->info("Done. Backfilled {$synced} orders for app {$app->name}.");
    }
}
