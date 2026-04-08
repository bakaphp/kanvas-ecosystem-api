<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\Services\EchoPayService;
use Kanvas\Souk\Payments\Infrastructure\Processors\Azul\AzulProcessor;
use Kanvas\Souk\Payments\Infrastructure\Processors\CardNet\CardNetProcessor;
use Override;

class PaymentProcessorServiceProvider extends ServiceProvider
{
    #[Override]
    public function register()
    {
        // TokenizationProcessorInterface bindings — resolved via payment.{processor} in PaymentMethodMutation
        $this->app->bind('payment.portal', function ($app) {
            $appModel = $app->make(Apps::class);
            $company = request()->user()->getCurrentCompany();

            return new EchoPayService($appModel, $company);
        });

        $this->app->bind('payment.azul', function ($app) {
            $appModel = $app->make(Apps::class);
            $company = request()->user()->getCurrentCompany();

            return new AzulProcessor($appModel, $company);
        });

        $this->app->bind('payment.cardnet', function ($app) {
            return new CardNetProcessor($app->make(Apps::class));
        });

        // PaymentProcessorInterface bindings — resolved via ProcessorFactory::make()
        $this->app->bind('payment_processor.azul', function ($app, array $params) {
            return new AzulProcessor(
                $params['app'] ?? $app->make(Apps::class),
                $params['company'] ?? request()->user()->getCurrentCompany(),
            );
        });

        $this->app->bind('payment_processor.cardnet', function ($app, array $params) {
            return new CardNetProcessor(
                $params['app'] ?? $app->make(Apps::class),
            );
        });
    }
}
