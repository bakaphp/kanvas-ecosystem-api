<?php

declare(strict_types=1);

namespace App\Console\Commands\Souk;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Actions\AlertUnverifiedDeliveryOrdersAction;
use Kanvas\Souk\Orders\Enums\DeliveryOrderTypeEnum;
use Kanvas\Souk\Orders\Models\OrderTypes;

class AlertUnverifiedDeliveryOrdersCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:souk-alert-unverified-delivery-orders';

    protected $description = 'Email the owner about delivery orders that stayed in verifying past the 24 hour limit';

    public function handle(): int
    {
        $appIds = OrderTypes::query()
            ->where('name', DeliveryOrderTypeEnum::DELIVERY->value)
            ->distinct()
            ->pluck('apps_id');

        foreach ($appIds as $appId) {
            $app = Apps::getById((int) $appId);
            $this->overwriteAppService($app);

            $alerted = new AlertUnverifiedDeliveryOrdersAction($app)->execute();

            $this->info("App {$app->getId()}: {$alerted} order(s) alerted");
        }

        return self::SUCCESS;
    }
}
