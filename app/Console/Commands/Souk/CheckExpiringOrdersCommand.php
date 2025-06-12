<?php

declare(strict_types=1);

namespace App\Console\Commands\Souk;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Models\Settings;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Models\Order;

class CheckExpiringOrdersCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-souk:check-expiring-orders {app_id?}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Finish expired orders';

    public function handle(): void
    {
        $appsId = $this->argument('app_id');

        if ($appsId) {
            $this->checkApps($appsId);
        } else {
            $appsIds = Settings::where([
                'name' => ConfigurationEnum::CHECK_EXPIRED_ORDERS->value,
                'value' => '1',
            ])->select('apps_id')->get()->pluck('apps_id');
            $this->info('Checking ' . $appsIds->count() . ' apps');
            foreach ($appsIds as $appsId) {
                $this->checkApps($appsId);
                $this->info('Checked ' . $appsId);
            }
        }
    }


    protected function notifyExpiringOrders(Order $order, $appTimeZone): void {
        $orderEndTime = $order->metadata['data']['end_at'];
        $parkingTimeZone = $order->items->first(function ($item) {
            return $item->variant->first()?->attributes->first(fn ($attribute) => $attribute->key === 'timezone')?->value;
        })?->variant?->attributes?->first(fn ($attribute) => $attribute->key === 'timezone')?->value;

        $orderEndTime = Carbon::parse($orderEndTime, $parkingTimeZone ?? $appTimeZone);
        $minutesUntilExpiry = now()->diffInMinutes($orderEndTime, false);

        if ($minutesUntilExpiry === 5) {
            $this->info('Order ' . $order->id . ' expires in ' . $minutesUntilExpiry . ' minutes');
        } elseif ($minutesUntilExpiry === 15) {
            $this->info('Order ' . $order->id . ' expires in ' . $minutesUntilExpiry . ' minutes');
        }
    }

  
    protected function checkExpiringOrders($app, $appTimeZone): void {
        $endTime = now()->toDateTimeString();

        $query = Order::fromApp($app)
            ->notDeleted()
            ->whereNotFulfilled()
            ->whereNotNull('metadata')
            ->whereRaw("JSON_LENGTH(COALESCE(NULLIF(metadata, ''), '{}')) > 0")
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(COALESCE(metadata, '{}'), '$.data.end_at')) is not null")
            ->whereExists(function ($query) use ($appTimeZone) {
                $query->select('attributes.value')
                    ->from('items')
                    ->join('variants', 'items.variants_id', '=', 'variants.id')
                    ->join('attributes', function ($join) {
                        $join->on('variants.id', '=', 'attributes.attributes_id')
                            ->where('attributes.attributes_type', '=', 'Kanvas\\Souk\\Products\\Models\\Variants')
                            ->where('attributes.key', '=', 'timezone');
                    })
                    ->whereRaw('items.orders_id = orders.id')
                    ->whereRaw('CONVERT_TZ(JSON_UNQUOTE(JSON_EXTRACT(orders.metadata, "$.data.end_at")), COALESCE(attributes.value, ?), "UTC") BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 15 MINUTE)', [$appTimeZone]);
            })
            ->orderBy('id', 'desc')
            ->with('items');

        $ordersToFinish = $query->get();
        $this->info('Found ' . $ordersToFinish->count() . ' orders expiring in the next 15 minutes for app ' . $app->name . ' at ' . $endTime);

        foreach ($ordersToFinish as $order) {
            $this->notifyExpiringOrders($order, $appTimeZone);
        }
    }

    protected function checkApps($appsId): void
    {
        $app = Apps::getById($appsId);
        $this->overwriteAppService($app);

        $appTimeZone = $app->get('timezone');

        $this->checkExpiringOrders($app, $appTimeZone);
    }
}
