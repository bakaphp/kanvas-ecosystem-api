<?php

declare(strict_types=1);

namespace App\Console\Commands\Souk\Concerns;

use Baka\Traits\KanvasJobsTrait;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Models\Settings;
use Kanvas\Souk\Taxes\Enums\ConfigurationEnum;

trait IteratesTaxEnabledApps
{
    use KanvasJobsTrait;

    protected function eachTaxEnabledApp(callable $callback): void
    {
        $appsId = $this->argument('app_id');

        $appsIds = $appsId
            ? [(int) $appsId]
            : Settings::where([
                'name' => ConfigurationEnum::TAX_ENGINE_ENABLED->value,
                'value' => '1',
            ])->pluck('apps_id');

        foreach ($appsIds as $appId) {
            $app = Apps::getById((int) $appId);
            $this->overwriteAppService($app);

            $callback($app);
        }
    }
}
