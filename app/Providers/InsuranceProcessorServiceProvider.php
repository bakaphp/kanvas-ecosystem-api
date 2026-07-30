<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Insurance\Infrastructure\Processors\UniversalSeguros\UniversalSegurosProcessor;
use Override;

class InsuranceProcessorServiceProvider extends ServiceProvider
{
    #[Override]
    public function register()
    {
        // InsuranceProcessorInterface bindings — resolved via InsuranceProcessorFactory::make().
        // Add one binding per insurance provider here instead of adding new
        // `{provider}CreateQuote` / `{provider}EmitPolicy` GraphQL mutations.
        $this->app->bind('insurance_processor.universal_seguros', function ($app, array $params) {
            return new UniversalSegurosProcessor(
                $params['app'] ?? $app->make(Apps::class),
                $params['company'] ?? request()->user()->getCurrentCompany(),
            );
        });
    }
}
