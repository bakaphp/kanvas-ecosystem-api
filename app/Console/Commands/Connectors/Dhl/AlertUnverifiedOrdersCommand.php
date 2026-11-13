<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Dhl;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Dhl\Actions\AlertUnverifiedOrdersAction;
use Kanvas\Connectors\Dhl\Enums\OrderTypeEnum;
use Kanvas\Souk\Orders\Models\OrderTypes;

class AlertUnverifiedOrdersCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:dhl-alert-unverified-orders';

    protected $description = 'Email the owner about DHL orders that stayed in verifying past the 24 hour limit';

    public function handle(): int
    {
        $appIds = OrderTypes::query()
            ->where('name', OrderTypeEnum::DELIVERY->value)
            ->distinct()
            ->pluck('apps_id');

        foreach ($appIds as $appId) {
            $app = Apps::getById((int) $appId);
            $this->overwriteAppService($app);

            $alerted = new AlertUnverifiedOrdersAction($app)->execute();

            $this->info("App {$app->getId()}: {$alerted} order(s) alerted");
        }

        return self::SUCCESS;
    }
}
