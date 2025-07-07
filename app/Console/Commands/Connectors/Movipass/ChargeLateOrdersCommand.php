<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Movipass;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Models\Settings;
use Kanvas\Connectors\Movipass\Actions\GenerateOrderLateFee;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;

class ChargeLateOrdersCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:movipass-charge-late-orders {app_id?}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Charge late fees for movipass orders';

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
        $getLateOrders = new GenerateOrderLateFee($app);
        $getLateOrders->execute(now($appTimeZone)->toDateTimeString());
    }

    protected function checkApps($appsId): void
    {
        $app = Apps::getById($appsId);
        $this->overwriteAppService($app);

        $appTimeZone = $app->get('timezone');

        $this->chargeLateOrders($app, $appTimeZone);
    }
}
