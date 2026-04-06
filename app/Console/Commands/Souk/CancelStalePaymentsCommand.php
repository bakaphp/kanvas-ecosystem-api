<?php

declare(strict_types=1);

namespace App\Console\Commands\Souk;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Models\Settings;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Payments\Actions\CancelStalePaymentsAction;

class CancelStalePaymentsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-souk:cancel-stale-payments {app_id?}';

    protected $description = 'Cancel payments stuck in transitional states beyond the configured TTL';

    public function handle(): void
    {
        $appsId = $this->argument('app_id');

        if ($appsId) {
            $this->processApp((int) $appsId);

            return;
        }

        $appsIds = Settings::where([
            'name' => ConfigurationEnum::CANCEL_STALE_PAYMENTS->value,
            'value' => '1',
        ])->select('apps_id')->get()->pluck('apps_id');

        $this->info("Checking {$appsIds->count()} apps for stale payments");

        foreach ($appsIds as $appId) {
            $this->processApp((int) $appId);
        }
    }

    protected function processApp(int $appsId): void
    {
        $app = Apps::getById($appsId);
        $this->overwriteAppService($app);

        $cancelled = new CancelStalePaymentsAction($app)->execute();

        $this->info("App {$app->name}: cancelled {$cancelled->count()} stale payments");
    }
}
