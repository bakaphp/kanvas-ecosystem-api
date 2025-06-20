<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Movipass;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Models\Settings;
use Kanvas\Connectors\Movipass\Actions\CheckExpiringOrders;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum as EnumsConfigurationEnum;
use Kanvas\Souk\Enums\ConfigurationEnum;

class CheckExpiringOrdersCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:movipass-check-expiring-orders {app_id?}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Check expiring orders';

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

    protected function checkExpiringOrders($app, $appTimeZone): void
    {
        $checkExpiringOrders = new CheckExpiringOrders($app);
        $orders = $checkExpiringOrders->execute(
            now($appTimeZone)->toDateTimeString(),
            [
                EnumsConfigurationEnum::EXPIRING_RESERVATION_MAX->value,
                EnumsConfigurationEnum::EXPIRING_RESERVATION_MIN->value
            ]
        );

        $checkExpiringOrders->notify($orders);
    }

    protected function checkApps($appsId): void
    {
        $app = Apps::getById($appsId);
        $this->overwriteAppService($app);

        $appTimeZone = $app->get('timezone');

        $this->checkExpiringOrders($app, $appTimeZone);
    }
}
