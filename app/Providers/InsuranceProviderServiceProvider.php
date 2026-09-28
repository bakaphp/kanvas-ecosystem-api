<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\UniversalSeguros\Providers\UniversalSegurosProvider;
use Kanvas\Connectors\UniversalSeguros\Services\UniversalSegurosService;
use Override;

/**
 * Adding an insurer is a binding here plus an adapter — no new GraphQL mutation.
 * Resolved through InsuranceProviderFactory as "insurance_provider.{name}".
 */
class InsuranceProviderServiceProvider extends ServiceProvider
{
    #[Override]
    public function register()
    {
        $this->app->bind('insurance_provider.' . UniversalSegurosProvider::NAME, function ($app, array $params) {
            $appModel = $params['app'] ?? $app->make(Apps::class);
            $company = $params['company'] ?? request()->user()->getCurrentCompany();

            return new UniversalSegurosProvider(
                app: $appModel,
                company: $company,
                service: new UniversalSegurosService($appModel, $company),
            );
        });
    }
}
