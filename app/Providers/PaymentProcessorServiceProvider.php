<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\Services\EchoPayService;
use Kanvas\Souk\Payments\Infrastructure\Processors\Azul\AzulProcessor;
use Override;

class PaymentProcessorServiceProvider extends ServiceProvider
{
    #[Override]
    public function register()
    {
        // Legacy EchoPay binding — used by PaymentMethodMutation for card tokenization
        $this->app->bind('payment.portal', function ($app) {
            $user = request()->user();
            $company = $user->getCurrentCompany();
            $app = $app->make(Apps::class);

            return new EchoPayService($app, $company);
        });

        // PaymentProcessorInterface bindings — resolved via ProcessorFactory::make()
        $this->app->bind('payment_processor.azul', function ($app, array $params) {
            return new AzulProcessor(
                $params['app'] ?? $app->make(Apps::class),
                $params['company'] ?? request()->user()->getCurrentCompany(),
            );
        });
    }
}
