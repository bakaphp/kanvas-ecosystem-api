<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\Stripe\Services\StripePaymentService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Models\Order as ModelsOrder;
use Override;

class CreateOrderFromCartAction extends CreateBaseOrderAction
{
    #[Override]
    public function execute(): ModelsOrder
    {
        if (! $this->app->get(ConfigurationEnum::ALLOW_NO_PAYMENT_ORDER->value)) {
            $paymentIntentId = $this->request['input']['metadata']['paymentIntent']['id']
                ?? $this->request['input']['payment_intent_id']
                ?? $this->request['input']['metadata']['paymentIntent']['client_secret'] //remove later
                ?? null;

            /**
             * @todo remove later
             */
            if (Str::contains($paymentIntentId, '_secret_')) {
                $paymentIntentId = explode('_secret_', $paymentIntentId ?? '')[0]; // Gets "pi_3RAClYDdrFkcUBzl0vNHHnFD"
            }

            if (! $paymentIntentId) {
                throw new ValidationException('Payment Intent not provided');
            }
            $stripe = new StripePaymentService($this->app);
            $validation = $stripe->validatePaymentIntent($paymentIntentId);

            if (! $validation['valid']) {
                throw new ValidationException($validation['error'], $validation['status']);
            }
        }

        $order = parent::execute();

        return $order;
    }
}
