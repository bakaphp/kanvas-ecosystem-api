<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Connectors\Stripe\Services\StripeCustomerService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Stripe\StripeClient;
use Throwable;

class SetOrderPaymentIntentActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Order $order, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::STRIPE,
            integrationOperation: function ($order, $app, $company, $additionalParams) use ($params) {
                $this->validateStripe($app);

                $stripe = new StripeClient($order->app->get(ConfigurationEnum::STRIPE_SECRET_KEY->value));

                $clientSecret = $order->metadata['paymentIntent'] ?? null;

                if (empty($clientSecret)) {
                    throw new ValidationException('Payment intent not found in order metadata');
                }

                if (! is_array($clientSecret)) {
                    throw new ValidationException('Invalid payment intent format: expected array, got ' . gettype($clientSecret));
                }

                $paymentIntentId = explode('_secret_', $clientSecret['id'])[0]; // Gets "pi_3RAClYDdrFkcUBzl0vNHHnFD"

                $paymentIntent = $stripe->paymentIntents->retrieve($paymentIntentId);

                try {
                    $stripeService = new StripeCustomerService($order->app);
                    $stripe->paymentIntents->update($paymentIntentId, [
                        'customer' => $stripeService->getOrCreateCustomerByPerson($order->people)->id,
                    ]);
                } catch (Throwable $e) {
                    report($e);
                }

                $order->addPrivateMetadata('stripe_payment_intent', $paymentIntent->toArray());

                return [
                    'order' => $order->toArray(),
                    'update' => $updateResponse ?? null,
                ];
            },
            company: $order->company,
        );
    }

    /**
     * @todo move to middleware'
     */
    public function validateStripe(Apps $app)
    {
        if (empty($app->get(ConfigurationEnum::STRIPE_SECRET_KEY->value))) {
            throw new ValidationException('Stripe is not configured for this app');
        }
    }
}
