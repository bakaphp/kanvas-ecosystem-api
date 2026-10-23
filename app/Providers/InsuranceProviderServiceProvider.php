<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Humano\Providers\HumanoProvider;
use Kanvas\Connectors\Humano\Services\HumanoService;
use Kanvas\Connectors\UniversalSeguros\Providers\UniversalSegurosProvider;
use Kanvas\Connectors\UniversalSeguros\Services\UniversalSegurosService;
use Kanvas\Insurance\Contracts\QuoteTaskRunner;
use Kanvas\Insurance\Services\OctaneQuoteTaskRunner;
use Override;

class InsuranceProviderServiceProvider extends ServiceProvider
{
    #[Override]
    public function register()
    {
        $this->app->bind(QuoteTaskRunner::class, OctaneQuoteTaskRunner::class);

        $this->app->bind('insurance_provider.' . HumanoProvider::NAME, function ($app, array $params) {
            $appModel = $params['app'] ?? $app->make(Apps::class);
            $company = $params['company'] ?? request()->user()->getCurrentCompany();

            return new HumanoProvider(
                app: $appModel,
                company: $company,
                service: new HumanoService($appModel, $company),
            );
        });

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
