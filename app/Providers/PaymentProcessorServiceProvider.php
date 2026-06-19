<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\Services\EchoPayService;
use Kanvas\Connectors\PayWay\PayWayClient;
use Kanvas\Connectors\PayWay\Services\PayWayTokenizationService;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Connectors\Stripe\Services\StripeTokenizationService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Payments\Infrastructure\Processors\Azul\AzulProcessor;
use Kanvas\Souk\Payments\Infrastructure\Processors\CardNet\CardNetProcessor;
use Kanvas\Souk\Payments\Infrastructure\Processors\PayWay\PayWayProcessor;
use Kanvas\Souk\Payments\Infrastructure\Processors\Stripe\StripeProcessor;
use Override;
use Stripe\StripeClient;

class PaymentProcessorServiceProvider extends ServiceProvider
{
    #[Override]
    public function register()
    {
        // Both Stripe bindings read the Company-scoped secret — never the App or env — so
        // tenant isolation holds. Building the client is cheap; never cache it statically (Octane).
        $stripeClientFactory = function ($company): StripeClient {
            $secret = (string) ($company->get(ConfigurationEnum::STRIPE_SECRET_KEY->value) ?? '');

            if (empty($secret)) {
                throw new ValidationException('Stripe is not configured for this company.');
            }

            return new StripeClient($secret);
        };

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

        $this->app->bind('payment.stripe', function ($app) use ($stripeClientFactory) {
            $appModel = $app->make(Apps::class);
            $company = request()->user()->getCurrentCompany();

            return new StripeTokenizationService($appModel, $company, $stripeClientFactory($company));
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

        $this->app->bind('payment_processor.stripe', function ($app, array $params) use ($stripeClientFactory) {
            $appModel = $params['app'] ?? $app->make(Apps::class);
            $company = $params['company'] ?? request()->user()->getCurrentCompany();

            return new StripeProcessor($appModel, $company, $stripeClientFactory($company));
        });
    }
}
