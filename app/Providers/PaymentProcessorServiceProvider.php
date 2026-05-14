<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\Services\EchoPayService;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Connectors\Stripe\Services\StripeTokenizationService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Payments\Infrastructure\Processors\Azul\AzulProcessor;
use Kanvas\Souk\Payments\Infrastructure\Processors\CardNet\CardNetProcessor;
use Override;
use Stripe\StripeClient;

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

        $this->app->bind('payment.stripe', function ($app) {
            $appModel = $app->make(Apps::class);
            $company = request()->user()->getCurrentCompany();
            $secret = (string) ($company->get(ConfigurationEnum::STRIPE_SECRET_KEY->value) ?? '');

            if (empty($secret)) {
                throw new ValidationException('Stripe is not configured for this company.');
            }

            return new StripeTokenizationService($appModel, $company, new StripeClient($secret));
        });

        // TODO Phase 5: bind payment_processor.stripe

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
