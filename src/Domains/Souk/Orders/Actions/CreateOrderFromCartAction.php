<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Kanvas\Connectors\Stripe\Services\StripePaymentService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order as ModelsOrder;

class CreateOrderFromCartAction extends CreateBaseOrderAction
{
    public function execute(): ModelsOrder
    {
        $paymentIntentId = $this->request['input']['metadata']['paymentIntent']['client_secret']
            ?? $this->request['payment_intent_id']
            ?? null;

        if (! $paymentIntentId) {
            throw new ValidationException('Payment Intent not provided');
        }
        $stripe = new StripePaymentService($this->app);
        $stripeIntent = $this->request['input']['metadata']['paymentIntent']['client_secret'];
        $validation = $stripe->validatePaymentIntent($stripeIntent);

        if (! $validation['valid']) {
            throw new ValidationException($validation['error'], $validation['status']);
        }

        $order = parent::execute();

        return $order;
    }
}
