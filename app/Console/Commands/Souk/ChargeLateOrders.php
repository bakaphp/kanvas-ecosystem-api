<?php

declare(strict_types=1);

namespace App\Console\Commands\Souk;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Models\Settings;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;

class ChargeLateOrders extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-souk:charge-late-orders {app_id?}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Charge late fees for orders';

    public function handle(): void
    {
        $appsId = $this->argument('app_id');

        if ($appsId) {
            $this->checkApps($appsId);
        } else {
            $appsIds = Settings::where([
                'name' => ConfigurationEnum::GRACE_PERIOD_DAYS->value,
                'value' => '1',
            ])->select('apps_id')->get()->pluck('apps_id');
            $this->info('Checking ' . $appsIds->count() . ' apps');
            foreach ($appsIds as $appsId) {
                $this->checkApps($appsId);
                $this->info('Checked ' . $appsId);
            }
        }
    }

    protected function chargeLateOrders($app, $appTimeZone): void
    {
        $getLateOrders = new GetLateOrders($app);
        $getLateOrders->execute(
            now($appTimeZone)->toDateTimeString(),
            [
                $app->get(ConfigurationEnum::GRACE_PERIOD_DAYS->value),
            ]
        );
    }

    protected function checkApps($appsId): void
    {
        $app = Apps::getById($appsId);
        $this->overwriteAppService($app);

        $appTimeZone = $app->get('timezone');

        $this->chargeLateOrders($app, $appTimeZone);
    }
}


