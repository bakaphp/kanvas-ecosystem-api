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

    protected function finishOrdersExpiredOrder(Order $order): void
    {
        // get the variant
        if (count($order->items) > 0) {
            $variant = $order->items->first(function ($item) {
                return $item->variant->product?->attributes
                ->contains(fn ($attribute) => in_array($attribute->slug, ['capacity', 'slots']) && ! empty($attribute->value));
            })->variant;
            $channel = $variant->variantChannels()->first();

            $variantWarehouse = $channel?->productVariantWarehouse()->first();
            // Mark order as completed
            $order->fulfill();
            $available = $variantWarehouse->quantity + 1;
            $variant->updateQuantityInWarehouse($variantWarehouse->warehouse, $available);
            $product = $variant->product;
            $capacity = $product->getAttributeByName('capacity')?->value;
            // @deprecated: remove this after new flow is implemented
            if ($capacity) {
                $product->addAttribute('capacity', [
                    'occupiedParkingSpaces' => $capacity['occupiedParkingSpaces'] - 1,
                    'availableParkingSpaces' => $available,
                    'totalParkingSpaces' => $capacity['totalParkingSpaces'] ?? $available,
                ]);
            }
            $this->info('Finished order ' . $order->id . ' for app ' . $order->app->name);
        } else {
            $this->info('No items found for order ' . $order->id . ' for app ' . $order->app->name . ' with ' . count($order->items) . ' items');
        }
    }

    protected function checkApps($appsId): void
    {
        $app = Apps::getById($appsId);
        $this->overwriteAppService($app);

        $endTime = now()->toDateTimeString();
        $query = Order::fromApp($app)
        ->notDeleted()
        ->whereNotFulfilled()
        ->whereNotNull('metadata')
        ->whereRaw("JSON_LENGTH(COALESCE(NULLIF(metadata, ''), '{}')) > 0")
        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(COALESCE(metadata, '{}'), '$.data.end_at')) is not null")
        ->orderBy('id', 'desc')
        ->with('items');


        $ordersInProgress = $query->get();
        $this->info('Found ' . $ordersInProgress->count() . ' orders in progress to finish for app ' . $app->name . ' at ' . $endTime);
        $appTimeZone = $app->get('timezone');

        foreach ($ordersInProgress as $order) {
            $orderEndTime = $order->metadata['data']['end_at'];

            $parkingTimeZone = $order->items->first(function ($item) {
                return $item->variant->first()?->attributes->first(fn ($attribute) => $attribute->key === 'timezone')?->value;
            })?->variant?->attributes?->first(fn ($attribute) => $attribute->key === 'timezone')?->value;

            $orderEndTime = Carbon::parse($orderEndTime, $parkingTimeZone ?? $appTimeZone);
            if ($orderEndTime->isPast()) {
                $this->finishOrdersExpiredOrder($order);
            }
        }
    }
}
