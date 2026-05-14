<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\Services\EchoPayService;
use Kanvas\Connectors\PayWay\PayWayClient;
use Kanvas\Connectors\PayWay\Services\PayWayTokenizationService;
use Kanvas\Souk\Payments\Infrastructure\Processors\Azul\AzulProcessor;
use Kanvas\Souk\Payments\Infrastructure\Processors\CardNet\CardNetProcessor;
use Kanvas\Souk\Payments\Infrastructure\Processors\PayWay\PayWayProcessor;
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

        $this->app->bind('payment.payway', function ($app) {
            $appModel = $app->make(Apps::class);
            $company = request()->user()->getCurrentCompany();

            return new PayWayTokenizationService($appModel, $company);
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

        $this->app->bind('payment_processor.payway', function ($app, array $params) {
            $appModel = $params['app'] ?? $app->make(Apps::class);
            $company = $params['company'] ?? request()->user()->getCurrentCompany();

            return new PayWayProcessor(
                app: $appModel,
                company: $company,
                client: new PayWayClient($appModel, $company),
            );
        });
    }
}
